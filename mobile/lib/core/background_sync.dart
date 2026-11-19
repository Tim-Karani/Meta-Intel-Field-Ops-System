import 'dart:convert';
import 'dart:ui' show DartPluginRegistrant;

import 'package:flutter/widgets.dart';
import 'package:workmanager/workmanager.dart';

import '../data/repository.dart';
import '../data/session.dart';
import '../data/sync_service.dart';
import 'config.dart';
import 'db.dart';
import 'notify_service.dart';
import 'secure_store.dart';

const _uniqueName = 'metaintel-background-sync';
const _taskName = 'metaintel.sync';

/// Runs in the background, about every 15 minutes (the shortest time Android allows), while the phone has a connection.
@pragma('vm:entry-point')
void backgroundSyncDispatcher() {
  Workmanager().executeTask((task, inputData) async {
    try {
      WidgetsFlutterBinding.ensureInitialized();
      DartPluginRegistrant.ensureInitialized();
      return await BackgroundSync.runOnce();
    } catch (_) {
      return true; // the next cycle simply tries again
    }
  });
}

/// Sends saved work and fetches new work while the app is closed, and shows a phone notification for anything new.
/// It never reads the phone's location: the "no tracking when the app is closed" promise still holds.
class BackgroundSync {
  static Future<void> register() async {
    try {
      await Workmanager().initialize(backgroundSyncDispatcher);
      await Workmanager().registerPeriodicTask(
        _uniqueName,
        _taskName,
        frequency: const Duration(minutes: 15),
        constraints: Constraints(networkType: NetworkType.connected),
      );
    } catch (_) {/* the app still syncs whenever it is open */}
  }

  static Future<void> cancel() async {
    try {
      await Workmanager().cancelByUniqueName(_uniqueName);
    } catch (_) {}
  }

  /// One background cycle. Returns true so the system does not retry aggressively.
  static Future<bool> runOnce() async {
    final adb = await AppDatabase.open();
    try {
      final probe = Repository(adb, deviceId: '');
      // While the app is open it syncs by itself; do not run two syncs side by side.
      final fg = DateTime.tryParse(await probe.meta('foreground_at') ?? '');
      if (fg != null && DateTime.now().difference(fg) < const Duration(minutes: 3)) return true;

      final repo = Repository(adb, deviceId: (await probe.meta('device_id')) ?? 'background');
      final baseUrl = (await repo.meta('base_url')) ?? AppConfig.defaultBaseUrl;
      final session = Session(SecureStore(), repo, baseUrl);
      await session.restore();
      if (!session.signedIn || session.expired) return true;

      await SyncService(repo, session).run();
      await _announceNew(repo);
    } finally {
      try {
        await adb.db.close();
      } catch (_) {}
    }
    return true;
  }

  static Future<void> _announceNew(Repository repo) async {
    final all = await repo.notifications();
    final raw = await repo.meta('notified_ids');
    final seen = <String>{if (raw != null) ...(jsonDecode(raw) as List).map((e) => '$e')};
    final fresh = [for (final n in all) if (n['_read'] != true && !seen.contains('${n['id']}')) n];
    // First ever run: remember what is already there instead of announcing old items.
    if (raw != null) {
      for (final n in fresh.take(3)) {
        await NotifyService.show(id: '${n['id']}'.hashCode & 0x7fffffff, title: '${n['title'] ?? 'MetaIntel'}', body: '${n['body'] ?? ''}');
      }
      if (fresh.length > 3) {
        await NotifyService.show(id: 1, title: 'MetaIntel', body: '${fresh.length - 3} more updates waiting for you.');
      }
    }
    final keep = [for (final n in all) '${n['id']}'].take(200).toList();
    await repo.setMeta('notified_ids', jsonEncode(keep));
  }
}
