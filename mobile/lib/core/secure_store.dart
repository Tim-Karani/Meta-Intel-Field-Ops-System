import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// The bearer token and signed-in user live only in the platform keystore - never in SQLite, never in logs.
class SecureStore {
  SecureStore([FlutterSecureStorage? storage])
      : _s = storage ?? const FlutterSecureStorage(aOptions: AndroidOptions(encryptedSharedPreferences: true));

  final FlutterSecureStorage _s;

  Future<String?> get token => _s.read(key: 'token');
  Future<DateTime?> get expiresAt async {
    final v = await _s.read(key: 'expires_at');
    return v == null ? null : DateTime.tryParse(v);
  }

  Future<Map<String, dynamic>?> get user async {
    final v = await _s.read(key: 'user');
    if (v == null) return null;
    try {
      return jsonDecode(v) as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  }

  Future<void> saveSession({required String token, DateTime? expiresAt, required Map<String, dynamic> user}) async {
    await _s.write(key: 'token', value: token);
    if (expiresAt != null) await _s.write(key: 'expires_at', value: expiresAt.toIso8601String());
    await _s.write(key: 'user', value: jsonEncode(user));
  }

  Future<void> saveToken(String token, DateTime? expiresAt) async {
    await _s.write(key: 'token', value: token);
    if (expiresAt != null) await _s.write(key: 'expires_at', value: expiresAt.toIso8601String());
  }

  Future<void> clear() async {
    await _s.delete(key: 'token');
    await _s.delete(key: 'expires_at');
    await _s.delete(key: 'user');
  }
}
