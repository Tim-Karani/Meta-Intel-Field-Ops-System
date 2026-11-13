import 'package:battery_plus/battery_plus.dart';

import '../data/session.dart';
import 'config.dart';
import 'location_service.dart';
import 'util.dart';

/// Tells the server where the phone is and how much battery it has, so supervisors see who is working and who has gone quiet.
/// Best effort: it never prompts for permission, never blocks the screen and never reports an error to the agent.
/// Sent when the app opens, when it comes back to the foreground, and every few minutes while it is open.
class PingService {
  PingService(this._session, this._location);

  final Session _session;
  final LocationService _location;
  DateTime? _last;

  Future<void> send({bool force = false}) async {
    if (!_session.signedIn || _session.expired || !_session.can('attendance.create')) return;
    final now = DateTime.now();
    if (!force && _last != null && now.difference(_last!) < const Duration(minutes: 2)) return;
    _last = now;
    try {
      final body = <String, dynamic>{'app_version': AppConfig.appVersion, 'recorded_at': nowIso()};
      try {
        final level = await Battery().batteryLevel;
        if (level >= 0 && level <= 100) body['battery_pct'] = level;
      } catch (_) {/* some phones do not report it */}
      final g = await _location.lastKnown();
      if (g != null) {
        body['latitude'] = g.latitude;
        body['longitude'] = g.longitude;
        if (g.accuracyM != null) body['accuracy_m'] = g.accuracyM;
      }
      await _session.api.post('agent/ping', body: body);
    } catch (_) {/* offline or refused: try again at the next interval */}
  }
}
