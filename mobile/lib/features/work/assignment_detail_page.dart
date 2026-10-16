import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

Future<void> openMapsTo(BuildContext context, double lat, double lng) async {
  final uri = Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$lat,$lng&travelmode=driving');
  final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
  if (!ok && context.mounted) toast(context, 'No maps app could be opened.', error: true);
}

class AssignmentDetailPage extends ConsumerWidget {
  const AssignmentDetailPage({super.key, required this.id});
  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final aAsync = ref.watch(assignmentProvider(id));
    final visit = ref.watch(visitForAssignmentProvider(id)).valueOrNull;

    return Scaffold(
      appBar: AppBar(title: const Text('Assignment')),
      body: aAsync.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => EmptyState(icon: Icons.error_outline, title: 'Could not open this assignment', message: '$e'),
        data: (a) {
          if (a == null) return const EmptyState(icon: Icons.search_off, title: 'Assignment not found', message: 'It may have been removed. Sync to refresh.');
          return ListView(padding: const EdgeInsets.all(16), children: [
            AppCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(a.locationName, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
                  StatusChip(a.isCancelled ? 'cancelled' : (visit?.status ?? a.status)),
                ]),
                const SizedBox(height: 10),
                KeyValue('Campaign', a.campaignName),
                if (a.clientName != null) KeyValue('Client', a.clientName!),
                if (a.activityName != null) KeyValue('Activity', a.activityName!),
                KeyValue('Date', fmtDate(a.scheduledDate)),
                if (a.startTime != null) KeyValue('Time', '${fmtTime(a.startTime)}${a.endTime != null ? ' - ${fmtTime(a.endTime)}' : ''}'),
                if (a.target != null) KeyValue('Target', '${a.target}'),
                if (a.supervisorName != null) KeyValue('Supervisor', a.supervisorName!),
              ]),
            ),
            if (a.removed || a.isCancelled) ...[
              const SizedBox(height: 10),
              AppCard(
                color: const Color(0xFFFFF3E0),
                child: Text(a.isCancelled ? 'This assignment was cancelled.' : 'This assignment was changed or reassigned on the server. Work you already saved on this phone is kept and will still sync.',
                    style: const TextStyle(color: Brand.warn, fontWeight: FontWeight.w700)),
              ),
            ],
            if (a.instructions != null && a.instructions!.trim().isNotEmpty) ...[
              const SectionTitle('Instructions'),
              AppCard(child: Text(a.instructions!)),
            ],
            const SectionTitle('Location'),
            AppCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text([a.address, a.town].where((e) => e != null && e.isNotEmpty).join(', ').ifEmpty('No address on file'), style: const TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 4),
                Text('Check-in radius ${a.radius} m', style: const TextStyle(color: Brand.muted)),
                if (a.lat != null && a.lng != null) ...[
                  const SizedBox(height: 12),
                  OutlinedButton.icon(onPressed: () => openMapsTo(context, a.lat!, a.lng!), icon: const Icon(Icons.navigation_outlined), label: const Text('Navigate')),
                ],
              ]),
            ),
            const SizedBox(height: 18),
            _Actions(a: a, visit: visit),
            const SizedBox(height: 24),
          ]);
        },
      ),
    );
  }
}

extension on String {
  String ifEmpty(String other) => isEmpty ? other : this;
}

class _Actions extends ConsumerWidget {
  const _Actions({required this.a, required this.visit});
  final AssignmentView a;
  final LocalVisit? visit;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final repo = ref.read(repoProvider);
    if (a.isCancelled) return const SizedBox.shrink();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (visit == null && a.needsAccept)
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: OutlinedButton.icon(
            onPressed: () async {
              await repo.acceptAssignment(a.id);
              if (context.mounted) toast(context, 'Accepted. It will sync when you are online.');
            },
            icon: const Icon(Icons.thumb_up_alt_outlined),
            label: const Text('Accept assignment'),
          ),
        ),
      if (visit != null)
        FilledButton.icon(
          onPressed: () => context.push('/visit/${visit!.localId}'),
          icon: const Icon(Icons.storefront),
          label: Text(visit!.isEditable ? 'Continue visit' : 'View visit'),
        )
      else if (!a.removed)
        FilledButton.icon(
          onPressed: () async {
            final v = await repo.startVisit(a);
            if (context.mounted) context.push('/visit/${v.localId}');
          },
          icon: const Icon(Icons.play_arrow_rounded),
          label: const Text('Start visit'),
        ),
    ]);
  }
}
