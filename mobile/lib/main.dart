import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/app.dart';
import 'core/config.dart';
import 'core/db.dart';
import 'core/secure_store.dart';
import 'core/util.dart';
import 'data/repository.dart';
import 'data/session.dart';
import 'data/sync_service.dart';
import 'state/providers.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final adb = await AppDatabase.open();
  // A per-install device id, sent with GPS evidence so reviewers can tell phones apart.
  Future<String> deviceId() async {
    final probe = Repository(adb, deviceId: '');
    var id = await probe.meta('device_id');
    if (id == null) {
      id = newId();
      await probe.setMeta('device_id', id);
    }
    return id;
  }

  final repo = Repository(adb, deviceId: await deviceId());
  final baseUrl = (await repo.meta('base_url')) ?? AppConfig.defaultBaseUrl;
  final session = Session(SecureStore(), repo, baseUrl);
  await session.restore();
  final sync = SyncService(repo, session);
  await sync.start();

  runApp(ProviderScope(
    overrides: [
      repoProvider.overrideWithValue(repo),
      sessionProvider.overrideWithValue(session),
      syncServiceProvider.overrideWithValue(sync),
    ],
    child: const FieldOpsApp(),
  ));
}
