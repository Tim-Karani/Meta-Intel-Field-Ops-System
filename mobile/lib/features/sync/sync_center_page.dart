import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../data/sync_service.dart';
import '../../models/outbox_op.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

/// Everything waiting to reach the server, what failed and why, with manual retry. Nothing here is ever dropped silently.
class SyncCenterPage extends ConsumerWidget {
  const SyncCenterPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sync = ref.watch(syncStateProvider).valueOrNull ?? const SyncState();
    final ops = ref.watch(outboxProvider).valueOrNull ?? const <OutboxOp>[];
    final lastSync = ref.watch(lastSyncProvider).valueOrNull;
    final session = ref.watch(sessionProvider);
    final repo = ref.read(repoProvider);

    final stuck = ops.where((o) => o.needsAttention).toList();
    final waiting = ops.where((o) => o.status == OpStatus.pending || o.status == OpStatus.syncing).toList();
    final done = ops.where((o) => o.status == OpStatus.synced).toList().reversed.take(15).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('Sync center')),
      body: RefreshIndicator(
        onRefresh: () => ref.read(syncServiceProvider).run(manual: true),
        child: ListView(padding: const EdgeInsets.all(16), children: [
          AppCard(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Icon(sync.online ? Icons.cloud_done_outlined : Icons.cloud_off_outlined, color: sync.online ? Brand.good : Brand.warn, size: 28),
                const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(sync.running ? 'Syncing...' : (sync.online ? 'Online' : 'Offline'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  Text('Last sync: ${relative(lastSync)}', style: const TextStyle(color: Brand.muted)),
                ])),
              ]),
              const SizedBox(height: 12),
              Row(children: [
                _Count('Waiting', sync.pending, Brand.warn),
                _Count('Need attention', sync.stuck, sync.stuck > 0 ? Brand.bad : Brand.muted),
                _Count('Sent', done.length, Brand.good),
              ]),
              if (sync.lastError != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(sync.lastError!, style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w600))),
              if (session.expired || sync.sessionExpired) ...[
                const SizedBox(height: 10),
                FilledButton.icon(onPressed: () => context.go('/login'), icon: const Icon(Icons.lock_open), label: const Text('Sign in again to sync')),
              ],
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: sync.running ? null : () => ref.read(syncServiceProvider).run(manual: true),
                icon: const Icon(Icons.sync),
                label: const Text('Sync now'),
              ),
              if (stuck.isNotEmpty) ...[
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: () async {
                    await repo.retryAllStuck();
                    ref.read(syncServiceProvider).requestSync();
                  },
                  icon: const Icon(Icons.replay),
                  label: const Text('Retry all that failed'),
                ),
              ],
            ]),
          ),
          if (stuck.isNotEmpty) ...[
            const SectionTitle('Needs your attention'),
            for (final o in stuck) Padding(padding: const EdgeInsets.only(bottom: 10), child: _OpCard(op: o)),
          ],
          if (waiting.isNotEmpty) ...[
            const SectionTitle('Waiting to send'),
            for (final o in waiting) Padding(padding: const EdgeInsets.only(bottom: 10), child: _OpCard(op: o)),
          ],
          if (done.isNotEmpty) ...[
            const SectionTitle('Recently sent'),
            for (final o in done) Padding(padding: const EdgeInsets.only(bottom: 10), child: _OpCard(op: o)),
          ],
          if (ops.isEmpty) const Padding(padding: EdgeInsets.only(top: 40), child: EmptyState(icon: Icons.cloud_done_outlined, title: 'Everything is up to date', message: 'Nothing is waiting to sync.')),
          const SectionTitle('Maintenance'),
          OutlinedButton.icon(
            onPressed: sync.running
                ? null
                : () async {
                    final yes = await confirm(context,
                        title: 'Re-download all data?',
                        message: 'Fetches assignments, forms and products again. Your unsent work is not affected.',
                        yes: 'Re-download');
                    if (yes) await ref.read(syncServiceProvider).run(manual: true, fullDownload: true);
                  },
            icon: const Icon(Icons.download_outlined),
            label: const Text('Re-download everything'),
          ),
          const SizedBox(height: 24),
        ]),
      ),
    );
  }
}

class _Count extends StatelessWidget {
  const _Count(this.label, this.n, this.color);
  final String label;
  final int n;
  final Color color;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('$n', style: TextStyle(color: color, fontSize: 24, fontWeight: FontWeight.w800)),
          Text(label, style: const TextStyle(color: Brand.muted, fontSize: 12.5)),
        ]),
      );
}

class _OpCard extends ConsumerWidget {
  const _OpCard({required this.op});
  final OutboxOp op;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final repo = ref.read(repoProvider);
    final stuck = op.needsAttention;
    final color = switch (op.status) {
      OpStatus.synced => Brand.good,
      OpStatus.failed => Brand.bad,
      OpStatus.conflict => Brand.warn,
      OpStatus.syncing => Brand.ink,
      OpStatus.pending => Brand.muted,
    };
    final next = op.nextAttemptAt == null ? null : DateTime.tryParse(op.nextAttemptAt!);
    final backingOff = op.status == OpStatus.pending && next != null && next.isAfter(DateTime.now());
    return AppCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(op.label, style: const TextStyle(fontWeight: FontWeight.w800))),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
            decoration: BoxDecoration(color: color.withAlpha(30), borderRadius: BorderRadius.circular(12)),
            child: Text(op.status.name.toUpperCase(), style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w800)),
          ),
        ]),
        const SizedBox(height: 2),
        Text('Saved ${relative(op.createdAt)}${op.retryCount > 0 ? '  -  ${op.retryCount} retr${op.retryCount == 1 ? 'y' : 'ies'}' : ''}${backingOff ? '  -  next try ${relative(op.nextAttemptAt)}' : ''}',
            style: const TextStyle(color: Brand.muted, fontSize: 12.5)),
        if (op.error != null && op.status != OpStatus.synced)
          Padding(padding: const EdgeInsets.only(top: 6), child: Text(op.error!, style: TextStyle(color: op.needsAttention ? Brand.bad : Brand.muted, fontSize: 13))),
        if (stuck) ...[
          const SizedBox(height: 8),
          Row(children: [
            Expanded(
              child: OutlinedButton(
                onPressed: () async {
                  await repo.retryOp(op.id);
                  ref.read(syncServiceProvider).requestSync();
                },
                child: const Text('Retry'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton(
                style: OutlinedButton.styleFrom(foregroundColor: Brand.bad),
                onPressed: () async {
                  final yes = await confirm(context,
                      title: 'Stop trying to send this?',
                      message: 'The data stays on this phone, but this step will not be sent to the server, and later steps for the same visit stay blocked. Only do this if your supervisor told you to.',
                      yes: 'Stop sending',
                      danger: true);
                  if (yes) await repo.discardOp(op.id);
                },
                child: const Text('Give up'),
              ),
            ),
          ]),
        ],
      ]),
    );
  }
}
