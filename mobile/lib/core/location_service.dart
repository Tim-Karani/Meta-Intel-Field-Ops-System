import 'package:geolocator/geolocator.dart';

import 'config.dart';

class GpsReading {
  GpsReading({required this.latitude, required this.longitude, required this.accuracyM, required this.timestamp, required this.mocked});

  final double latitude;
  final double longitude;
  final double? accuracyM;
  final DateTime timestamp;
  final bool mocked;

  /// Raw evidence for the server; the server decides what it means. device_ts is when the reading was TAKEN,
  /// which is what lets an offline check-in be judged fairly when it syncs later.
  Map<String, dynamic> toApi({required String deviceId}) => {
        'latitude': latitude,
        'longitude': longitude,
        if (accuracyM != null) 'accuracy_m': accuracyM,
        'device_ts': timestamp.toUtc().toIso8601String(),
        'mock_flag': mocked,
        'provider': 'fused',
        'device_id': deviceId,
        'app_version': AppConfig.appVersion,
      };
}

class LocationException implements Exception {
  LocationException(this.message);
  final String message;
  @override
  String toString() => message;
}

class LocationService {
  /// Gets a fresh high-accuracy fix, asking for permission if needed. Throws [LocationException] with a user-readable reason.
  Future<GpsReading> current() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw LocationException('Location is switched off. Turn on GPS and try again.');
    }
    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
    if (perm == LocationPermission.denied) throw LocationException('Location permission was denied.');
    if (perm == LocationPermission.deniedForever) {
      throw LocationException('Location permission is blocked. Enable it for this app in system settings.');
    }
    try {
      final p = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
        timeLimit: const Duration(seconds: 25),
      );
      return GpsReading(
        latitude: p.latitude,
        longitude: p.longitude,
        accuracyM: p.accuracy,
        timestamp: p.timestamp,
        mocked: p.isMocked,
      );
    } catch (_) {
      throw LocationException('Could not get a GPS fix. Move to an open area and try again.');
    }
  }

  /// A quick, non-blocking position for tagging a photo: the last known fix if there is one, otherwise nothing.
  /// (Never prompts and never waits - a photo must not be delayed by GPS.)
  Future<GpsReading?> lastKnown() async {
    try {
      final p = await Geolocator.getLastKnownPosition();
      if (p == null) return null;
      return GpsReading(latitude: p.latitude, longitude: p.longitude, accuracyM: p.accuracy, timestamp: p.timestamp, mocked: p.isMocked);
    } catch (_) {
      return null;
    }
  }
}
