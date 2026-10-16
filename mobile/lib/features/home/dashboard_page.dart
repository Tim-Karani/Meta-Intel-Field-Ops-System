import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../data/sync_service.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

/// Today at a glance: assignments, progress, attendance, sync status and what needs attention.
class DashboardPage extends ConsumerWidget {
  const DashboardPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final today = todayStr();
    final assignments = ref.watch(assignmentsProvider).valueOrNull ?? const <AssignmentView>[];
    final visits = ref.watch(visitsProvider).valueOrNull ?? const <LocalVisit>[];
    final attendance = ref.watch(attendanceTodayProvider).valueOrNull;
    final sync = ref.watch(syncStateProvider).valueOrNull ?? const SyncState();
    final lastSync = ref.watch(lastSyncProvider).valueOrNull;
    final name = ref.watch(sessionProvider).userName.split(' ').first;

    final active = assignments.where((a) => !a.isCancelled && !a.removed).toList();
    final todays = active.where((a) => a.scheduledDate == today).toList();
    final byAssignment = <String, LocalVisit>{for (final v in visits) v.assignmentId: v};
    bool doneFor(AssignmentView a) {
      final v = byAssignment[a.id];
      return a.isDone || (v != null && !v.isEditable);
    }

    final completed = todays.where(doneFor).length;
    final inProgress = todays.where((a) => !doneFor(a) && (byAssignment[a.id]?.status == 'started' || byAssignment[a.id]?.status == 'completed')).length;
    final pending = todays.length - completed - inProgress;
    final overdue = active.where((a) => a.scheduledDate.compareTo(today) < 0 && !doneFor(a)).toList();
    final rejected = visits.where((v) => v.isRejected).toList();
    final clockedIn = attendance?['clock_in'] != null;
    final clockedOut = attendance?['clock_out'] != null;
    final progress = todays.isEmpty ? 0.0 : completed / todays.length;

    return Scaffold(
      appBar: AppBar(
        title: Text('Hello, ${name.isEmpty ? 'there' : name}'),
        actions: [
          IconButton(
            tooltip: 'Sync now',
            onPressed: sync.running ? null : () => ref.read(syncServiceProvider).run(manual: true),
            icon: sync.running ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.sync),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.read(syncServiceProvider).run(manual: true),
        child: ListView(padding: const EdgeInsets.all(16), children: [
          AppCard(
            color: Brand.ink,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text("Today's work - ${fmtDate(today)}", style: const TextStyle(color: Colors.white70, fontWeight: FontWeight.w600)),
              const SizedBox(height: 8),
              Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text('$completed', style: const TextStyle(color: Brand.saffron, fontSize: 40, fontWeight: FontWeight.w800, height: 1)),
                Padding(padding: const EdgeInsets.only(left: 8, bottom: 4), child: Text('of ${todays.length} completed', style: const TextStyle(color: Colors.white, fontSize: 16))),
              ]),
              const SizedBox(height: 12),
              ClipRRect(
                borderRadius: BorderRadius.circular(6),
                child: LinearProgressIndicator(value: progress, minHeight: 8, backgroundColor: Colors.white24, valueColor: const AlwaysStoppedAnimation(Brand.saffron)),
              ),
            ]),
          ),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: _Stat('In progress', '$inProgress', Brand.warn)),
            const SizedBox(width: 10),
            Expanded(child: _Stat('To do', '${pending < 0 ? 0 : pending}', Brand.ink)),
            const SizedBox(width: 10),
            Expanded(child: _Stat('Overdue', '${overdue.length}', overdue.isEmpty ? Brand.muted : Brand.bad)),
          ]),
          const SectionTitle('Attendance'),
          AppCard(
            onTap: () => context.push('/attendance'),
            child: Row(children: [
              Icon(clockedOut ? Icons.check_circle_outline : (clockedIn ? Icons.timer_outlined : Icons.login), color: clockedIn ? Brand.good : Brand.saffron, size: 28),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(clockedOut ? 'Day finished' : (clockedIn ? 'Clocked in' : 'Not clocked in yet'), style: const TextStyle(fontWeight: FontWeight.w800)),
                Text(clockedIn ? 'Tap to clock out or review' : 'Tap to clock in', style: const TextStyle(color: Brand.muted)),
              ])),
              const Icon(Icons.chevron_right),
            ]),
          ),
          if (rejected.isNotEmpty || overdue.isNotEmpty || sync.stuck > 0) ...[
            const SectionTitle('Needs your attention'),
            if (rejected.isNotEmpty)
              _Attention(icon: Icons.thumb_down_alt_outlined, text: '${rejected.length} visit${rejected.length == 1 ? '' : 's'} rejected by your supervisor', onTap: () => context.go('/visits'), color: Brand.bad),
            if (overdue.isNotEmpty)
              _Attention(icon: Icons.schedule, text: '${overdue.length} overdue assignment${overdue.length == 1 ? '' : 's'}', onTap: () => context.go('/work'), color: Brand.warn),
            if (sync.stuck > 0)
              _Attention(icon: Icons.sync_problem, text: '${sync.stuck} item${sync.stuck == 1 ? '' : 's'} could not sync', onTap: () => context.go('/sync'), color: Brand.bad),
          ],
          const SectionTitle("Today's stops"),
          if (todays.isEmpty)
            const AppCard(child: Text('Nothing is scheduled for today.', style: TextStyle(color: Brand.muted)))
          else
            for (final a in todays) Padding(padding: const EdgeInsets.only(bottom: 10), child: _StopTile(a: a, visit: byAssignment[a.id])),
          const SectionTitle('Sync'),
          AppCard(
            onTap: () => context.go('/sync'),
            child: Row(children: [
              Icon(sync.online ? Icons.cloud_done_outlined : Icons.cloud_off_outlined, color: sync.online ? Brand.good : Brand.warn),
              const SizedBox(width: 12),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(sync.online ? 'Online' : 'Offline', style: const TextStyle(fontWeight: FontWeight.w800)),
                Text('${sync.pending} waiting - last sync ${relative(lastSync)}', style: const TextStyle(color: Brand.muted)),
              ])),
              const Icon(Icons.chevron_right),
            ]),
          ),
        ]),
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat(this.label, this.value, this.color);
  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) => AppCard(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(value, style: TextStyle(color: color, fontSize: 26, fontWeight: FontWeight.w800)),
          Text(label, style: const TextStyle(color: Brand.muted, fontSize: 12.5)),
        ]),
      );
}

class _Attention extends StatelessWidget {
  const _Attention({required this.icon, required this.text, required this.onTap, required this.color});
  final IconData icon;
  final String text;
  final VoidCallback onTap;
  final Color color;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: AppCard(
          onTap: onTap,
          child: Row(children: [
            Icon(icon, color: color),
            const SizedBox(width: 12),
            Expanded(child: Text(text, style: const TextStyle(fontWeight: FontWeight.w700))),
            const Icon(Icons.chevron_right),
          ]),
        ),
      );
}

class _StopTile extends StatelessWidget {
  const _StopTile({required this.a, required this.visit});
  final AssignmentView a;
  final LocalVisit? visit;

  @override
  Widget build(BuildContext context) {
    final status = visit?.status ?? a.status;
    return AppCard(
      onTap: () => context.push('/assignment/${a.id}'),
      child: Row(children: [
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(a.locationName, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
          const SizedBox(height: 2),
          Text('${a.campaignName}${a.startTime != null ? '  -  ${fmtTime(a.startTime)}' : ''}', style: const TextStyle(color: Brand.muted, fontSize: 13)),
        ])),
        StatusChip(status),
      ]),
    );
  }
}
