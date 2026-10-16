import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../state/providers.dart';
import 'router.dart';
import 'theme.dart';

class FieldOpsApp extends ConsumerStatefulWidget {
  const FieldOpsApp({super.key});

  @override
  ConsumerState<FieldOpsApp> createState() => _FieldOpsAppState();
}

class _FieldOpsAppState extends ConsumerState<FieldOpsApp> with WidgetsBindingObserver {
  late final GoRouter _router;
  bool _wasSignedIn = false;

  @override
  void initState() {
    super.initState();
    final session = ref.read(sessionProvider);
    _router = buildRouter(session);
    _wasSignedIn = session.signedIn;
    session.addListener(_onSession);
    WidgetsBinding.instance.addObserver(this);
  }

  void _onSession() {
    final session = ref.read(sessionProvider);
    if (session.signedIn && !_wasSignedIn) {
      ref.read(syncServiceProvider).requestSync(delay: const Duration(milliseconds: 500));
    }
    _wasSignedIn = session.signedIn;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      ref.read(syncServiceProvider).requestSync(delay: const Duration(seconds: 1));
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    ref.read(sessionProvider).removeListener(_onSession);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'Field Operations',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      routerConfig: _router,
    );
  }
}
