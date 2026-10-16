import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

/// Today's stops in visiting order, with one-tap navigation to each stop or to the whole route.
class RoutesPage extends ConsumerWidget {
  const RoutesPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final today = todayStr();
    final all = ref.watch(assignmentsProvider).valueOrNull ?? const <AssignmentView>[];
    final visits = ref.watch(visitsProvider).valueOrNull ?? const <LocalVisit>[];
    final byAssignment = <String, LocalVisit>{for (final v in visits) v.assignmentId: v};
    final stops = all.where((a) => a.scheduledDate == today && !a.isCancelled && !a.removed).toList();

    bool done(AssignmentView a) => a.isDone || (byAssignment[a.id] != null && !byAssignment[a.id]!.isEditable);
    final remaining = stops.where((a) => !done(a) && a.lat != null && a.lng != null).toList();

    return Scaffold(
      appBar: AppBar(title: const Text("Today's route")),
      body: stops.isEmpty
          ? const EmptyState(icon: Icons.alt_route, title: 'No stops today', message: 'Your route appears here when work is scheduled.')
          : ListView(padding: const EdgeInsets.all(16), children: [
              if (remaining.isNotEmpty)
                FilledButton.icon(
                  onPressed: () => _openWholeRoute(context, remaining),
                  icon: const Icon(Icons.map_outlined),
                  label: Text('Navigate remaining ${remaining.length} stop${remaining.length == 1 ? '' : 's'}'),
                ),
              const SizedBox(height: 14),
              for (var i = 0; i < stops.length; i++)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: AppCard(
                    onTap: () => context.push('/assignment/${stops[i].id}'),
                    child: Row(children: [
                      CircleAvatar(
                        radius: 16,
                        backgroundColor: done(stops[i]) ? Brand.good : Brand.saffron,
                        child: done(stops[i]) ? const Icon(Icons.check, size: 18, color: Colors.white) : Text('${i + 1}', style: const TextStyle(color: Brand.ink, fontWeight: FontWeight.w800)),
                      ),
                      const SizedBox(width: 12),
                      Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(stops[i].locationName, style: const TextStyle(fontWeight: FontWeight.w800)),
                        Text('${stops[i].town ?? ''}${stops[i].startTime != null ? '  -  ${fmtTime(stops[i].startTime)}' : ''}', style: const TextStyle(color: Brand.muted, fontSize: 13)),
                      ])),
                      if (stops[i].lat != null && stops[i].lng != null)
                        IconButton(
                          tooltip: 'Navigate',
                          icon: const Icon(Icons.navigation_outlined),
                          onPressed: () => _openOne(context, stops[i].lat!, stops[i].lng!),
                        ),
                    ]),
                  ),
                ),
            ]),
    );
  }

  Future<void> _openOne(BuildContext context, double lat, double lng) async {
    final ok = await launchUrl(Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$lat,$lng&travelmode=driving'), mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) toast(context, 'No maps app could be opened.', error: true);
  }

  /// Google Maps accepts up to 9 waypoints; longer routes are cut to the first 10 remaining stops.
  Future<void> _openWholeRoute(BuildContext context, List<AssignmentView> stops) async {
    final use = stops.take(10).toList();
    final last = use.last;
    final via = use.take(use.length - 1).map((s) => '${s.lat},${s.lng}').join('|');
    final uri = Uri.parse('https://www.google.com/maps/dir/?api=1&destination=${last.lat},${last.lng}${via.isEmpty ? '' : '&waypoints=${Uri.encodeComponent(via)}'}&travelmode=driving');
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) toast(context, 'No maps app could be opened.', error: true);
  }
}
