/// Build-time defaults. The server address can also be changed on the sign-in screen
/// (needed on a real phone, where 10.0.2.2 - the emulator's alias for your PC - does not work).
class AppConfig {
  static const String defaultBaseUrl =
      String.fromEnvironment('API_BASE_URL', defaultValue: 'http://10.0.2.2:8080/api/v1');
  static const String appVersion = '1.10.0';

  /// A failed (non-network) attempt is retried this many times before the item waits for manual retry.
  static const int maxRetries = 8;

  /// Mapping a new outlet from a check-in needs a good fix (the server enforces the same limit).
  static const double captureMaxAccuracyM = 50;

  /// An outlet check-in is refused beyond this distance (the server enforces the same limit, and is the one that decides).
  static const double checkinMaxM = 200;

  /// Benefit of the doubt for a noisy GPS fix, as on the server.
  static const double checkinCreditM = 30;

  /// Window the app keeps on the device: assignments from [pastDays] ago to [futureDays] ahead come from the server.
  static const int pastDays = 3;
  static const int futureDays = 14;

  /// Longest edge and JPEG quality applied to photos before they are queued for upload.
  static const double photoMaxEdge = 1600;
  static const int photoQuality = 78;
}
