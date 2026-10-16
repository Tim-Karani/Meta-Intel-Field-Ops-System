import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

class IssuesPage extends ConsumerWidget {
  const IssuesPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final issues = ref.watch(issuesProvider).valueOrNull ?? const <Map<String, Object?>>[];
    return Scaffold(
      appBar: AppBar(title: const Text('Issues')),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: Brand.saffron,
        foregroundColor: Brand.ink,
        onPressed: () => showIssueSheet(context, ref),
        icon: const Icon(Icons.add),
        label: const Text('Report issue'),
      ),
      body: issues.isEmpty
          ? const EmptyState(icon: Icons.report_problem_outlined, title: 'No issues reported from this phone', message: 'Report stock-outs, access problems, damaged displays or anything your supervisor should know.')
          : ListView.separated(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
              itemCount: issues.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (c, i) {
                final it = issues[i];
                final synced = (it['synced'] as int? ?? 0) == 1;
                return AppCard(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Expanded(child: Text('${it['title']}', style: const TextStyle(fontWeight: FontWeight.w800))),
                      _Priority('${it['priority']}'),
                    ]),
                    if ((it['description'] as String?)?.isNotEmpty ?? false) Padding(padding: const EdgeInsets.only(top: 4), child: Text('${it['description']}')),
                    const SizedBox(height: 6),
                    Row(children: [
                      Icon(synced ? Icons.cloud_done_outlined : Icons.cloud_upload_outlined, size: 15, color: synced ? Brand.good : Brand.warn),
                      const SizedBox(width: 6),
                      Text(synced ? 'Sent' : 'Waiting to send', style: TextStyle(color: synced ? Brand.good : Brand.warn, fontWeight: FontWeight.w700, fontSize: 12.5)),
                      const Spacer(),
                      Text(fmtDateTime(it['created_at'] as String?), style: const TextStyle(color: Brand.muted, fontSize: 12.5)),
                    ]),
                  ]),
                );
              },
            ),
    );
  }
}

class _Priority extends StatelessWidget {
  const _Priority(this.p);
  final String p;

  @override
  Widget build(BuildContext context) {
    final color = switch (p) { 'critical' => Brand.bad, 'high' => Brand.warn, 'medium' => Brand.ink, _ => Brand.muted };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(color: color.withAlpha(30), borderRadius: BorderRadius.circular(12)),
      child: Text(p[0].toUpperCase() + p.substring(1), style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w800)),
    );
  }
}

/// Bottom sheet to report an issue (optionally tied to a visit). Saved locally and sent when online.
Future<void> showIssueSheet(BuildContext context, WidgetRef ref, {LocalVisit? visit}) async {
  final result = await showModalBottomSheet<Map<String, String>>(
    context: context,
    isScrollControlled: true,
    builder: (c) => const _IssueSheet(),
  );
  if (result == null) return;
  await ref.read(repoProvider).createIssue(
        title: result['title']!,
        description: result['description'],
        category: result['category'],
        priority: result['priority'] ?? 'medium',
        visit: visit,
      );
  if (context.mounted) toast(context, 'Issue saved. It will be sent when you are online.');
}

class _IssueSheet extends StatefulWidget {
  const _IssueSheet();

  @override
  State<_IssueSheet> createState() => _IssueSheetState();
}

class _IssueSheetState extends State<_IssueSheet> {
  final _title = TextEditingController();
  final _description = TextEditingController();
  String _category = 'stock';
  String _priority = 'medium';
  String? _error;

  @override
  void dispose() {
    _title.dispose();
    _description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          const Text('Report an issue', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          const SizedBox(height: 12),
          TextField(controller: _title, textCapitalization: TextCapitalization.sentences, decoration: InputDecoration(labelText: 'What is the problem?', errorText: _error)),
          const SizedBox(height: 10),
          TextField(controller: _description, minLines: 3, maxLines: 5, textCapitalization: TextCapitalization.sentences, decoration: const InputDecoration(labelText: 'Details (optional)')),
          const SizedBox(height: 10),
          DropdownButtonFormField<String>(
            value: _category,
            items: const [
              DropdownMenuItem(value: 'stock', child: Text('Stock / availability')),
              DropdownMenuItem(value: 'display', child: Text('Display / merchandising')),
              DropdownMenuItem(value: 'access', child: Text('Access / permission')),
              DropdownMenuItem(value: 'pricing', child: Text('Pricing')),
              DropdownMenuItem(value: 'safety', child: Text('Safety')),
              DropdownMenuItem(value: 'other', child: Text('Other')),
            ],
            onChanged: (v) => setState(() => _category = v ?? 'other'),
            decoration: const InputDecoration(labelText: 'Category'),
          ),
          const SizedBox(height: 10),
          SegmentedButton<String>(
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: 'low', label: Text('Low')),
              ButtonSegment(value: 'medium', label: Text('Medium')),
              ButtonSegment(value: 'high', label: Text('High')),
              ButtonSegment(value: 'critical', label: Text('Critical')),
            ],
            selected: {_priority},
            onSelectionChanged: (s) => setState(() => _priority = s.first),
          ),
          const SizedBox(height: 14),
          FilledButton(
            onPressed: () {
              if (_title.text.trim().length < 3) {
                setState(() => _error = 'Describe the problem in a few words.');
                return;
              }
              Navigator.pop(context, {
                'title': _title.text.trim(),
                'description': _description.text.trim(),
                'category': _category,
                'priority': _priority,
              });
            },
            child: const Text('Save issue'),
          ),
        ]),
      ),
    );
  }
}
