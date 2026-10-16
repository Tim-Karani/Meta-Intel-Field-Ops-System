import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../app/theme.dart';
import '../../models/form_def.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';
import 'photo_capture.dart';

/// Renders the visit's form from the downloaded definition. Answers autosave to the phone as you type.
class FormPage extends ConsumerStatefulWidget {
  const FormPage({super.key, required this.visitId});
  final String visitId;

  @override
  ConsumerState<FormPage> createState() => _FormPageState();
}

class _FormPageState extends ConsumerState<FormPage> {
  Map<String, dynamic> _answers = {};
  bool _loaded = false;
  bool _showErrors = false;
  Timer? _save;

  @override
  void dispose() {
    _save?.cancel();
    super.dispose();
  }

  void _set(LocalVisit v, String qid, dynamic value) {
    setState(() {
      if (FormQuestion.isEmpty(value)) {
        _answers.remove(qid);
      } else {
        _answers[qid] = value;
      }
    });
    _save?.cancel();
    _save = Timer(const Duration(milliseconds: 700), () => _flush(v));
  }

  Future<void> _flush(LocalVisit v) => ref.read(repoProvider).saveAnswers(v.localId, Map<String, dynamic>.from(_answers));

  @override
  Widget build(BuildContext context) {
    final vAsync = ref.watch(visitProvider(widget.visitId));
    final v = vAsync.valueOrNull;
    if (v == null) return Scaffold(appBar: AppBar(title: const Text('Form')), body: const Center(child: CircularProgressIndicator()));
    final formAsync = ref.watch(formProvider(v.formId));
    final form = formAsync.valueOrNull;
    if (form == null) {
      return Scaffold(appBar: AppBar(title: const Text('Form')), body: const EmptyState(icon: Icons.description_outlined, title: 'No form for this visit'));
    }
    if (!_loaded) {
      _answers = Map<String, dynamic>.from(v.answers);
      _loaded = true;
    }
    final photos = ref.watch(photosProvider(v.localId)).valueOrNull ?? const <Map<String, Object?>>[];
    final counts = <String, int>{};
    for (final p in photos) {
      final q = p['question_id'] as String?;
      if (q != null) counts[q] = (counts[q] ?? 0) + 1;
    }
    final vis = form.visibility(_answers);
    final problems = form.problems(_answers, counts);
    final editable = v.isEditable && v.status != 'scheduled';

    return PopScope(
      onPopInvokedWithResult: (didPop, _) {
        if (didPop && _save?.isActive == true) {
          _save?.cancel();
          _flush(v); // never lose the last edit
        }
      },
      child: Scaffold(
        appBar: AppBar(title: Text(form.name)),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          if (!editable)
            const AppCard(color: Color(0xFFFFF3E0), child: Text('This form is read-only.', style: TextStyle(color: Brand.warn, fontWeight: FontWeight.w700))),
          for (final section in form.sections) ...[
            if (section.title.isNotEmpty) SectionTitle(section.title),
            if ((section.description ?? '').isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(section.description!, style: const TextStyle(color: Brand.muted))),
            for (final q in section.questions)
              if (vis[q.id] == true)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: AppCard(
                    child: _QuestionField(
                      key: ValueKey('${q.id}-${q.type}'),
                      q: q,
                      value: _answers[q.id],
                      enabled: editable,
                      error: _showErrors ? problems[q.id] : null,
                      photoCount: counts[q.id] ?? 0,
                      visit: v,
                      form: form,
                      onChanged: (val) => _set(v, q.id, val),
                    ),
                  ),
                ),
          ],
          const SizedBox(height: 8),
          if (problems.isEmpty)
            const AppCard(color: Color(0xFFE6F5EE), child: Row(children: [Icon(Icons.check_circle, color: Brand.good), SizedBox(width: 10), Expanded(child: Text('All required answers are complete.', style: TextStyle(fontWeight: FontWeight.w700)))]))
          else
            AppCard(
              color: const Color(0xFFFFF3E0),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${problems.length} item${problems.length == 1 ? '' : 's'} still to finish before you can submit.', style: const TextStyle(color: Brand.warn, fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
                OutlinedButton(onPressed: () => setState(() => _showErrors = true), child: const Text('Show what is missing')),
              ]),
            ),
          const SizedBox(height: 24),
        ]),
      ),
    );
  }
}

class _QuestionField extends ConsumerWidget {
  const _QuestionField({
    super.key,
    required this.q,
    required this.value,
    required this.enabled,
    required this.error,
    required this.photoCount,
    required this.visit,
    required this.form,
    required this.onChanged,
  });

  final FormQuestion q;
  final dynamic value;
  final bool enabled;
  final String? error;
  final int photoCount;
  final LocalVisit visit;
  final FormDef form;
  final void Function(dynamic) onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final label = Text.rich(TextSpan(children: [
      TextSpan(text: q.label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
      if (q.required) const TextSpan(text: '  *', style: TextStyle(color: Brand.bad, fontWeight: FontWeight.w800)),
    ]));
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      label,
      if ((q.helpText ?? '').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(q.helpText!, style: const TextStyle(color: Brand.muted, fontSize: 12.5))),
      const SizedBox(height: 8),
      _input(context, ref),
      if (error != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(error!, style: const TextStyle(color: Brand.bad, fontSize: 12.5, fontWeight: FontWeight.w600))),
    ]);
  }

  Widget _input(BuildContext context, WidgetRef ref) {
    switch (q.type) {
      case 'short_text':
      case 'barcode':
        return _TextInput(value: value?.toString() ?? '', enabled: enabled, onChanged: onChanged, hint: q.type == 'barcode' ? 'Type the barcode' : null);
      case 'long_text':
        return _TextInput(value: value?.toString() ?? '', enabled: enabled, onChanged: onChanged, lines: 4);
      case 'integer':
        return _TextInput(
          value: value?.toString() ?? '',
          enabled: enabled,
          keyboard: const TextInputType.numberWithOptions(signed: true),
          onChanged: (s) => onChanged(s.trim().isEmpty ? null : (int.tryParse(s.trim()) ?? s.trim())),
        );
      case 'decimal':
      case 'currency':
      case 'percentage':
        return _TextInput(
          value: value?.toString() ?? '',
          enabled: enabled,
          keyboard: const TextInputType.numberWithOptions(decimal: true),
          suffix: q.type == 'percentage' ? '%' : null,
          onChanged: (s) => onChanged(s.trim().isEmpty ? null : (num.tryParse(s.trim()) ?? s.trim())),
        );
      case 'yes_no':
        final yes = value == true || '$value'.toLowerCase() == 'yes';
        final no = value == false || '$value'.toLowerCase() == 'no';
        return SegmentedButton<bool>(
          emptySelectionAllowed: true,
          showSelectedIcon: false,
          segments: const [ButtonSegment(value: true, label: Text('Yes')), ButtonSegment(value: false, label: Text('No'))],
          selected: {if (yes) true, if (no) false},
          onSelectionChanged: enabled ? (s) => onChanged(s.isEmpty ? null : s.first) : null,
        );
      case 'single_select':
        return Column(children: [
          for (final o in q.options)
            RadioListTile<String>(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: Text(o.label),
              value: o.value,
              groupValue: value?.toString(),
              onChanged: enabled ? (x) => onChanged(x) : null,
            ),
        ]);
      case 'dropdown':
        return DropdownButtonFormField<String>(
          value: q.options.any((o) => o.value == value) ? value as String : null,
          items: [for (final o in q.options) DropdownMenuItem(value: o.value, child: Text(o.label))],
          onChanged: enabled ? (x) => onChanged(x) : null,
          decoration: const InputDecoration(hintText: 'Select'),
        );
      case 'multi_select':
        final selected = value is List ? value.map((e) => e.toString()).toSet() : <String>{};
        return Column(children: [
          for (final o in q.options)
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              controlAffinity: ListTileControlAffinity.leading,
              title: Text(o.label),
              value: selected.contains(o.value),
              onChanged: enabled
                  ? (on) {
                      final next = <String>{...selected};
                      on == true ? next.add(o.value) : next.remove(o.value);
                      onChanged(next.toList());
                    }
                  : null,
            ),
        ]);
      case 'rating':
        final max = (q.settings['max'] as num?)?.toInt() ?? 5;
        final current = value is num ? value.toInt() : int.tryParse('$value') ?? 0;
        return Row(children: [
          for (var i = 1; i <= max; i++)
            IconButton(
              onPressed: enabled ? () => onChanged(i == current ? null : i) : null,
              icon: Icon(i <= current ? Icons.star_rounded : Icons.star_outline_rounded, color: Brand.saffron, size: 32),
            ),
        ]);
      case 'date_time':
        final dt = value == null ? null : DateTime.tryParse('$value');
        return OutlinedButton.icon(
          onPressed: enabled ? () => _pickDateTime(context, dt) : null,
          icon: const Icon(Icons.event),
          label: Text(dt == null ? 'Pick date & time' : DateFormat('d MMM yyyy, HH:mm').format(dt.toLocal())),
        );
      case 'gps':
        final m = value is Map ? value as Map : null;
        return OutlinedButton.icon(
          onPressed: enabled ? () => _captureGps(context, ref) : null,
          icon: const Icon(Icons.my_location),
          label: Text(m == null ? 'Capture my location' : '${(m['lat'] as num).toStringAsFixed(5)}, ${(m['lng'] as num).toStringAsFixed(5)}'),
        );
      case 'product':
        return _ProductPicker(campaignId: visit.campaignId, value: value?.toString(), enabled: enabled, onChanged: onChanged);
      case 'photo':
        return Row(children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: enabled ? () => captureEvidence(context, ref, visit, questionId: q.id, liveOnly: form.requiresLiveCapture) : null,
              icon: const Icon(Icons.photo_camera_outlined),
              label: Text(photoCount == 0 ? 'Take photo' : 'Add another'),
            ),
          ),
          const SizedBox(width: 10),
          Text('$photoCount taken', style: TextStyle(fontWeight: FontWeight.w700, color: photoCount > 0 ? Brand.good : Brand.muted)),
        ]);
      case 'signature':
        return Row(children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: enabled ? () => captureSignature(context, ref, visit, questionId: q.id) : null,
              icon: const Icon(Icons.draw_outlined),
              label: Text(photoCount == 0 ? 'Capture signature' : 'Sign again'),
            ),
          ),
          const SizedBox(width: 10),
          Text(photoCount > 0 ? 'Signed' : 'Not signed', style: TextStyle(fontWeight: FontWeight.w700, color: photoCount > 0 ? Brand.good : Brand.muted)),
        ]);
      default:
        return _TextInput(value: value?.toString() ?? '', enabled: enabled, onChanged: onChanged);
    }
  }

  Future<void> _pickDateTime(BuildContext context, DateTime? current) async {
    final now = DateTime.now();
    final date = await showDatePicker(context: context, initialDate: current ?? now, firstDate: DateTime(now.year - 5), lastDate: DateTime(now.year + 5));
    if (date == null || !context.mounted) return;
    final time = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(current ?? now));
    if (time == null) return;
    onChanged(DateTime(date.year, date.month, date.day, time.hour, time.minute).toUtc().toIso8601String());
  }

  Future<void> _captureGps(BuildContext context, WidgetRef ref) async {
    try {
      final g = await ref.read(locationServiceProvider).current();
      onChanged({'lat': g.latitude, 'lng': g.longitude, 'accuracy_m': g.accuracyM});
    } catch (e) {
      if (context.mounted) toast(context, '$e', error: true);
    }
  }
}

class _TextInput extends StatefulWidget {
  const _TextInput({required this.value, required this.enabled, required this.onChanged, this.keyboard, this.lines = 1, this.suffix, this.hint});
  final String value;
  final bool enabled;
  final void Function(String) onChanged;
  final TextInputType? keyboard;
  final int lines;
  final String? suffix;
  final String? hint;

  @override
  State<_TextInput> createState() => _TextInputState();
}

class _TextInputState extends State<_TextInput> {
  late final TextEditingController _c = TextEditingController(text: widget.value);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => TextField(
        controller: _c,
        enabled: widget.enabled,
        keyboardType: widget.keyboard ?? (widget.lines > 1 ? TextInputType.multiline : TextInputType.text),
        minLines: widget.lines,
        maxLines: widget.lines,
        onChanged: widget.onChanged,
        decoration: InputDecoration(suffixText: widget.suffix, hintText: widget.hint),
      );
}

class _ProductPicker extends ConsumerWidget {
  const _ProductPicker({required this.campaignId, required this.value, required this.enabled, required this.onChanged});
  final String? campaignId;
  final String? value;
  final bool enabled;
  final void Function(dynamic) onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final products = ref.watch(productsProvider(campaignId)).valueOrNull ?? const <Map<String, dynamic>>[];
    final ids = products.map((p) => p['id'].toString()).toSet();
    return DropdownButtonFormField<String>(
      value: value != null && ids.contains(value) ? value : null,
      isExpanded: true,
      items: [for (final p in products) DropdownMenuItem(value: p['id'].toString(), child: Text('${p['name']}', overflow: TextOverflow.ellipsis))],
      onChanged: enabled ? (x) => onChanged(x) : null,
      decoration: const InputDecoration(hintText: 'Select a product'),
    );
  }
}
