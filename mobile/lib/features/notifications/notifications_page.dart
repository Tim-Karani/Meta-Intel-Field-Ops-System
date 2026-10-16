import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

class NotificationsPage extends ConsumerWidget {
  const NotificationsPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final list = ref.watch(notificationsProvider).valueOrNull ?? const <Map<String, dynamic>>[];
    return Scaffold(
      appBar: AppBar(title: const Text('Notifications')),
      body: list.isEmpty
          ? const EmptyState(icon: Icons.notifications_none, title: 'No notifications', message: 'Updates about your assignments and reviews appear here.')
          : RefreshIndicator(
              onRefresh: () => ref.read(syncServiceProvider).run(manual: true),
              child: ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: list.length,
                separatorBuilder: (_, __) => const SizedBox(height: 10),
                itemBuilder: (c, i) {
                  final n = list[i];
                  final read = n['_read'] == true;
                  return AppCard(
                    color: read ? Colors.white : Brand.saffronSoft,
                    onTap: read ? null : () => ref.read(repoProvider).markNotificationRead('${n['id']}'),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Icon(read ? Icons.notifications_none : Icons.notifications_active, color: read ? Brand.muted : Brand.saffron),
                      const SizedBox(width: 12),
                      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('${n['title']}', style: TextStyle(fontWeight: read ? FontWeight.w600 : FontWeight.w800)),
                        if ((n.str('body') ?? '').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(n.str('body')!)),
                        const SizedBox(height: 4),
                        Text(relative(n.str('created_at')), style: const TextStyle(color: Brand.muted, fontSize: 12)),
                      ])),
                    ]),
                  );
                },
              ),
            ),
    );
  }
}
