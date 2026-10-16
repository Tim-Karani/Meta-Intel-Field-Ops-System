import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../state/providers.dart';
import '../common/widgets.dart';

/// Bottom navigation: Home, My work, Visits, Sync, More.
class HomeShell extends ConsumerWidget {
  const HomeShell({super.key, required this.shell});
  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sync = ref.watch(syncStateProvider).valueOrNull;
    final attention = (sync?.stuck ?? 0) > 0;
    final pending = sync?.pending ?? 0;
    final unread = ref.watch(unreadProvider).valueOrNull ?? 0;
    return Scaffold(
      // The banner sits just above the navigation bar so it never collides with the status bar or the page's app bar.
      body: Column(children: [
        Expanded(child: shell),
        const SyncBanner(),
      ]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: shell.currentIndex,
        onDestinationSelected: (i) => shell.goBranch(i, initialLocation: i == shell.currentIndex),
        destinations: [
          const NavigationDestination(icon: Icon(Icons.dashboard_outlined), selectedIcon: Icon(Icons.dashboard), label: 'Home'),
          const NavigationDestination(icon: Icon(Icons.assignment_outlined), selectedIcon: Icon(Icons.assignment), label: 'My work'),
          const NavigationDestination(icon: Icon(Icons.storefront_outlined), selectedIcon: Icon(Icons.storefront), label: 'Visits'),
          NavigationDestination(
            icon: Badge(isLabelVisible: attention || pending > 0, backgroundColor: attention ? Colors.red : null, label: Text(attention ? '!' : '$pending'), child: const Icon(Icons.sync_outlined)),
            selectedIcon: const Icon(Icons.sync),
            label: 'Sync',
          ),
          NavigationDestination(
            icon: Badge(isLabelVisible: unread > 0, label: Text('$unread'), child: const Icon(Icons.menu)),
            selectedIcon: const Icon(Icons.menu_open),
            label: 'More',
          ),
        ],
      ),
    );
  }
}
