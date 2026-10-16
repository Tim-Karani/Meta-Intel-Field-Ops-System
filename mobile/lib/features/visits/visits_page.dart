import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

class VisitsPage extends ConsumerStatefulWidget {
  const VisitsPage({super.key});

  @override
  ConsumerState<VisitsPage> createState() => _VisitsPageState();
}

class _VisitsPageState extends ConsumerState<VisitsPage> {
  String _filter = 'active';

  @override
  Widget build(BuildContext context) {
    final visits = ref.watch(visitsProvider).valueOrNull ?? const <LocalVisit>[];
    final assignments = ref.watch(assignmentsProvider).valueOrNull ?? const <AssignmentView>[];
    final byId = {for (final a in assignments) a.id: a};
    bool match(LocalVisit v) {
      switch (_filter) {
        case 'active':
          return v.isEditable;
        case 'review':
          return v.status == 'submitted' || v.status == 'under_review';
        default:
          return v.status == 'approved' || v.status == 'rejected';
      }
    }

    final list = visits.where(match).toList();
    return Scaffold(
      appBar: AppBar(title: const Text('Visits')),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: SizedBox(
            width: double.infinity,
            child: SegmentedButton<String>(
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: 'active', label: Text('In progress')),
                ButtonSegment(value: 'review', label: Text('In review')),
                ButtonSegment(value: 'done', label: Text('Closed')),
              ],
              selected: {_filter},
              onSelectionChanged: (s) => setState(() => _filter = s.first),
            ),
          ),
        ),
        Expanded(
          child: list.isEmpty
              ? const EmptyState(icon: Icons.storefront_outlined, title: 'No visits here', message: 'Start a visit from My work.')
              : ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: list.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (c, i) {
                    final v = list[i];
                    final a = byId[v.assignmentId];
                    return AppCard(
                      onTap: () => c.push('/visit/${v.localId}'),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text(a?.locationName ?? 'Visit', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
                          StatusChip(v.status),
                        ]),
                        const SizedBox(height: 2),
                        Text('${a?.campaignName ?? ''}  -  ${fmtDate(v.visitDate)}', style: const TextStyle(color: Brand.muted, fontSize: 13)),
                        if (v.isRejected && (v.reviewNote ?? '').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text('Rejected: ${v.reviewNote}', style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w600))),
                      ]),
                    );
                  },
                ),
        ),
      ]),
    );
  }
}
