import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../core/config.dart';
import '../../core/location_service.dart';
import '../../core/util.dart';
import '../../models/form_def.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';
import '../issues/issues_page.dart';
import '../work/assignment_detail_page.dart';

/// The workspace for one visit: check in, do the tasks, check out, submit - all against the local database.
class VisitPage extends ConsumerStatefulWidget {
  const VisitPage({super.key, required this.visitId});
  final String visitId;

  @override
  ConsumerState<VisitPage> createState() => _VisitPageState();
}

class _VisitPageState extends ConsumerState<VisitPage> {
  bool _busy = false;

  Future<void> _guard(Future<void> Function() job) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await job();
    } on LocationException catch (e) {
      if (mounted) toast(context, e.message, error: true);
    } catch (e) {
      if (mounted) toast(context, '$e', error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _checkIn(LocalVisit v, AssignmentView? a) => _guard(() async {
        final gps = await ref.read(locationServiceProvider).current();
        if (a != null && a.isUnmapped) {
          // This check-in will become the outlet's saved position, so it has to be a clean, accurate fix. Refusing here
          // is better than queueing a check-in the server would reject later, when the agent has already left.
          if (gps.mocked) throw LocationException('A simulated location cannot be used to map a new outlet. Turn off mock locations and try again.');
          final acc = gps.accuracyM;
          if (acc == null || acc > AppConfig.captureMaxAccuracyM) {
            throw LocationException('GPS accuracy is ${acc == null ? 'unknown' : '${acc.round()} m'}; ${AppConfig.captureMaxAccuracyM.round()} m or better is needed to save this outlet\'s position. Step outside or move to open sky, wait a few seconds and try again.');
          }
        }
        if (a?.lat != null && a?.lng != null) {
          final d = distanceMeters(gps.latitude, gps.longitude, a!.lat!, a.lng!);
          if (d > a.radius + (gps.accuracyM ?? 0)) {
            if (!mounted) return;
            final go = await confirm(context,
                title: 'You look far from the store',
                message: 'You are about ${fmtDistance(d)} away; the allowed radius is ${a.radius} m. The server will decide, and a check-in outside the radius may be rejected or flagged for review. Check in anyway?',
                yes: 'Check in anyway');
            if (!go) return;
          }
        }
        if (gps.mocked) {
          if (!mounted) return;
          final go = await confirm(context,
              title: 'Mock location detected', message: 'This phone reports a simulated location. The check-in will be flagged for review. Continue?', yes: 'Continue', danger: true);
          if (!go) return;
        }
        await ref.read(repoProvider).checkIn(v, gps);
        if (mounted) toast(context, 'Checked in. Saved on this phone${ref.read(syncStateProvider).valueOrNull?.online == false ? ' - will sync when online' : ''}.');
      });

  Future<void> _checkOut(LocalVisit v) => _guard(() async {
        final loc = ref.read(locationServiceProvider);
        GpsReading? fix;
        try {
          fix = await loc.current();
        } on LocationException {
          fix = await loc.lastKnown();
        }
        if (fix == null) throw LocationException('Could not get a GPS fix to check out. Move to an open area and try again.');
        await ref.read(repoProvider).checkOut(v, fix);
        if (mounted) toast(context, 'Checked out.');
      });

  Future<void> _submit(LocalVisit v) => _guard(() async {
        final blockers = await ref.read(repoProvider).submitBlockers(v);
        if (!mounted) return;
        if (blockers.isNotEmpty) {
          await showDialog<void>(
            context: context,
            builder: (c) => AlertDialog(
              title: const Text('Not ready to submit'),
              content: SingleChildScrollView(
                child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
                  for (final b in blockers) Padding(padding: const EdgeInsets.only(bottom: 6), child: Text('- $b')),
                ]),
              ),
              actions: [TextButton(onPressed: () => Navigator.pop(c), child: const Text('OK'))],
            ),
          );
          return;
        }
        final go = await confirm(context,
            title: 'Submit this visit?', message: 'After submitting you cannot change it. It goes to your supervisor for review.', yes: 'Submit');
        if (!go) return;
        await ref.read(repoProvider).submitVisit(v);
        if (mounted) toast(context, 'Submitted. It will reach your supervisor when you are online.');
      });

  @override
  Widget build(BuildContext context) {
    final vAsync = ref.watch(visitProvider(widget.visitId));
    return vAsync.when(
      loading: () => Scaffold(appBar: AppBar(title: const Text('Visit')), body: const Center(child: CircularProgressIndicator())),
      error: (e, _) => Scaffold(appBar: AppBar(title: const Text('Visit')), body: EmptyState(icon: Icons.error_outline, title: 'Could not open the visit', message: '$e')),
      data: (v) {
        if (v == null) return Scaffold(appBar: AppBar(title: const Text('Visit')), body: const EmptyState(icon: Icons.search_off, title: 'Visit not found'));
        return _body(context, v);
      },
    );
  }

  Widget _body(BuildContext context, LocalVisit v) {
    final a = ref.watch(assignmentProvider(v.assignmentId)).valueOrNull;
    final form = ref.watch(formProvider(v.formId)).valueOrNull;
    final sales = ref.watch(itemsProvider((visitId: v.localId, kind: 'sale'))).valueOrNull?.length ?? 0;
    final osa = ref.watch(itemsProvider((visitId: v.localId, kind: 'osa'))).valueOrNull?.length ?? 0;
    final merch = ref.watch(itemsProvider((visitId: v.localId, kind: 'merch'))).valueOrNull?.length ?? 0;
    final photos = ref.watch(photosProvider(v.localId)).valueOrNull ?? const <Map<String, Object?>>[];
    final checkedIn = v.status != 'scheduled';
    final editable = v.status == 'started' || v.status == 'completed';

    final formStats = _formStats(form, v, photos);

    Widget task(IconData icon, String title, String subtitle, String route, {bool enabled = true}) => Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: AppCard(
            onTap: enabled ? () => context.push('/visit/${v.localId}/$route') : null,
            child: Opacity(
              opacity: enabled ? 1 : .5,
              child: Row(children: [
                CircleAvatar(backgroundColor: Brand.saffronSoft, child: Icon(icon, color: Brand.ink)),
                const SizedBox(width: 12),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
                  Text(subtitle, style: const TextStyle(color: Brand.muted, fontSize: 13)),
                ])),
                const Icon(Icons.chevron_right),
              ]),
            ),
          ),
        );

    return Scaffold(
      appBar: AppBar(title: Text(a?.locationName ?? 'Visit')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        AppCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(a?.campaignName ?? 'Visit', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16))),
              StatusChip(v.status),
            ]),
            if (a != null) ...[
              const SizedBox(height: 6),
              Text('${a.locationName}${a.town != null ? ', ${a.town}' : ''}', style: const TextStyle(color: Brand.muted)),
              if (a.lat != null && a.lng != null)
                TextButton.icon(onPressed: () => openMapsTo(context, a.lat!, a.lng!), icon: const Icon(Icons.navigation_outlined, size: 18), label: const Text('Navigate to the store')),
            ],
            _SyncNote(entityId: v.localId, serverId: v.serverId),
          ]),
        ),
        if (a != null && a.isUnmapped && v.status == 'scheduled') ...[
          const SizedBox(height: 10),
          AppCard(
            color: Brand.saffronSoft,
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Icon(Icons.add_location_alt_outlined, color: Brand.ink),
              const SizedBox(width: 10),
              const Expanded(
                  child: Text('This outlet has no saved location yet. Stand at the outlet, then check in: your position becomes the outlet\'s location (your supervisor will confirm it).',
                      style: TextStyle(fontWeight: FontWeight.w600))),
            ]),
          ),
        ],
        if (v.isRejected && (v.reviewNote ?? '').isNotEmpty) ...[
          const SizedBox(height: 10),
          AppCard(color: const Color(0xFFFDECEE), child: Text('Rejected: ${v.reviewNote}', style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w700))),
        ],
        if (v.status == 'approved' && (v.reviewNote ?? '').isNotEmpty) ...[
          const SizedBox(height: 10),
          AppCard(color: const Color(0xFFE6F5EE), child: Text('Approved: ${v.reviewNote}', style: const TextStyle(color: Brand.good, fontWeight: FontWeight.w700))),
        ],
        const SectionTitle('1 - Arrive'),
        if (v.status == 'scheduled')
          FilledButton.icon(
            onPressed: _busy ? null : () => _checkIn(v, a),
            icon: _busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.my_location),
            label: Text(a != null && a.isUnmapped ? 'Start outlet & save its location' : 'Check in at the store'),
          )
        else
          AppCard(child: Row(children: [
            const Icon(Icons.check_circle, color: Brand.good),
            const SizedBox(width: 10),
            Expanded(child: Text('Checked in ${fmtDateTime(v.checkin?['device_ts']?.toString())}', style: const TextStyle(fontWeight: FontWeight.w700))),
          ])),
        const SectionTitle('2 - Do the work'),
        task(Icons.fact_check_outlined, form?.name ?? 'Visit form', form == null ? 'No form for this visit' : formStats, 'form', enabled: checkedIn && form != null),
        task(Icons.attach_money, 'Sales', '$sales recorded', 'sales', enabled: checkedIn),
        task(Icons.inventory_2_outlined, 'On-shelf availability', '$osa product${osa == 1 ? '' : 's'} checked', 'osa', enabled: checkedIn),
        task(Icons.view_in_ar_outlined, 'Merchandising', '$merch record${merch == 1 ? '' : 's'}', 'merch', enabled: checkedIn),
        task(Icons.photo_camera_outlined, 'Photos & evidence', '${photos.length} photo${photos.length == 1 ? '' : 's'}', 'photos', enabled: checkedIn),
        if (editable)
          OutlinedButton.icon(
            onPressed: () => showIssueSheet(context, ref, visit: v),
            icon: const Icon(Icons.report_problem_outlined),
            label: const Text('Report an issue at this store'),
          ),
        const SectionTitle('3 - Finish'),
        if (v.status == 'started')
          FilledButton.icon(onPressed: _busy ? null : () => _checkOut(v), icon: const Icon(Icons.logout), label: const Text('Check out'))
        else if (v.status == 'completed')
          Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            AppCard(child: Row(children: [
              const Icon(Icons.check_circle, color: Brand.good),
              const SizedBox(width: 10),
              Expanded(child: Text('Checked out ${fmtDateTime(v.checkout?['device_ts']?.toString())}', style: const TextStyle(fontWeight: FontWeight.w700))),
            ])),
            const SizedBox(height: 10),
            FilledButton.icon(onPressed: _busy ? null : () => _submit(v), icon: const Icon(Icons.send_rounded), label: const Text('Review & submit')),
          ])
        else if (v.status == 'scheduled')
          const AppCard(child: Text('Check in first, then complete the tasks.', style: TextStyle(color: Brand.muted)))
        else
          AppCard(child: Text('This visit is ${v.statusLabel.toLowerCase()}. It can no longer be edited.', style: const TextStyle(fontWeight: FontWeight.w600))),
        const SizedBox(height: 24),
      ]),
    );
  }

  String _formStats(FormDef? form, LocalVisit v, List<Map<String, Object?>> photos) {
    if (form == null) return '';
    final counts = <String, int>{};
    for (final p in photos) {
      final q = p['question_id'] as String?;
      if (q != null) counts[q] = (counts[q] ?? 0) + 1;
    }
    final problems = form.problems(v.answers, counts);
    final vis = form.visibility(v.answers);
    final requiredVisible = form.questions.where((q) => q.required && vis[q.id] == true).length;
    final missing = problems.length;
    if (missing == 0) return requiredVisible == 0 ? 'Optional' : 'Complete';
    return '$missing item${missing == 1 ? '' : 's'} to finish';
  }
}

class _SyncNote extends ConsumerWidget {
  const _SyncNote({required this.entityId, required this.serverId});
  final String entityId;
  final String? serverId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    ref.watch(revisionProvider);
    return FutureBuilder<int>(
      future: ref.read(repoProvider).unsyncedFor(entityId),
      builder: (c, snap) {
        final n = snap.data ?? 0;
        if (n == 0) return const SizedBox.shrink();
        return Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(children: [
            const Icon(Icons.cloud_upload_outlined, size: 16, color: Brand.warn),
            const SizedBox(width: 6),
            Text('$n change${n == 1 ? '' : 's'} waiting to sync', style: const TextStyle(color: Brand.warn, fontWeight: FontWeight.w700, fontSize: 12.5)),
          ]),
        );
      },
    );
  }
}
