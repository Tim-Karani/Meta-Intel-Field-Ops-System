import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

class AssignmentsPage extends ConsumerStatefulWidget {
  const AssignmentsPage({super.key});

  @override
  ConsumerState<AssignmentsPage> createState() => _AssignmentsPageState();
}

class _AssignmentsPageState extends ConsumerState<AssignmentsPage> {
  String _filter = 'today';

  @override
  Widget build(BuildContext context) {
    final async = ref.watch(assignmentsProvider);
    final visits = ref.watch(visitsProvider).valueOrNull ?? const <LocalVisit>[];
    final byAssignment = <String, LocalVisit>{for (final v in visits) v.assignmentId: v};
    final today = todayStr();

    return Scaffold(
      appBar: AppBar(title: const Text('My work')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => context.push('/outlet/new'),
        icon: const Icon(Icons.add_business_outlined),
        label: const Text('Add outlet'),
      ),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: SizedBox(
            width: double.infinity,
            child: SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'today', label: Text('Today')),
                ButtonSegment(value: 'upcoming', label: Text('Upcoming')),
                ButtonSegment(value: 'past', label: Text('Past')),
              ],
              selected: {_filter},
              onSelectionChanged: (s) => setState(() => _filter = s.first),
              showSelectedIcon: false,
            ),
          ),
        ),
        Expanded(
          child: async.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (e, _) => EmptyState(icon: Icons.error_outline, title: 'Could not load assignments', message: '$e'),
            data: (all) {
              var list = all.where((a) {
                if (_filter == 'today') return a.scheduledDate == today;
                if (_filter == 'upcoming') return a.scheduledDate.compareTo(today) > 0;
                return a.scheduledDate.compareTo(today) < 0;
              }).toList();
              if (_filter == 'past') list = list.reversed.toList();
              return RefreshIndicator(
                onRefresh: () => ref.read(syncServiceProvider).run(manual: true),
                child: list.isEmpty
                    ? ListView(children: [
                        SizedBox(
                          height: 320,
                          child: EmptyState(
                            icon: Icons.assignment_turned_in_outlined,
                            title: _filter == 'today' ? 'Nothing scheduled today' : (_filter == 'upcoming' ? 'No upcoming assignments' : 'No past assignments on this phone'),
                            message: 'Pull down to check for new work.',
                          ),
                        ),
                      ])
                    : ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: list.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (c, i) => AssignmentCard(a: list[i], visit: byAssignment[list[i].id]),
                      ),
              );
            },
          ),
        ),
      ]),
    );
  }
}

class AssignmentCard extends StatelessWidget {
  const AssignmentCard({super.key, required this.a, required this.visit});
  final AssignmentView a;
  final LocalVisit? visit;

  @override
  Widget build(BuildContext context) {
    final status = a.isCancelled ? 'cancelled' : (visit?.status ?? a.status);
    return AppCard(
      onTap: () => context.push('/assignment/${a.id}'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(a.locationName, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
          StatusChip(status),
        ]),
        const SizedBox(height: 4),
        Text(a.campaignName, style: const TextStyle(color: Brand.muted)),
        const SizedBox(height: 8),
        Wrap(spacing: 14, runSpacing: 4, children: [
          _Meta(Icons.event, fmtDate(a.scheduledDate)),
          if (a.startTime != null) _Meta(Icons.schedule, '${fmtTime(a.startTime)}${a.endTime != null ? ' - ${fmtTime(a.endTime)}' : ''}'),
          if (a.town != null) _Meta(Icons.place_outlined, a.town!),
          if (a.activityName != null) _Meta(Icons.bolt, a.activityName!),
        ]),
        if (a.removed) ...[
          const SizedBox(height: 8),
          const Text('This assignment was changed on the server. Your saved work is kept.', style: TextStyle(color: Brand.warn, fontWeight: FontWeight.w600, fontSize: 12.5)),
        ],
      ]),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta(this.icon, this.text);
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 15, color: Brand.muted),
        const SizedBox(width: 4),
        Text(text, style: const TextStyle(fontSize: 12.5, color: Brand.muted)),
      ]);
}
