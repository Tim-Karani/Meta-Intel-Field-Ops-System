import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Shows phone notifications (the bar at the top of the screen). Used by the background sync so an agent hears about
/// a new assignment or review even when the app is closed. Everything is best effort: a phone that refuses never breaks the app.
class NotifyService {
  NotifyService._();

  static final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  static bool _ready = false;

  static Future<void> init() async {
    if (_ready) return;
    const settings = InitializationSettings(android: AndroidInitializationSettings('@mipmap/ic_launcher'));
    await _plugin.initialize(settings);
    _ready = true;
  }

  /// Android 13 and newer ask the person once. Safe to call again: it does nothing when already answered.
  static Future<void> requestPermission() async {
    try {
      await init();
      await _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.requestNotificationsPermission();
    } catch (_) {/* not available on this phone */}
  }

  static Future<void> show({required int id, required String title, required String body}) async {
    try {
      await init();
      const details = NotificationDetails(
        android: AndroidNotificationDetails(
          'metaintel_updates',
          'MetaIntel updates',
          channelDescription: 'New assignments, reviews and messages from your supervisor',
          importance: Importance.high,
          priority: Priority.high,
        ),
      );
      await _plugin.show(id, title, body, details);
    } catch (_) {/* permission refused or not available */}
  }
}
