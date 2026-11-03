import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../models/outbox_op.dart';
import '../../state/providers.dart';
import 'widgets.dart';

/// Shown on a visit when the server refused something. Says in plain words what was refused and why, and offers the
/// ways out: try again, or (for an outlet that never reached the server) discard it. Work stays blocked until then.
class RefusedBanner extends ConsumerWidget {
  const RefusedBanner({super.key, required this.visitId, required this.ops});
  final String visitId;
  final List<OutboxOp> ops;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locked = ops.any((o) => foundationOps.contains(o.type));
    final first = ops.first;
    final isOutlet = ops.any((o) => o.type == 'outlet_create');
    final repo = ref.read(repoProvider);
    return Container(
      width: double.infinity,
      color: const Color(0xFFFDECEE),
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(Icons.error_outline, color: Brand.bad),
          const SizedBox(width: 8),
          Expanded(
            child: Text(locked ? 'The server did not accept this ${isOutlet ? 'outlet' : 'visit'}' : '${ops.length} item${ops.length == 1 ? '' : 's'} could not be sent',
                style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w800)),
          ),
        ]),
        const SizedBox(height: 6),
        Text(first.explanation, style: const TextStyle(fontSize: 13.5)),
        if (locked) const Padding(padding: EdgeInsets.only(top: 6), child: Text('You cannot add more to this visit until this is resolved.', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13))),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 6, children: [
          FilledButton.tonal(
            onPressed: () async {
              for (final o in ops) {
                await repo.retryOp(o.id);
              }
              ref.read(syncServiceProvider).run(manual: true);
            },
            child: const Text('Try again'),
          ),
          OutlinedButton(onPressed: () => context.go('/sync'), child: const Text('Sync center')),
          if (isOutlet)
            OutlinedButton(
              style: OutlinedButton.styleFrom(foregroundColor: Brand.bad),
              onPressed: () async {
                final yes = await confirm(context,
                    title: 'Discard this outlet?',
                    message: 'It was never accepted by the server. The outlet and everything recorded for it on this phone will be deleted.',
                    yes: 'Discard',
                    danger: true);
                if (!yes) return;
                await repo.discardUnsentOutlet(visitId);
                if (context.mounted) context.go('/work');
              },
              child: const Text('Discard outlet'),
            ),
        ]),
      ]),
    );
  }
}
