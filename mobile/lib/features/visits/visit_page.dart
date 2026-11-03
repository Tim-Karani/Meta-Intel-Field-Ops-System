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
import '../common/refused_banner.dart';
import '../common/widgets.dart';
import '../issues/issues_page.dart';
import 'photo_capture.dart';
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
        final rules = a?.rules ?? const ProjectRules();
        final gps = await ref.read(locationServiceProvider).current();

        // Hard rules. The server enforces the same ones; stopping here saves the agent a refused check-in later.
        if (gps.mocked) {
          throw LocationException('Your phone is using a fake (mock) location, so you cannot check in. Turn off any location-spoofing app and the developer "mock location" setting, then try again. Your supervisor is told when this happens.');
        }
        if (a != null && a.isUnmapped) {
          // This check-in will become the outlet's saved position, so it has to be a clean, accurate fix.
          final acc = gps.accuracyM;
          if (acc == null || acc > AppConfig.captureMaxAccuracyM) {
            throw LocationException('GPS accuracy is ${acc == null ? 'unknown' : '${acc.round()} m'}; ${AppConfig.captureMaxAccuracyM.round()} m or better is needed to save this outlet\'s position. Step outside or move to open sky, wait a few seconds and try again.');
          }
        } else if (a?.lat != null && a?.lng != null) {
          final d = distanceMeters(gps.latitude, gps.longitude, a!.lat!, a.lng!);
          final credit = (gps.accuracyM ?? 0) < AppConfig.checkinCreditM ? (gps.accuracyM ?? 0) : AppConfig.checkinCreditM;
          if (d - credit > AppConfig.checkinMaxM) {
            throw LocationException('You are about ${fmtDistance(d)} from ${a.locationName}. You can only check in within ${AppConfig.checkinMaxM.round()} m of the outlet. Walk to the outlet and try again.');
          }
        }

        // The check-in photo (a selfie at the outlet) proves who is servicing it. No photo, no check-in.
        CapturedPhoto? proof;
        if (rules.visitPhotos) {
          if (!mounted) return;
          proof = await takeStampedPhoto(context, ref, v, front: true, title: 'CHECK-IN');
          if (proof == null) {
            if (mounted) toast(context, 'A check-in photo is required. Take a photo of yourself at the outlet to check in.', error: true);
            return;
          }
        }
        final repo = ref.read(repoProvider);
        await repo.checkIn(v, gps);
        if (proof != null) await repo.addPhoto(v.localId, proof.file, kind: 'checkin', gps: proof.gps ?? gps);
        if (mounted) toast(context, 'Checked in. Saved on this phone${ref.read(syncStateProvider).valueOrNull?.online == false ? ' - will sync when online' : ''}.');
      });

  Future<void> _checkOut(LocalVisit v, AssignmentView? a) => _guard(() async {
        final loc = ref.read(locationServiceProvider);
        GpsReading? fix;
        try {
          fix = await loc.current();
        } on LocationException {
          fix = await loc.lastKnown();
        }
        if (fix == null) throw LocationException('Could not get a GPS fix to check out. Move to an open area and try again.');
        CapturedPhoto? proof;
        if ((a?.rules ?? const ProjectRules()).visitPhotos) {
          if (!mounted) return;
          proof = await takeStampedPhoto(context, ref, v, front: true, title: 'CHECK-OUT');
          if (proof == null) {
            if (mounted) toast(context, 'A check-out photo is required to close the outlet.', error: true);
            return;
          }
        }
        final repo = ref.read(repoProvider);
        await repo.checkOut(v, fix);
        if (proof != null) await repo.addPhoto(v.localId, proof.file, kind: 'checkout', gps: proof.gps ?? fix);
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
        if (mounted) toast(context, 'Submitted on this phone. It is not at the server until the banner on this visit says "Received by the server".');
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
    final stuck = ref.watch(stuckOpsProvider(v.localId)).valueOrNull ?? const [];
    final locked = stuck.any((o) => foundationOps.contains(o.type));
    final checkedIn = v.status != 'scheduled' && !locked;
    final editable = v.status == 'started' || v.status == 'completed';

    final rules = a?.rules ?? const ProjectRules();
    final label = rules.visitLabel.toLowerCase();
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
        if (stuck.isNotEmpty) ...[
          ClipRRect(borderRadius: BorderRadius.circular(14), child: RefusedBanner(visitId: v.localId, ops: stuck)),
          const SizedBox(height: 10),
        ],
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
        if (v.status == 'scheduled' && !(a != null && a.isUnmapped)) ...[
          const SizedBox(height: 10),
          AppCard(
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Icon(Icons.pin_drop_outlined, color: Brand.ink),
              const SizedBox(width: 10),
              Expanded(
                  child: Text(
                      'You can only check in within ${AppConfig.checkinMaxM.round()} m of the outlet${rules.visitPhotos ? ', with a photo of yourself' : ''}. Your working time starts at your first outlet check-in, not when you clock in.',
                      style: const TextStyle(fontWeight: FontWeight.w600))),
            ]),
          ),
        ],
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
            onPressed: (_busy || locked) ? null : () => _checkIn(v, a),
            icon: _busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.my_location),
            label: Text(a != null && a.isUnmapped ? 'Start outlet & save its location' : (rules.visitPhotos ? 'Take check-in photo & start $label' : 'Check in & start $label')),
          )
        else
          AppCard(child: Row(children: [
            const Icon(Icons.check_circle, color: Brand.good),
            const SizedBox(width: 10),
            Expanded(child: Text('Checked in ${fmtDateTime(v.checkin?['device_ts']?.toString())}', style: const TextStyle(fontWeight: FontWeight.w700))),
          ])),
        const SectionTitle('2 - Do the work'),
        if (rules.taskOn('form')) task(Icons.fact_check_outlined, form?.name ?? 'Visit form', form == null ? 'No form for this $label' : formStats, 'form', enabled: checkedIn && form != null),
        if (rules.taskOn('sales')) task(Icons.attach_money, 'Sales', '$sales recorded', 'sales', enabled: checkedIn),
        if (rules.taskOn('osa')) task(Icons.inventory_2_outlined, 'On-shelf availability', '$osa product${osa == 1 ? '' : 's'} checked', 'osa', enabled: checkedIn),
        if (rules.taskOn('merchandising')) task(Icons.view_in_ar_outlined, 'Merchandising', '$merch record${merch == 1 ? '' : 's'}', 'merch', enabled: checkedIn),
        if (rules.taskOn('photos') || rules.minPhotos > 0)
          task(Icons.photo_camera_outlined, 'Photos & evidence',
              '${photos.where((p) => (p['kind'] ?? 'evidence') == 'evidence').length}${rules.minPhotos > 0 ? ' of ${rules.minPhotos} needed' : ''} photo${photos.length == 1 && rules.minPhotos == 0 ? '' : 's'} - camera only, time-stamped', 'photos', enabled: checkedIn),
        if (editable)
          OutlinedButton.icon(
            onPressed: () => showIssueSheet(context, ref, visit: v),
            icon: const Icon(Icons.report_problem_outlined),
            label: const Text('Report an issue at this store'),
          ),
        const SectionTitle('3 - Finish'),
        if (v.status == 'started')
          FilledButton.icon(onPressed: _busy ? null : () => _checkOut(v, a), icon: const Icon(Icons.logout), label: Text(rules.visitPhotos ? 'Check-out photo & close outlet' : 'Check out'))
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
        if (!snap.hasData) return const SizedBox.shrink();
        final n = snap.data ?? 0;
        final stuck = ref.watch(stuckOpsProvider(entityId)).valueOrNull?.length ?? 0;
        final received = n == 0 && serverId != null;
        final color = stuck > 0 ? Brand.bad : (n > 0 ? Brand.warn : Brand.good);
        final icon = stuck > 0 ? Icons.error_outline : (n > 0 ? Icons.cloud_upload_outlined : (received ? Icons.cloud_done_outlined : Icons.smartphone_outlined));
        final text = stuck > 0
            ? '$stuck item${stuck == 1 ? '' : 's'} refused by the server'
            : n > 0
                ? 'Saved on this phone - $n change${n == 1 ? '' : 's'} not at the server yet'
                : (received ? 'Received by the server' : 'Saved on this phone only');
        return Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(children: [
            Icon(icon, size: 16, color: color),
            const SizedBox(width: 6),
            Expanded(child: Text(text, style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12.5))),
          ]),
        );
      },
    );
  }
}
