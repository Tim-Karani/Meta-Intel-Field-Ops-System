import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../app/theme.dart';
import '../../core/api_exception.dart';
import '../../core/config.dart';
import '../../core/util.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

const _days = {1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday'};

/// Ask your supervisor to add, remove or move an outlet in your journey plan. The request goes straight to the server
/// (it needs a connection); an approval changes your route at once.
class JpRequestPage extends ConsumerStatefulWidget {
  const JpRequestPage({super.key});

  @override
  ConsumerState<JpRequestPage> createState() => _JpRequestPageState();
}

class _JpRequestPageState extends ConsumerState<JpRequestPage> {
  List<Map<String, dynamic>>? _plans;
  String? _loadError;
  bool _sending = false;

  Map<String, dynamic>? _plan;
  String _type = 'move';
  int _weekday = 1;
  int _toWeekday = 2;
  String? _locationId;
  final _reason = TextEditingController();
  XFile? _photo;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _plans = null;
      _loadError = null;
    });
    try {
      final r = await ref.read(sessionProvider).api.get('jp-requests/mine');
      final list = (r is List ? r : const []).whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
      if (!mounted) return;
      setState(() {
        _plans = list;
        _plan = list.isEmpty ? null : list.first;
        _locationId = null;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _loadError = e.kind == ApiErrorKind.network ? 'You need a connection to ask for a plan change. ${e.message}' : e.display);
    } catch (e) {
      if (mounted) setState(() => _loadError = '$e');
    }
  }

  List<Map<String, dynamic>> _choices() {
    final p = _plan;
    if (p == null) return const [];
    if (_type == 'add') {
      return ((p['outlets'] as List?) ?? const []).whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
    }
    return ((p['stops'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => e.cast<String, dynamic>())
        .where((s) => s['weekday'] == _weekday)
        .map((s) => <String, dynamic>{'id': s['location_id'], 'name': s['name'], 'town': s['town']})
        .toList();
  }

  Future<void> _pickPhoto() async {
    try {
      final x = await ImagePicker().pickImage(source: ImageSource.camera, maxWidth: AppConfig.photoMaxEdge, maxHeight: AppConfig.photoMaxEdge, imageQuality: AppConfig.photoQuality);
      if (x != null && mounted) setState(() => _photo = x);
    } catch (_) {
      if (mounted) toast(context, 'Could not open the camera.');
    }
  }

  Future<void> _send() async {
    final plan = _plan;
    if (plan == null || _locationId == null) {
      toast(context, 'Choose the outlet first.');
      return;
    }
    if (_reason.text.trim().length < 5) {
      toast(context, 'Write a short reason (at least 5 characters).');
      return;
    }
    if (_type == 'move' && _toWeekday == _weekday) {
      toast(context, 'Choose a different day to move it to.');
      return;
    }
    setState(() => _sending = true);
    final fields = <String, Object?>{
      'client_uuid': newId(),
      'journey_plan_id': plan['id'],
      'type': _type,
      'location_id': _locationId,
      'weekday': _weekday,
      if (_type == 'move') 'to_weekday': _toWeekday,
      'reason': _reason.text.trim(),
    };
    try {
      // Where the agent is is part of the proof. It is optional: a failed fix does not block the request.
      try {
        final g = await ref.read(locationServiceProvider).current();
        fields['latitude'] = g.latitude;
        fields['longitude'] = g.longitude;
        if (g.accuracyM != null) fields['accuracy_m'] = g.accuracyM;
      } catch (_) {/* sent without a position */}
      final api = ref.read(sessionProvider).api;
      if (_photo != null) {
        await api.upload('jp-requests', filePath: _photo!.path, fileName: File(_photo!.path).uri.pathSegments.last, fields: fields, fileField: 'photo');
      } else {
        await api.post('jp-requests', body: fields);
      }
      if (!mounted) return;
      toast(context, 'Request sent to your supervisor.');
      context.pop();
    } on ApiException catch (e) {
      if (mounted) {
        setState(() => _sending = false);
        toast(context, e.kind == ApiErrorKind.network ? 'No connection. Connect and try again - nothing was sent.' : e.display);
      }
    } catch (e) {
      if (mounted) {
        setState(() => _sending = false);
        toast(context, '$e');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final plans = _plans;
    return Scaffold(
      appBar: AppBar(title: const Text('Journey plan change')),
      body: _loadError != null
          ? Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              EmptyState(icon: Icons.cloud_off_outlined, title: 'Could not load your plan', message: _loadError),
              FilledButton.icon(onPressed: _load, icon: const Icon(Icons.refresh), label: const Text('Try again')),
            ])
          : plans == null
              ? const Center(child: CircularProgressIndicator())
              : plans.isEmpty
                  ? const EmptyState(icon: Icons.event_busy_outlined, title: 'No active journey plan', message: 'Your supervisor has not given you a fixed plan yet, so there is nothing to change.')
                  : _form(plans),
    );
  }

  Widget _form(List<Map<String, dynamic>> plans) {
    final choices = _choices();
    if (_locationId != null && !choices.any((c) => c['id'] == _locationId)) _locationId = null;
    return ListView(padding: const EdgeInsets.all(16), children: [
      const Text('Your supervisor reviews the request. If approved, your route changes straight away.', style: TextStyle(color: Brand.muted)),
      if (plans.length > 1) ...[
        const SectionTitle('Plan'),
        DropdownButtonFormField<String>(
          value: _plan?['id'] as String?,
          items: [for (final p in plans) DropdownMenuItem(value: p['id'] as String, child: Text('${p['name']}  -  ${(p['campaign'] as Map?)?['name'] ?? ''}'))],
          onChanged: (v) => setState(() {
            _plan = plans.firstWhere((p) => p['id'] == v);
            _locationId = null;
          }),
        ),
      ],
      const SectionTitle('What do you want to do?'),
      SizedBox(
        width: double.infinity,
        child: SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'move', label: Text('Move')),
            ButtonSegment(value: 'remove', label: Text('Remove')),
            ButtonSegment(value: 'add', label: Text('Add')),
          ],
          selected: {_type},
          showSelectedIcon: false,
          onSelectionChanged: (s) => setState(() {
            _type = s.first;
            _locationId = null;
          }),
        ),
      ),
      SectionTitle(_type == 'add' ? 'Day to add it' : 'Day it is on now'),
      DropdownButtonFormField<int>(
        value: _weekday,
        items: [for (final e in _days.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
        onChanged: (v) => setState(() {
          _weekday = v ?? 1;
          _locationId = null;
        }),
      ),
      if (_type == 'move') ...[
        const SectionTitle('Move it to'),
        DropdownButtonFormField<int>(
          value: _toWeekday,
          items: [for (final e in _days.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
          onChanged: (v) => setState(() => _toWeekday = v ?? 2),
        ),
      ],
      const SectionTitle('Outlet'),
      if (choices.isEmpty)
        AppCard(child: Text(_type == 'add' ? 'This project has no outlets to add.' : 'You have no outlets in your plan on ${_days[_weekday]}.', style: const TextStyle(color: Brand.muted)))
      else
        DropdownButtonFormField<String>(
          value: _locationId,
          isExpanded: true,
          hint: const Text('Choose an outlet'),
          items: [for (final c in choices) DropdownMenuItem(value: c['id'] as String, child: Text('${c['name']}${c['town'] != null ? '  -  ${c['town']}' : ''}', overflow: TextOverflow.ellipsis))],
          onChanged: (v) => setState(() => _locationId = v),
        ),
      const SectionTitle('Why?'),
      TextField(controller: _reason, minLines: 2, maxLines: 4, maxLength: 1000, decoration: const InputDecoration(hintText: 'For example: the store is closed on Wednesdays')),
      const SectionTitle('Photo proof (optional)'),
      Row(children: [
        OutlinedButton.icon(onPressed: _sending ? null : _pickPhoto, icon: const Icon(Icons.photo_camera_outlined), label: Text(_photo == null ? 'Take a photo' : 'Retake')),
        if (_photo != null) ...[
          const SizedBox(width: 12),
          ClipRRect(borderRadius: BorderRadius.circular(8), child: Image.file(File(_photo!.path), width: 56, height: 56, fit: BoxFit.cover)),
        ],
      ]),
      const SizedBox(height: 24),
      FilledButton.icon(onPressed: _sending ? null : _send, icon: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.send), label: const Text('Send request')),
      const SizedBox(height: 8),
      const Text('Needs a connection. Your location is sent with the request as proof when your phone can find it.', style: TextStyle(color: Brand.muted, fontSize: 12.5)),
    ]);
  }
}
