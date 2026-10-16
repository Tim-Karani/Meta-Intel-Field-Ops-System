import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';

import '../core/api_client.dart';
import '../core/api_exception.dart';
import '../core/util.dart';
import '../models/outbox_op.dart';
import 'repository.dart';
import 'session.dart';

class SyncState {
  const SyncState({
    this.running = false,
    this.online = true,
    this.lastSyncAt,
    this.lastError,
    this.pending = 0,
    this.stuck = 0,
    this.sessionExpired = false,
  });

  final bool running;
  final bool online;
  final String? lastSyncAt;
  final String? lastError;
  final int pending;
  final int stuck;
  final bool sessionExpired;

  SyncState copy({bool? running, bool? online, String? lastSyncAt, String? lastError, bool clearError = false, int? pending, int? stuck, bool? sessionExpired}) =>
      SyncState(
        running: running ?? this.running,
        online: online ?? this.online,
        lastSyncAt: lastSyncAt ?? this.lastSyncAt,
        lastError: clearError ? null : (lastError ?? this.lastError),
        pending: pending ?? this.pending,
        stuck: stuck ?? this.stuck,
        sessionExpired: sessionExpired ?? this.sessionExpired,
      );
}

const _visitOrder = ['scheduled', 'started', 'completed', 'submitted', 'under_review', 'approved', 'rejected'];

/// Pushes the outbox to the server (oldest first, one at a time, with retry/backoff) and then downloads changes.
///
/// Rules (from the offline design):
///  * network error  -> stop, keep everything, try again when online (not counted as a failed attempt)
///  * 401            -> stop, ask for sign-in; nothing is deleted
///  * 5xx / 429      -> retry later with backoff (bounded)
///  * 400/422/403/404 -> FAILED: waits for the user (never retried forever, never silently dropped)
///  * 409            -> CONFLICT, unless the server already holds the result of this very step (a lost response)
class SyncService {
  SyncService(this._repo, this._session) {
    _state = const SyncState();
  }

  final Repository _repo;
  final Session _session;
  final _controller = StreamController<SyncState>.broadcast();
  late SyncState _state;
  bool _running = false;
  Timer? _debounce;
  Timer? _periodic;
  StreamSubscription<List<ConnectivityResult>>? _connSub;
  StreamSubscription<int>? _changeSub;
  DateTime _lastPeriodicPull = DateTime.fromMillisecondsSinceEpoch(0);

  SyncState get state => _state;
  Stream<SyncState> get stream => _controller.stream;

  ApiClient get _api => _session.api;

  void _emit(SyncState s) {
    _state = s;
    if (!_controller.isClosed) _controller.add(s);
  }

  Future<void> start() async {
    await _repo.recoverInterrupted();
    await _refreshCounts();
    final r = await Connectivity().checkConnectivity();
    _emit(_state.copy(online: _isOnline(r)));

    _connSub = Connectivity().onConnectivityChanged.listen((r) {
      final online = _isOnline(r);
      final wasOnline = _state.online;
      _emit(_state.copy(online: online));
      if (online && !wasOnline) requestSync(delay: const Duration(seconds: 2));
    });
    // New local work is sent soon after it is made.
    _changeSub = _repo.changes.listen((_) {
      _refreshCounts();
      if (!_running) requestSync(delay: const Duration(seconds: 3), onlyIfPending: true);
    });
    _periodic = Timer.periodic(const Duration(seconds: 60), (_) {
      final due = DateTime.now().difference(_lastPeriodicPull) > const Duration(minutes: 5);
      requestSync(onlyIfPending: !due);
    });
    requestSync(delay: const Duration(seconds: 1));
  }

  void dispose() {
    _debounce?.cancel();
    _periodic?.cancel();
    _connSub?.cancel();
    _changeSub?.cancel();
    _controller.close();
  }

  static bool _isOnline(List<ConnectivityResult> r) => r.isNotEmpty && !r.contains(ConnectivityResult.none);

  void requestSync({Duration delay = Duration.zero, bool onlyIfPending = false}) {
    _debounce?.cancel();
    _debounce = Timer(delay, () async {
      if (onlyIfPending && _state.pending == 0) return;
      await run();
    });
  }

  Future<void> _refreshCounts() async {
    final c = await _repo.outboxCounts();
    _emit(_state.copy(pending: (c['PENDING'] ?? 0) + (c['SYNCING'] ?? 0), stuck: (c['FAILED'] ?? 0) + (c['CONFLICT'] ?? 0)));
  }

  /// Runs one full cycle. [manual] also ignores retry backoff, so "Sync now" really tries everything.
  Future<void> run({bool manual = false, bool fullDownload = false}) async {
    if (_running || !_session.signedIn) return;
    final conn = await Connectivity().checkConnectivity();
    if (!_isOnline(conn)) {
      _emit(_state.copy(online: false));
      return;
    }
    _running = true;
    _emit(_state.copy(running: true, online: true, clearError: true, sessionExpired: _session.expired));
    try {
      if (manual) await _repo.clearBackoff();
      await _session.refreshIfNeeded();
      final pushedAll = await _push();
      if (pushedAll) {
        await _pull(full: fullDownload);
        _lastPeriodicPull = DateTime.now();
        _emit(_state.copy(lastSyncAt: nowIso()));
      }
      await _repo.purgeOldSynced();
    } on ApiException catch (e) {
      _emit(_state.copy(lastError: e.kind == ApiErrorKind.network ? 'Offline - will retry.' : e.display));
    } catch (e) {
      _emit(_state.copy(lastError: 'Sync problem: $e'));
    } finally {
      _running = false;
      await _refreshCounts();
      _emit(_state.copy(running: false, sessionExpired: _session.expired));
    }
  }

  /// Returns false when the push had to stop early (offline / signed out / rate limited).
  Future<bool> _push() async {
    while (true) {
      final op = await _repo.nextRunnable(DateTime.now());
      if (op == null) return true;
      await _repo.markSyncing(op.id);
      try {
        await _execute(op);
        await _repo.markSynced(op.id);
      } on ApiException catch (e) {
        switch (e.kind) {
          case ApiErrorKind.network:
            await _repo.releaseToPending(op.id, error: e.message);
            _emit(_state.copy(online: false, lastError: 'Offline - will retry.'));
            return false;
          case ApiErrorKind.unauthorized:
            await _repo.releaseToPending(op.id);
            _emit(_state.copy(sessionExpired: true, lastError: 'Session expired. Sign in again to sync.'));
            return false;
          case ApiErrorKind.rateLimited:
            await _repo.scheduleRetry(op, error: e.display, http: e.status, serverHint: e.retryAfter);
            return false;
          case ApiErrorKind.server:
            await _repo.scheduleRetry(op, error: e.display, http: e.status);
            break;
          case ApiErrorKind.conflict:
            if (await _alreadyApplied(op)) {
              await _repo.markSynced(op.id);
            } else {
              await _repo.markFailed(op.id, error: e.display, http: e.status, conflict: true);
            }
            break;
          case ApiErrorKind.notFound:
            if (op.type == 'photo_delete' || op.type == 'notification_read') {
              await _repo.markSynced(op.id); // already gone: that is the goal
            } else {
              await _repo.markFailed(op.id, error: 'No longer available on the server. ${e.display}', http: e.status);
            }
            break;
          default:
            await _repo.markFailed(op.id, error: e.display, http: e.status);
        }
      } on FileSystemException catch (e) {
        await _repo.markFailed(op.id, error: 'The saved photo file is missing (${e.message}).');
      } on StateError catch (e) {
        await _repo.markFailed(op.id, error: e.message);
      } catch (e) {
        await _repo.markFailed(op.id, error: 'Unexpected error: $e');
      }
    }
  }

  /// A 409 on a state change usually means "already done" (the earlier request succeeded but its reply was lost).
  Future<bool> _alreadyApplied(OutboxOp op) async {
    const target = {'visit_checkin': 'started', 'visit_checkout': 'completed', 'visit_submit': 'submitted'};
    final want = target[op.type];
    if (want == null || op.entityId == null) return false;
    try {
      final sid = await _repo.serverIdOf(op.entityId!);
      if (sid == null) return false;
      final v = await _api.get('visits/$sid') as Map<String, dynamic>;
      final have = _visitOrder.indexOf(v.str('status') ?? '');
      return have >= _visitOrder.indexOf(want) && have >= 0;
    } catch (_) {
      return false;
    }
  }

  static String _ext(String path) {
    final dot = path.lastIndexOf('.');
    final e = dot < 0 ? '' : path.substring(dot).toLowerCase();
    return const ['.jpg', '.jpeg', '.png', '.webp'].contains(e) ? e : '.jpg';
  }

  Future<String> _sid(OutboxOp op) async {
    final sid = op.entityId == null ? null : await _repo.serverIdOf(op.entityId!);
    if (sid == null) throw StateError('The visit has not reached the server yet.');
    return sid;
  }

  Future<void> _execute(OutboxOp op) async {
    final p = op.payload;
    switch (op.type) {
      case 'outlet_create':
        await _api.post('locations/field', body: p);
        return;
      case 'assignment_accept':
        await _api.post('assignments/${p['assignment_id']}/accept');
        return;
      case 'attendance_clock_in':
        final attRes = await _api.post('attendance/clock-in', body: p);
        if (attRes is Map<String, dynamic> && op.entityId != null) {
          await _repo.applyAttendanceSynced(op.entityId!.replaceFirst('attendance:', ''), attRes);
        }
        return;
      case 'attendance_clock_out':
        await _api.post('attendance/clock-out', body: p);
        return;
      case 'visit_create':
        final created = await _api.post('visits', body: p);
        if (created is Map<String, dynamic>) await _repo.applyVisitCreated(op.entityId!, created);
        return;
      case 'visit_checkin':
        await _api.post('visits/${await _sid(op)}/check-in', body: p);
        return;
      case 'visit_checkout':
        await _api.post('visits/${await _sid(op)}/check-out', body: p);
        return;
      case 'visit_responses':
        final v = await _repo.visit(op.entityId!);
        if (v == null) return;
        final answers = Map<String, dynamic>.from(v.answers)..removeWhere((k, val) => val == null || val == '' || (val is List && val.isEmpty));
        if (answers.isEmpty) return;
        await _api.put('visits/${await _sid(op)}/responses', body: {'answers': answers});
        return;
      case 'visit_sale':
      case 'visit_osa':
      case 'visit_merch':
        final item = await _repo.itemPayload('${p['item_id']}');
        if (item == null) return;
        final sid = await _sid(op);
        if (op.type == 'visit_sale') {
          await _api.post('visits/$sid/sales', body: item);
        } else if (op.type == 'visit_osa') {
          await _api.put('visits/$sid/osa', body: item);
        } else {
          await _api.post('visits/$sid/merchandising', body: item);
        }
        return;
      case 'photo_upload':
        final photo = await _repo.photoRow('${p['photo_id']}');
        if (photo == null || (photo['uploaded'] as int? ?? 0) == 1) return;
        final path = photo['path'] as String?;
        if (path == null || !await File(path).exists()) throw const FileSystemException('file not found');
        final uploaded = await _api.upload('visits/${await _sid(op)}/photos', filePath: path, fileName: '${photo['id']}${_ext(path)}', fields: {
          'client_uuid': photo['id'],
          'question_id': photo['question_id'],
          'kind': photo['kind'],
          'captured_at': photo['captured_at'],
          'latitude': photo['latitude'],
          'longitude': photo['longitude'],
        });
        await _repo.applyPhotoUploaded('${photo['id']}', uploaded is Map<String, dynamic> ? uploaded.str('id') : null);
        return;
      case 'photo_delete':
        await _api.delete('photos/${p['server_id']}');
        return;
      case 'visit_submit':
        await _api.post('visits/${await _sid(op)}/submit');
        return;
      case 'issue_create':
        final issue = await _repo.issueRow('${p['issue_id']}');
        if (issue == null) return;
        final visitLocal = issue['visit_local_id'] as String?;
        final body = <String, dynamic>{
          'client_uuid': issue['id'],
          'title': issue['title'],
          if (issue['description'] != null) 'description': issue['description'],
          if (issue['category'] != null) 'category': issue['category'],
          'priority': issue['priority'],
        };
        if (visitLocal != null) {
          final sid = await _repo.serverIdOf(visitLocal);
          if (sid == null) throw StateError('The visit this issue belongs to has not reached the server yet.');
          body['visit_id'] = sid;
        } else {
          if (issue['campaign_id'] != null) body['campaign_id'] = issue['campaign_id'];
          if (issue['location_id'] != null) body['location_id'] = issue['location_id'];
        }
        final sent = await _api.post('issues', body: body);
        await _repo.applyIssueSynced('${issue['id']}', sent is Map<String, dynamic> ? sent.str('id') : null);
        return;
      case 'notification_read':
        await _api.post('notifications/${p['id']}/read');
        return;
      default:
        throw StateError('Unknown queued operation "${op.type}". Update the app.');
    }
  }

  Future<void> _pull({bool full = false}) async {
    final last = full ? null : await _repo.meta('last_sync');
    Map<String, dynamic> data;
    try {
      final r = last == null ? await _api.get('sync/bootstrap') : await _api.get('sync/changes', query: {'since': last});
      data = (r as Map).cast<String, dynamic>();
    } on ApiException catch (e) {
      if (last != null && e.kind == ApiErrorKind.validation) {
        data = ((await _api.get('sync/bootstrap')) as Map).cast<String, dynamic>();
      } else {
        rethrow;
      }
    }
    await _repo.applyDownload(data);
    final t = data.str('server_time');
    if (t != null) await _repo.setMeta('last_sync', t);
    await _repo.setMeta('last_full_sync', nowIso());
  }
}
