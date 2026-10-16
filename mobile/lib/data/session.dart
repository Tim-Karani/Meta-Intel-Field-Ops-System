import 'package:flutter/foundation.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../core/secure_store.dart';
import '../core/util.dart';
import 'repository.dart';

/// Who is signed in and how to reach the server. Notifies the router when the user signs in or out.
class Session extends ChangeNotifier {
  Session(this._store, this._repo, String baseUrl) {
    _baseUrl = baseUrl;
    _client = _build();
  }

  final SecureStore _store;
  final Repository _repo;
  late String _baseUrl;
  late ApiClient _client;
  Map<String, dynamic>? _user;
  bool _expired = false;

  ApiClient get api => _client;
  String get baseUrl => _baseUrl;
  Map<String, dynamic>? get user => _user;
  bool get signedIn => _user != null;

  /// True after the server answered 401: the saved work is safe, but syncing needs a fresh sign-in.
  bool get expired => _expired;

  String get userName => _user?.str('name') ?? '';
  String get userId => _user?.str('id') ?? '';

  ApiClient _build() => ApiClient(baseUrl: _baseUrl, readToken: () => _store.token, onUnauthorized: _onUnauthorized);

  void _onUnauthorized() {
    if (!_expired && signedIn) {
      _expired = true;
      notifyListeners();
    }
  }

  /// Restores a saved session at app start (works offline: the token is only checked when we next talk to the server).
  Future<void> restore() async {
    final token = await _store.token;
    final user = await _store.user;
    if (token != null && user != null) {
      _user = user;
      final exp = await _store.expiresAt;
      _expired = exp != null && exp.isBefore(DateTime.now());
      notifyListeners();
    }
  }

  Future<void> setBaseUrl(String url) async {
    final u = url.trim();
    if (u.isEmpty || u == _baseUrl) return;
    _baseUrl = u;
    _client = _build();
    await _repo.setMeta('base_url', u);
    notifyListeners();
  }

  /// Signs in. Throws [ApiException] (bad credentials, offline, ...) or [StateError] with a readable message.
  Future<void> login(String login, String password) async {
    final data = await _client.post('auth/login', auth: false, body: {
      'login': login.trim(),
      'password': password,
      'device_name': 'Field app ${AppConfig.appVersion}',
    }) as Map<String, dynamic>;
    final user = (data['user'] as Map).cast<String, dynamic>();
    final perms = user.list('permissions').map((e) => e.toString()).toSet();
    if (!perms.contains('assignments.view') || !perms.contains('visits.create')) {
      // Do not leave a usable token behind for an account this app cannot serve.
      try {
        await _store.saveToken(data['token'].toString(), null);
        await _client.post('auth/logout');
      } catch (_) {/* best effort */}
      await _store.clear();
      throw StateError('This app is for field agents. Use the web app for your role.');
    }

    // A different person signing in on a phone that still holds unsynced work must not mix or lose it.
    final previous = await _repo.meta('user_id');
    final newId = user.str('id');
    if (previous != null && newId != null && previous != newId) {
      final dirty = await _repo.outboxCounts();
      final unsynced = (dirty['PENDING'] ?? 0) + (dirty['FAILED'] ?? 0) + (dirty['CONFLICT'] ?? 0) + (dirty['SYNCING'] ?? 0);
      if (unsynced > 0) {
        try {
          await _store.saveToken(data['token'].toString(), null);
          await _client.post('auth/logout');
        } catch (_) {/* best effort */}
        await _store.clear();
        throw StateError('This phone still holds unsynced work from another account. Sign in with that account and sync first.');
      }
      await _repo.wipeAll();
    }

    DateTime? exp;
    if (data['expires_at'] != null) exp = DateTime.tryParse(data['expires_at'].toString());
    await _store.saveSession(token: data['token'].toString(), expiresAt: exp, user: user);
    if (newId != null) await _repo.setMeta('user_id', newId);
    _user = user;
    _expired = false;
    notifyListeners();
  }

  /// Exchanges the token for a fresh one when it is close to expiry. Silent on failure.
  Future<void> refreshIfNeeded() async {
    final exp = await _store.expiresAt;
    if (exp == null || exp.difference(DateTime.now()) > const Duration(days: 2)) return;
    try {
      final data = await _client.post('auth/refresh') as Map<String, dynamic>;
      await _store.saveToken(data['token'].toString(), DateTime.tryParse('${data['expires_at']}'));
    } catch (_) {/* keep the old token until it really expires */}
  }

  Future<void> refreshProfile() async {
    try {
      final me = await _client.get('auth/me') as Map<String, dynamic>;
      _user = me;
      final token = await _store.token;
      if (token != null) await _store.saveSession(token: token, user: me);
      notifyListeners();
    } catch (_) {/* offline is fine */}
  }

  /// Signs out. Local field work is kept (it is deleted only if a different person signs in after it has synced).
  Future<void> logout() async {
    try {
      await _client.post('auth/logout');
    } catch (_) {/* the token may already be dead or we are offline */}
    await _store.clear();
    _user = null;
    _expired = false;
    notifyListeners();
  }
}
