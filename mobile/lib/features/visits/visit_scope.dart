import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../models/local_visit.dart';
import '../../state/providers.dart';

typedef VisitBuilder = Widget Function(BuildContext context, WidgetRef ref, LocalVisit visit, bool editable);

/// Shared frame for the visit sub-screens: loads the visit, shows a read-only notice when it can no longer be edited.
class VisitScope extends ConsumerWidget {
  const VisitScope({super.key, required this.visitId, required this.title, required this.builder, this.fab});

  final String visitId;
  final String title;
  final VisitBuilder builder;
  final VisitBuilder? fab;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final v = ref.watch(visitProvider(visitId)).valueOrNull;
    if (v == null) {
      return Scaffold(appBar: AppBar(title: Text(title)), body: const Center(child: CircularProgressIndicator()));
    }
    final editable = v.status == 'started' || v.status == 'completed';
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      floatingActionButton: editable && fab != null ? fab!(context, ref, v, editable) : null,
      body: Column(children: [
        if (!editable)
          Container(
            width: double.infinity,
            color: const Color(0xFFFFF3E0),
            padding: const EdgeInsets.all(12),
            child: Text(v.status == 'scheduled' ? 'Check in first to record data.' : 'This visit is ${v.statusLabel.toLowerCase()} - read only.', style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
        Expanded(child: builder(context, ref, v, editable)),
      ]),
    );
  }
}

String productName(List<Map<String, dynamic>> products, String? id) {
  for (final p in products) {
    if (p['id'].toString() == id) return '${p['name']}';
  }
  return 'Product';
}
