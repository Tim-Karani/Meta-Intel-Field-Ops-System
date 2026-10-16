import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

class MorePage extends ConsumerWidget {
  const MorePage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unread = ref.watch(unreadProvider).valueOrNull ?? 0;
    final user = ref.watch(sessionProvider).user;
    Widget tile(IconData icon, String title, String subtitle, String route, {int badge = 0}) => AppCard(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
          onTap: () => context.push(route),
          child: ListTile(
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(backgroundColor: Brand.saffronSoft, child: Icon(icon, color: Brand.ink)),
            title: Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text(subtitle),
            trailing: badge > 0 ? Badge(label: Text('$badge')) : const Icon(Icons.chevron_right),
          ),
        );
    return Scaffold(
      appBar: AppBar(title: const Text('More')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        AppCard(
          child: Row(children: [
            CircleAvatar(radius: 24, backgroundColor: Brand.ink, child: Text(_initials(user?['name']?.toString() ?? ''), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800))),
            const SizedBox(width: 12),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(user?['name']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
              Text(user?['email']?.toString() ?? '', style: const TextStyle(color: Brand.muted)),
            ])),
          ]),
        ),
        const SizedBox(height: 12),
        tile(Icons.access_time, 'Attendance', 'Clock in and out for the day', '/attendance'),
        const SizedBox(height: 10),
        tile(Icons.alt_route, 'Routes', "Today's stops in order, with navigation", '/routes'),
        const SizedBox(height: 10),
        tile(Icons.report_problem_outlined, 'Issues', 'Report and review problems', '/issues'),
        const SizedBox(height: 10),
        tile(Icons.notifications_outlined, 'Notifications', 'Messages from your supervisor', '/notifications', badge: unread),
        const SizedBox(height: 10),
        tile(Icons.person_outline, 'Profile', 'Account, server and sign out', '/profile'),
      ]),
    );
  }

  static String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+')).where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return '?';
    return (parts.first[0] + (parts.length > 1 ? parts.last[0] : '')).toUpperCase();
  }
}
