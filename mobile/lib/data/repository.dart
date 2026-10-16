import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:sqflite/sqflite.dart';

import '../core/config.dart';
import '../core/db.dart';
import '../core/location_service.dart';
import '../core/util.dart';
import '../models/form_def.dart';
import '../models/local_visit.dart';
import '../models/outbox_op.dart';

/// Everything the UI reads and writes. All reads come from SQLite. Every user action is written locally FIRST and
/// queued in the outbox in the same transaction, so a crash or a dead connection can never lose field work.
class Repository {
  Repository(this._adb, {required this.deviceId});

  final AppDatabase _adb;
  final String deviceId;
  Database get _db => _adb.db;

  final StreamController<int> _rev = StreamController<int>.broadcast();
  int _revision = 0;

  /// Emits whenever local data changed, so screens can refresh.
  Stream<int> get changes => _rev.stream;
  int get revision => _revision;

  void touch() {
    _revision++;
    if (!_rev.isClosed) _rev.add(_revision);
  }

  // ---------------------------------------------------------------- meta

  Future<String?> meta(String key) async {
    final r = await _db.query('meta', where: 'k = ?', whereArgs: [key], limit: 1);
    return r.isEmpty ? null : r.first['v'] as String?;
  }

  Future<void> setMeta(String key, String? value) async {
    if (value == null) {
      await _db.delete('meta', where: 'k = ?', whereArgs: [key]);
    } else {
      await _db.insert('meta', {'k': key, 'v': value}, conflictAlgorithm: ConflictAlgorithm.replace);
    }
  }

  // ---------------------------------------------------------------- reads

  Future<List<AssignmentView>> assignments({String? from, String? to}) async {
    final where = <String>[];
    final args = <Object?>[];
    if (from != null) {
      where.add('scheduled_date >= ?');
      args.add(from);
    }
    if (to != null) {
      where.add('scheduled_date <= ?');
      args.add(to);
    }
    final rows = await _db.query('assignments',
        where: where.isEmpty ? null : where.join(' AND '), whereArgs: args, orderBy: 'scheduled_date, json');
    final list = rows.map(_assignmentFromRow).toList();
    list.sort((a, b) {
      final d = a.scheduledDate.compareTo(b.scheduledDate);
      return d != 0 ? d : (a.startTime ?? '99').compareTo(b.startTime ?? '99');
    });
    return list;
  }

  Future<AssignmentView?> assignment(String id) async {
    final r = await _db.query('assignments', where: 'id = ?', whereArgs: [id], limit: 1);
    return r.isEmpty ? null : _assignmentFromRow(r.first);
  }

  AssignmentView _assignmentFromRow(Map<String, Object?> r) {
    final raw = (jsonDecode(r['json'] as String) as Map).cast<String, dynamic>();
    raw['status'] = r['status'];
    return AssignmentView(raw, removed: (r['removed'] as int? ?? 0) == 1);
  }

  Future<List<LocalVisit>> visits({int limit = 300}) async {
    final rows = await _db.query('visits', orderBy: 'visit_date DESC, updated_at DESC', limit: limit);
    return rows.map(LocalVisit.fromRow).toList();
  }

  Future<LocalVisit?> visit(String localId) async {
    final r = await _db.query('visits', where: 'local_id = ?', whereArgs: [localId], limit: 1);
    return r.isEmpty ? null : LocalVisit.fromRow(r.first);
  }

  Future<LocalVisit?> visitForAssignment(String assignmentId) async {
    final r = await _db.query('visits', where: 'assignment_id = ?', whereArgs: [assignmentId], orderBy: 'created_at DESC');
    if (r.isEmpty) return null;
    final all = r.map(LocalVisit.fromRow).toList();
    return all.firstWhere((v) => !v.isRejected, orElse: () => all.first);
  }

  Future<FormDef?> form(String? id) async {
    if (id == null) return null;
    final r = await _db.query('forms', where: 'id = ?', whereArgs: [id], limit: 1);
    return r.isEmpty ? null : FormDef.decode(r.first['json'] as String);
  }

  /// Products of one campaign (the server tells us which campaigns each product belongs to).
  Future<List<Map<String, dynamic>>> products({String? campaignId}) async {
    final rows = await _db.query('products', orderBy: 'name COLLATE NOCASE');
    final all = rows.map((r) => (jsonDecode(r['json'] as String) as Map).cast<String, dynamic>()).toList();
    if (campaignId == null) return all;
    return all.where((p) => p.list('campaign_ids').contains(campaignId)).toList();
  }

  Future<List<Map<String, dynamic>>> items(String visitLocalId, String kind) async {
    final rows = await _db.query('visit_items',
        where: 'visit_local_id = ? AND kind = ?', whereArgs: [visitLocalId, kind], orderBy: 'created_at');
    return rows.map((r) {
      final m = (jsonDecode(r['payload'] as String) as Map).cast<String, dynamic>();
      m['_id'] = r['id'];
      m['_created_at'] = r['created_at'];
      return m;
    }).toList();
  }

  Future<List<Map<String, Object?>>> photos(String visitLocalId) =>
      _db.query('photos', where: 'visit_local_id = ?', whereArgs: [visitLocalId], orderBy: 'captured_at');

  Future<List<Map<String, Object?>>> issues() => _db.query('issues', orderBy: 'created_at DESC', limit: 200);

  Future<Map<String, Object?>?> attendance(String day) async {
    final r = await _db.query('attendance', where: 'day = ?', whereArgs: [day], limit: 1);
    return r.isEmpty ? null : r.first;
  }

  Future<List<Map<String, dynamic>>> notifications() async {
    final rows = await _db.query('notifications', orderBy: 'created_at DESC', limit: 100);
    return rows.map((r) {
      final m = (jsonDecode(r['json'] as String) as Map).cast<String, dynamic>();
      m['_read'] = (r['read'] as int? ?? 0) == 1;
      return m;
    }).toList();
  }

  Future<int> unreadNotifications() async =>
      Sqflite.firstIntValue(await _db.rawQuery('SELECT COUNT(*) FROM notifications WHERE read = 0')) ?? 0;

  // ---------------------------------------------------------------- outbox reads

  Future<List<OutboxOp>> outbox({bool includeSynced = false, int limit = 300}) async {
    final rows = await _db.query('outbox',
        where: includeSynced ? null : "status != 'SYNCED'", orderBy: 'seq', limit: limit);
    return rows.map(OutboxOp.fromRow).toList();
  }

  Future<Map<String, int>> outboxCounts() async {
    final rows = await _db.rawQuery('SELECT status, COUNT(*) AS n FROM outbox GROUP BY status');
    return {for (final r in rows) (r['status'] as String): (r['n'] as int)};
  }

  /// Ids (assignment ids and visit local ids) that still have unsynced operations. Server data must not overwrite these.
  Future<Set<String>> dirtyEntities() async {
    final rows = await _db.rawQuery("SELECT DISTINCT entity_id FROM outbox WHERE status != 'SYNCED' AND entity_id IS NOT NULL");
    return rows.map((r) => r['entity_id'] as String).toSet();
  }

  Future<int> unsyncedFor(String entityId) async =>
      Sqflite.firstIntValue(await _db.rawQuery("SELECT COUNT(*) FROM outbox WHERE entity_id = ? AND status != 'SYNCED'", [entityId])) ?? 0;

  // ---------------------------------------------------------------- outbox writes

  Future<void> _enqueue(DatabaseExecutor tx, String type, String? entityId, Map<String, dynamic> payload) async {
    final now = nowIso();
    await tx.insert('outbox', {
      'id': newId(),
      'type': type,
      'entity_id': entityId,
      'payload': jsonEncode(payload),
      'status': 'PENDING',
      'retry_count': 0,
      'created_at': now,
      'updated_at': now,
    });
  }

  /// The next operation ready to send: the oldest PENDING one that is due and whose entity has no earlier operation
  /// stuck in FAILED/CONFLICT (later steps of a broken visit wait, but other visits keep syncing).
  Future<OutboxOp?> nextRunnable(DateTime now) async {
    final stuck = await _db.rawQuery("SELECT DISTINCT entity_id FROM outbox WHERE status IN ('FAILED','CONFLICT') AND entity_id IS NOT NULL");
    final blocked = stuck.map((r) => r['entity_id'] as String).toSet();
    final rows = await _db.query('outbox', where: "status = 'PENDING'", orderBy: 'seq', limit: 200);
    for (final r in rows) {
      final op = OutboxOp.fromRow(r);
      if (op.entityId != null && blocked.contains(op.entityId)) continue;
      final due = op.nextAttemptAt == null ? null : DateTime.tryParse(op.nextAttemptAt!);
      if (due != null && due.isAfter(now)) {
        // This entity is backing off: later operations for it must wait too.
        if (op.entityId != null) blocked.add(op.entityId!);
        continue;
      }
      return op;
    }
    return null;
  }

  Future<void> markSyncing(String opId) async {
    await _db.update('outbox', {'status': 'SYNCING', 'last_attempt_at': nowIso(), 'updated_at': nowIso()}, where: 'id = ?', whereArgs: [opId]);
    touch();
  }

  Future<void> markSynced(String opId) async {
    await _db.update('outbox', {'status': 'SYNCED', 'error': null, 'http_status': null, 'updated_at': nowIso()}, where: 'id = ?', whereArgs: [opId]);
    touch();
  }

  Future<void> markFailed(String opId, {required String error, int? http, bool conflict = false}) async {
    await _db.update(
        'outbox', {'status': conflict ? 'CONFLICT' : 'FAILED', 'error': error, 'http_status': http, 'updated_at': nowIso()},
        where: 'id = ?', whereArgs: [opId]);
    touch();
  }

  Future<void> scheduleRetry(OutboxOp op, {required String error, int? http, Duration? serverHint}) async {
    final next = op.retryCount + 1;
    if (next >= AppConfig.maxRetries) {
      await markFailed(op.id, error: '$error (gave up after $next attempts - tap Retry)', http: http);
      return;
    }
    final at = DateTime.now().add(backoffFor(op.retryCount, serverHint: serverHint)).toUtc().toIso8601String();
    await _db.update('outbox',
        {'status': 'PENDING', 'retry_count': next, 'next_attempt_at': at, 'error': error, 'http_status': http, 'updated_at': nowIso()},
        where: 'id = ?', whereArgs: [op.id]);
    touch();
  }

  /// Back to the queue after a connectivity failure, without counting it as an attempt.
  Future<void> releaseToPending(String opId, {String? error}) async {
    await _db.update('outbox', {'status': 'PENDING', 'error': error, 'updated_at': nowIso()}, where: 'id = ?', whereArgs: [opId]);
    touch();
  }

  /// Anything left SYNCING (app killed mid-request) goes back to PENDING; the server de-duplicates by client_uuid.
  Future<void> recoverInterrupted() async {
    await _db.update('outbox', {'status': 'PENDING'}, where: "status = 'SYNCING'");
  }

  Future<void> retryOp(String opId) async {
    await _db.update('outbox', {'status': 'PENDING', 'retry_count': 0, 'next_attempt_at': null, 'error': null, 'http_status': null, 'updated_at': nowIso()},
        where: 'id = ?', whereArgs: [opId]);
    touch();
  }

  Future<void> retryAllStuck() async {
    await _db.update('outbox', {'status': 'PENDING', 'retry_count': 0, 'next_attempt_at': null, 'error': null, 'http_status': null, 'updated_at': nowIso()},
        where: "status IN ('FAILED','CONFLICT')");
    touch();
  }

  /// Makes every backed-off operation due now (manual "Sync now").
  Future<void> clearBackoff() async {
    await _db.update('outbox', {'next_attempt_at': null}, where: "status = 'PENDING'");
  }

  /// Deliberately gives up on one operation. The local data stays on the device; only the queued send is removed.
  Future<void> discardOp(String opId) async {
    await _db.delete('outbox', where: 'id = ?', whereArgs: [opId]);
    touch();
  }

  /// Removes every local record (a different account signing in on a clean phone).
  Future<void> wipeAll() async {
    await _adb.wipe();
    touch();
  }

  Future<void> purgeOldSynced() async {
    final cutoff = DateTime.now().subtract(const Duration(days: 3)).toUtc().toIso8601String();
    await _db.delete('outbox', where: "status = 'SYNCED' AND updated_at < ?", whereArgs: [cutoff]);
  }

  // ---------------------------------------------------------------- field actions

  /// Campaigns the agent can add an outlet to: one entry per campaign from the assignments on the phone, carrying a
  /// recent sibling assignment (its form / activity / campaign details are copied onto the new outlet's assignment).
  Future<List<AssignmentView>> outletCampaigns() async {
    final all = await assignments();
    final byCampaign = <String, AssignmentView>{};
    for (final a in all.reversed) {
      final cid = a.campaignId;
      if (cid == null || a.isCancelled || a.removed) continue;
      byCampaign.putIfAbsent(cid, () => a);
    }
    return byCampaign.values.toList()..sort((a, b) => a.campaignName.compareTo(b.campaignName));
  }

  /// Adds an outlet while standing at it - works offline. Creates, in one transaction: a provisional assignment for
  /// today, the (not yet started) visit, and the outbox steps "add outlet" then "start visit". Ids are generated here,
  /// so the server can later accept them as they are. Returns the local visit id.
  Future<String> createOutlet({
    required AssignmentView sibling,
    required String name,
    String type = 'retail_store',
    String? town,
    String? address,
    String? contactName,
    String? contactPhone,
    String? notes,
  }) async {
    final clean = name.trim();
    final today = todayStr();
    final dupe = (await assignments(from: today, to: today)).any((a) =>
        a.campaignId == sibling.campaignId && a.locationName.trim().toLowerCase() == clean.toLowerCase() && (town == null || a.town == null || a.town!.toLowerCase() == town.trim().toLowerCase()));
    if (dupe) throw StateError('"$clean" is already on your list for today. Open it from My work instead.');

    final locationId = newId();
    final assignmentId = newId();
    final visitId = newId();
    final now = nowIso();
    final locationJson = <String, dynamic>{
      'id': locationId,
      'name': clean,
      'type': type,
      'town': _blankToNull(town),
      'address': _blankToNull(address),
      'contact_name': _blankToNull(contactName),
      'contact_phone': _blankToNull(contactPhone),
      'latitude': null,
      'longitude': null,
      'geofence_radius_m': 100,
      'needs_review': true,
      'created_via': 'field',
    };
    final assignmentJson = <String, dynamic>{
      'id': assignmentId,
      'campaign_id': sibling.campaignId,
      'campaign': sibling.raw['campaign'],
      'activity_id': sibling.raw['activity_id'],
      'activity': sibling.raw['activity'],
      'location_id': locationId,
      'location': locationJson,
      'form_id': sibling.formId,
      'supervisor': sibling.raw['supervisor'],
      'scheduled_date': today,
      'status': 'accepted',
      'instructions': 'Added in the field by you. The outlet position is saved when you check in.',
    };
    final apiPayload = <String, dynamic>{
      'location_id': locationId,
      'assignment_id': assignmentId,
      'campaign_id': sibling.campaignId,
      if (sibling.raw['activity_id'] != null) 'activity_id': sibling.raw['activity_id'],
      'name': clean,
      'type': type,
      if (_blankToNull(town) != null) 'town': town!.trim(),
      if (_blankToNull(address) != null) 'address': address!.trim(),
      if (_blankToNull(contactName) != null) 'contact_name': contactName!.trim(),
      if (_blankToNull(contactPhone) != null) 'contact_phone': contactPhone!.trim(),
      if (_blankToNull(notes) != null) 'notes': notes!.trim(),
    };

    await _db.transaction((tx) async {
      await tx.insert('assignments', {'id': assignmentId, 'scheduled_date': today, 'status': 'accepted', 'removed': 0, 'json': jsonEncode(assignmentJson)});
      await tx.insert('visits', {
        'local_id': visitId,
        'assignment_id': assignmentId,
        'form_id': sibling.formId,
        'campaign_id': sibling.campaignId,
        'location_id': locationId,
        'status': 'scheduled',
        'visit_date': today,
        'answers': '{}',
        'created_at': now,
        'updated_at': now,
      });
      // Same entity as the visit: if adding the outlet gets stuck, the visit's later steps wait behind it.
      await _enqueue(tx, 'outlet_create', visitId, apiPayload);
      await _enqueue(tx, 'visit_create', visitId, {'assignment_id': assignmentId, 'client_uuid': visitId, 'device_id': deviceId});
    });
    touch();
    return visitId;
  }

  String? _blankToNull(String? s) => (s == null || s.trim().isEmpty) ? null : s.trim();

  Future<void> acceptAssignment(String assignmentId) async {
    await _db.transaction((tx) async {
      await tx.update('assignments', {'status': 'accepted'}, where: "id = ? AND status = 'pending'", whereArgs: [assignmentId]);
      await _enqueue(tx, 'assignment_accept', assignmentId, {'assignment_id': assignmentId});
    });
    touch();
  }

  /// Creates the local visit for an assignment (or returns the existing one).
  Future<LocalVisit> startVisit(AssignmentView a) async {
    final existing = await visitForAssignment(a.id);
    if (existing != null && !existing.isRejected) return existing;
    final id = newId();
    final now = nowIso();
    await _db.transaction((tx) async {
      await tx.insert('visits', {
        'local_id': id,
        'assignment_id': a.id,
        'form_id': a.formId,
        'campaign_id': a.campaignId,
        'location_id': a.locationId,
        'status': 'scheduled',
        'visit_date': a.scheduledDate,
        'answers': '{}',
        'created_at': now,
        'updated_at': now,
      });
      await _enqueue(tx, 'visit_create', id, {'assignment_id': a.id, 'client_uuid': id, 'device_id': deviceId});
    });
    touch();
    return (await visit(id))!;
  }

  Future<void> checkIn(LocalVisit v, GpsReading gps) async {
    if (v.status != 'scheduled') return;
    final payload = gps.toApi(deviceId: deviceId);
    await _db.transaction((tx) async {
      await tx.update('visits', {'status': 'started', 'checkin': jsonEncode(payload), 'updated_at': nowIso()}, where: 'local_id = ?', whereArgs: [v.localId]);
      await tx.update('assignments', {'status': 'in_progress'}, where: "id = ? AND status IN ('pending','accepted','overdue')", whereArgs: [v.assignmentId]);
      await _enqueue(tx, 'visit_checkin', v.localId, payload);
    });
    touch();
  }

  Future<void> checkOut(LocalVisit v, GpsReading gps) async {
    if (v.status != 'started') return;
    final payload = gps.toApi(deviceId: deviceId);
    await _db.transaction((tx) async {
      await tx.update('visits', {'status': 'completed', 'checkout': jsonEncode(payload), 'updated_at': nowIso()}, where: 'local_id = ?', whereArgs: [v.localId]);
      await _enqueue(tx, 'visit_checkout', v.localId, payload);
    });
    touch();
  }

  /// Saves the answers locally and makes sure exactly one pending "answers" send exists. The send reads the newest
  /// answers when it runs, so rapid edits never queue a pile of stale uploads.
  Future<void> saveAnswers(String visitLocalId, Map<String, dynamic> answers, {String? notes}) async {
    await _db.transaction((tx) async {
      await tx.update('visits', {'answers': jsonEncode(answers), if (notes != null) 'notes': notes, 'updated_at': nowIso()},
          where: 'local_id = ?', whereArgs: [visitLocalId]);
      final pending = await tx.query('outbox',
          columns: ['seq'], where: "type = 'visit_responses' AND entity_id = ? AND status = 'PENDING'", whereArgs: [visitLocalId], limit: 1);
      if (pending.isEmpty) {
        await _enqueue(tx, 'visit_responses', visitLocalId, {});
      } else {
        await tx.update('outbox', {'updated_at': nowIso()}, where: 'seq = ?', whereArgs: [pending.first['seq']]);
      }
    });
    touch();
  }

  Future<String> addItem(String visitLocalId, String kind, Map<String, dynamic> data) async {
    final id = newId();
    final payload = {...data, 'client_uuid': id};
    await _db.transaction((tx) async {
      await tx.insert('visit_items', {'id': id, 'visit_local_id': visitLocalId, 'kind': kind, 'payload': jsonEncode(payload), 'created_at': nowIso()});
      await _enqueue(tx, 'visit_$kind', visitLocalId, {'item_id': id});
    });
    touch();
    return id;
  }

  Future<Map<String, dynamic>?> itemPayload(String itemId) async {
    final r = await _db.query('visit_items', where: 'id = ?', whereArgs: [itemId], limit: 1);
    return r.isEmpty ? null : (jsonDecode(r.first['payload'] as String) as Map).cast<String, dynamic>();
  }

  Future<void> addPhoto(String visitLocalId, File file, {String? questionId, String kind = 'evidence', GpsReading? gps}) async {
    final id = newId();
    final size = await file.length();
    await _db.transaction((tx) async {
      await tx.insert('photos', {
        'id': id,
        'visit_local_id': visitLocalId,
        'path': file.path,
        'question_id': questionId,
        'kind': kind,
        'captured_at': nowIso(),
        'latitude': gps?.latitude,
        'longitude': gps?.longitude,
        'bytes': size,
        'uploaded': 0,
      });
      await _enqueue(tx, 'photo_upload', visitLocalId, {'photo_id': id});
    });
    touch();
  }

  Future<void> deletePhoto(String photoId) async {
    final rows = await _db.query('photos', where: 'id = ?', whereArgs: [photoId], limit: 1);
    if (rows.isEmpty) return;
    final row = rows.first;
    await _db.transaction((tx) async {
      if ((row['uploaded'] as int? ?? 0) == 1 && row['server_id'] != null) {
        await _enqueue(tx, 'photo_delete', row['visit_local_id'] as String, {'server_id': row['server_id']});
      } else {
        // Never reached the server: just drop the queued upload (only if it is still waiting).
        await tx.delete('outbox',
            where: "type = 'photo_upload' AND status IN ('PENDING','FAILED','CONFLICT') AND payload LIKE ?", whereArgs: ['%"photo_id":"$photoId"%']);
      }
      await tx.delete('photos', where: 'id = ?', whereArgs: [photoId]);
    });
    final path = row['path'] as String?;
    if (path != null) {
      try {
        final f = File(path);
        if (await f.exists()) await f.delete();
      } catch (_) {/* the file is only a cache of the capture */}
    }
    touch();
  }

  Future<void> submitVisit(LocalVisit v) async {
    if (v.status != 'completed') return;
    await _db.transaction((tx) async {
      await tx.update('visits', {'status': 'submitted', 'updated_at': nowIso()}, where: 'local_id = ?', whereArgs: [v.localId]);
      await tx.update('assignments', {'status': 'completed'}, where: 'id = ?', whereArgs: [v.assignmentId]);
      await _enqueue(tx, 'visit_submit', v.localId, {});
    });
    touch();
  }

  /// Why this visit cannot be submitted yet (empty list = ready).
  Future<List<String>> submitBlockers(LocalVisit v) async {
    final out = <String>[];
    if (v.status == 'scheduled') out.add('Check in at the location first.');
    if (v.status == 'started') out.add('Check out before submitting.');
    final def = await form(v.formId);
    if (def != null) {
      final photoRows = await photos(v.localId);
      final counts = <String, int>{};
      for (final p in photoRows) {
        final q = p['question_id'] as String?;
        if (q != null) counts[q] = (counts[q] ?? 0) + 1;
      }
      final problems = def.problems(v.answers, counts);
      final labels = {for (final q in def.questions) q.id: q.label};
      problems.forEach((qid, msg) => out.add('${labels[qid] ?? 'Question'}: $msg'));
    }
    return out;
  }

  Future<void> createIssue({
    required String title,
    String? description,
    String? category,
    String priority = 'medium',
    LocalVisit? visit,
    String? campaignId,
    String? locationId,
  }) async {
    final id = newId();
    await _db.transaction((tx) async {
      await tx.insert('issues', {
        'id': id,
        'visit_local_id': visit?.localId,
        'campaign_id': visit?.campaignId ?? campaignId,
        'location_id': visit?.locationId ?? locationId,
        'title': title,
        'description': description,
        'category': category,
        'priority': priority,
        'synced': 0,
        'created_at': nowIso(),
      });
      await _enqueue(tx, 'issue_create', visit?.localId ?? id, {'issue_id': id});
    });
    touch();
  }

  Future<Map<String, Object?>?> issueRow(String id) async {
    final r = await _db.query('issues', where: 'id = ?', whereArgs: [id], limit: 1);
    return r.isEmpty ? null : r.first;
  }

  Future<void> clockIn({GpsReading? gps, String? assignmentId, String? notes}) async {
    final day = todayStr();
    if ((await attendance(day))?['clock_in'] != null) return;
    final clientUuid = newId();
    final payload = <String, dynamic>{
      'client_uuid': clientUuid,
      if (assignmentId != null) 'assignment_id': assignmentId,
      if (gps != null) 'latitude': gps.latitude,
      if (gps != null) 'longitude': gps.longitude,
      if (gps?.accuracyM != null) 'accuracy_m': gps!.accuracyM,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
    };
    await _db.transaction((tx) async {
      await tx.insert('attendance', {'day': day, 'clock_in': jsonEncode({...payload, 'at': nowIso()})}, conflictAlgorithm: ConflictAlgorithm.ignore);
      await _enqueue(tx, 'attendance_clock_in', 'attendance:$day', payload);
    });
    touch();
  }

  Future<void> clockOut({GpsReading? gps}) async {
    final day = todayStr();
    final row = await attendance(day);
    if (row == null || row['clock_in'] == null || row['clock_out'] != null) return;
    final payload = <String, dynamic>{
      if (gps != null) 'latitude': gps.latitude,
      if (gps != null) 'longitude': gps.longitude,
    };
    await _db.transaction((tx) async {
      await tx.update('attendance', {'clock_out': jsonEncode({...payload, 'at': nowIso()})}, where: 'day = ?', whereArgs: [day]);
      await _enqueue(tx, 'attendance_clock_out', 'attendance:$day', payload);
    });
    touch();
  }

  Future<void> markNotificationRead(String id) async {
    await _db.transaction((tx) async {
      await tx.update('notifications', {'read': 1}, where: 'id = ?', whereArgs: [id]);
      await _enqueue(tx, 'notification_read', null, {'id': id});
    });
    touch();
  }

  // ---------------------------------------------------------------- results of successful sends

  Future<void> applyVisitCreated(String localId, Map<String, dynamic> server) async {
    await _db.update('visits', {'server_id': server.str('id'), 'server_json': jsonEncode(server), 'updated_at': nowIso()},
        where: 'local_id = ?', whereArgs: [localId]);
    touch();
  }

  Future<String?> serverIdOf(String visitLocalId) async {
    final r = await _db.query('visits', columns: ['server_id'], where: 'local_id = ?', whereArgs: [visitLocalId], limit: 1);
    return r.isEmpty ? null : r.first['server_id'] as String?;
  }

  Future<void> applyPhotoUploaded(String photoId, String? serverId) async {
    final rows = await _db.query('photos', where: 'id = ?', whereArgs: [photoId], limit: 1);
    if (rows.isEmpty) return;
    await _db.update('photos', {'uploaded': 1, 'server_id': serverId}, where: 'id = ?', whereArgs: [photoId]);
    // The server now owns the image; free the phone's storage.
    final path = rows.first['path'] as String?;
    if (path != null) {
      try {
        final f = File(path);
        if (await f.exists()) await f.delete();
      } catch (_) {/* best effort */}
    }
    touch();
  }

  Future<Map<String, Object?>?> photoRow(String id) async {
    final r = await _db.query('photos', where: 'id = ?', whereArgs: [id], limit: 1);
    return r.isEmpty ? null : r.first;
  }

  Future<void> applyIssueSynced(String issueId, String? serverId) async {
    await _db.update('issues', {'synced': 1, 'server_id': serverId}, where: 'id = ?', whereArgs: [issueId]);
    touch();
  }

  Future<void> applyAttendanceSynced(String day, Map<String, dynamic> server) async {
    await _db.update('attendance', {'server_json': jsonEncode(server), 'status': server.str('status')}, where: 'day = ?', whereArgs: [day]);
    touch();
  }

  // ---------------------------------------------------------------- download

  /// Merges a bootstrap / changes payload into the device. Anything with unsynced local operations keeps its local
  /// state (the user is ahead of the server); the server is authoritative for everything else.
  Future<void> applyDownload(Map<String, dynamic> data) async {
    final dirty = await dirtyEntities();
    // Outlets added on this phone that the server has not received yet: their assignment is not in any server list.
    final provisional = <String>{};
    final pendingOutlets = await _db.query('outbox', columns: ['payload', 'entity_id'], where: "type = 'outlet_create' AND status != 'SYNCED'");
    for (final r in pendingOutlets) {
      try {
        final id = (jsonDecode(r['payload'] as String) as Map)['assignment_id']?.toString();
        if (id != null) provisional.add(id);
      } catch (_) {/* a corrupt payload is reported by the sync centre */}
    }
    dirty.addAll(provisional);
    // A visit with unsynced steps also pins its assignment, so a download cannot step its status backwards.
    final visitRows = await _db.rawQuery("SELECT assignment_id FROM visits WHERE local_id IN (SELECT entity_id FROM outbox WHERE status != 'SYNCED' AND entity_id IS NOT NULL)");
    for (final r in visitRows) {
      dirty.add(r['assignment_id'] as String);
    }
    await _db.transaction((tx) async {
      for (final a in data.list('assignments')) {
        final m = (a as Map).cast<String, dynamic>();
        final id = m.str('id');
        if (id == null) continue;
        final existing = await tx.query('assignments', columns: ['status'], where: 'id = ?', whereArgs: [id], limit: 1);
        var status = m.str('status') ?? 'pending';
        if (existing.isNotEmpty && dirty.contains(id)) status = existing.first['status'] as String;
        await tx.insert(
            'assignments',
            {'id': id, 'scheduled_date': (m.str('scheduled_date') ?? '').split('T').first, 'status': status, 'removed': 0, 'json': jsonEncode(m)},
            conflictAlgorithm: ConflictAlgorithm.replace);
      }

      // Assignments that left the server's list (cancelled-and-hidden, reassigned): keep them if the user has work
      // on them, otherwise drop them.
      final window = data.obj('window');
      if (window != null && data['assignment_ids'] is List) {
        final keep = data.list('assignment_ids').map((e) => e.toString()).toSet();
        final from = window.str('from') ?? '0000-00-00';
        final to = window.str('to') ?? '9999-99-99';
        final local = await tx.query('assignments', columns: ['id'], where: 'scheduled_date >= ? AND scheduled_date <= ?', whereArgs: [from, to]);
        for (final r in local) {
          final id = r['id'] as String;
          if (keep.contains(id) || provisional.contains(id)) continue;
          final hasVisit = Sqflite.firstIntValue(await tx.rawQuery('SELECT COUNT(*) FROM visits WHERE assignment_id = ?', [id])) ?? 0;
          if (hasVisit > 0 || dirty.contains(id)) {
            await tx.update('assignments', {'removed': 1}, where: 'id = ?', whereArgs: [id]);
          } else {
            await tx.delete('assignments', where: 'id = ?', whereArgs: [id]);
          }
        }
        // Old days outside the window that carry no field work.
        await tx.rawDelete(
            'DELETE FROM assignments WHERE scheduled_date < ? AND id NOT IN (SELECT assignment_id FROM visits)', [from]);
      }

      for (final f in data.list('forms')) {
        final m = (f as Map).cast<String, dynamic>();
        await tx.insert('forms', {'id': m.str('id'), 'json': jsonEncode(m)}, conflictAlgorithm: ConflictAlgorithm.replace);
      }

      if (data.containsKey('products')) await tx.delete('products');
      for (final p in data.list('products')) {
        final m = (p as Map).cast<String, dynamic>();
        await tx.insert('products', {'id': m.str('id'), 'name': m.str('name') ?? '', 'json': jsonEncode(m)}, conflictAlgorithm: ConflictAlgorithm.replace);
      }

      for (final n in data.list('notifications')) {
        final m = (n as Map).cast<String, dynamic>();
        final id = m.str('id');
        if (id == null) continue;
        final existing = await tx.query('notifications', columns: ['read'], where: 'id = ?', whereArgs: [id], limit: 1);
        final read = (existing.isNotEmpty && (existing.first['read'] as int? ?? 0) == 1) || m['read_at'] != null;
        await tx.insert('notifications', {'id': id, 'created_at': m.str('created_at'), 'read': read ? 1 : 0, 'json': jsonEncode(m)},
            conflictAlgorithm: ConflictAlgorithm.replace);
      }

      for (final v in data.list('visits')) {
        await _mergeServerVisit(tx, (v as Map).cast<String, dynamic>(), dirty);
      }
    });
    touch();
  }

  Future<void> _mergeServerVisit(Transaction tx, Map<String, dynamic> v, Set<String> dirty) async {
    final serverId = v.str('id');
    if (serverId == null) return;
    final clientUuid = v.str('client_uuid');
    List<Map<String, Object?>> rows = [];
    if (clientUuid != null) {
      rows = await tx.query('visits', where: 'local_id = ?', whereArgs: [clientUuid], limit: 1);
    }
    if (rows.isEmpty) {
      rows = await tx.query('visits', where: 'server_id = ?', whereArgs: [serverId], limit: 1);
    }
    final answers = v['answers'] is Map ? jsonEncode(v['answers']) : '{}';
    if (rows.isEmpty) {
      // Created elsewhere (web, another phone, or before a reinstall): adopt it.
      final now = nowIso();
      await tx.insert('visits', {
        'local_id': serverId,
        'server_id': serverId,
        'assignment_id': v.str('assignment_id') ?? '',
        'form_id': v.str('form_id'),
        'campaign_id': v.str('campaign_id'),
        'location_id': v.str('location_id'),
        'status': v.str('status') ?? 'scheduled',
        'visit_date': (v.str('visit_date') ?? '').split('T').first,
        'answers': answers,
        'review_note': v.str('review_note'),
        'server_json': jsonEncode(v),
        'created_at': v.str('created_at') ?? now,
        'updated_at': now,
      });
      return;
    }
    final local = rows.first;
    final localId = local['local_id'] as String;
    final values = <String, Object?>{'server_id': serverId, 'server_json': jsonEncode(v), 'review_note': v.str('review_note')};
    if (!dirty.contains(localId)) {
      values['status'] = v.str('status') ?? local['status'];
      if ((local['answers'] as String?) == '{}' || local['answers'] == null) values['answers'] = answers;
    }
    await tx.update('visits', values, where: 'local_id = ?', whereArgs: [localId]);
  }
}
