import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';
import 'visit_scope.dart';

class MerchPage extends ConsumerWidget {
  const MerchPage({super.key, required this.visitId});
  final String visitId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return VisitScope(
      visitId: visitId,
      title: 'Merchandising',
      fab: (c, r, v, e) => FloatingActionButton.extended(
        backgroundColor: Brand.saffron,
        foregroundColor: Brand.ink,
        icon: const Icon(Icons.add),
        label: const Text('Add record'),
        onPressed: () async {
          final products = await r.read(repoProvider).products(campaignId: v.campaignId);
          if (!c.mounted) return;
          final result = await showModalBottomSheet<Map<String, dynamic>>(
            context: c,
            isScrollControlled: true,
            builder: (x) => _MerchSheet(products: products),
          );
          if (result == null) return;
          await r.read(repoProvider).addItem(v.localId, 'merch', result);
          if (c.mounted) toast(c, 'Record saved.');
        },
      ),
      builder: (c, r, v, editable) {
        final rows = r.watch(itemsProvider((visitId: v.localId, kind: 'merch'))).valueOrNull ?? const <Map<String, dynamic>>[];
        final products = r.watch(productsProvider(v.campaignId)).valueOrNull ?? const <Map<String, dynamic>>[];
        if (rows.isEmpty) return const EmptyState(icon: Icons.view_in_ar_outlined, title: 'No merchandising records', message: 'Record POSM, shelf position, planogram and competitor activity.');
        return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
          for (final m in rows)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: AppCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(m.str('product_id') == null ? 'General' : productName(products, m.str('product_id')), style: const TextStyle(fontWeight: FontWeight.w800)),
                  const SizedBox(height: 6),
                  _flag('POSM present', m['posm_present']),
                  if ((m.str('posm_type') ?? '').isNotEmpty) Text('POSM: ${m.str('posm_type')}', style: const TextStyle(color: Brand.muted)),
                  if ((m.str('shelf_position') ?? '').isNotEmpty) Text('Shelf: ${m.str('shelf_position')}', style: const TextStyle(color: Brand.muted)),
                  _flag('Planogram followed', m['planogram_compliant']),
                  _flag('Price compliant', m['price_compliant']),
                  _flag('Competitor present', m['competitor_present'], goodWhen: false),
                  if ((m.str('competitor_notes') ?? '').isNotEmpty) Text(m.str('competitor_notes')!, style: const TextStyle(color: Brand.muted)),
                ]),
              ),
            ),
        ]);
      },
    );
  }

  static Widget _flag(String label, dynamic v, {bool goodWhen = true}) {
    if (v == null) return const SizedBox.shrink();
    final yes = v == true;
    final good = yes == goodWhen;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 1),
      child: Row(children: [
        Icon(good ? Icons.check_circle : Icons.cancel, size: 16, color: good ? Brand.good : Brand.bad),
        const SizedBox(width: 6),
        Text('$label: ${yes ? 'Yes' : 'No'}'),
      ]),
    );
  }
}

class _MerchSheet extends StatefulWidget {
  const _MerchSheet({required this.products});
  final List<Map<String, dynamic>> products;

  @override
  State<_MerchSheet> createState() => _MerchSheetState();
}

class _MerchSheetState extends State<_MerchSheet> {
  String? _product;
  bool _posm = false;
  bool _planogram = true;
  bool _price = true;
  bool _competitor = false;
  final _posmType = TextEditingController();
  final _position = TextEditingController();
  final _notes = TextEditingController();

  @override
  void dispose() {
    _posmType.dispose();
    _position.dispose();
    _notes.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          const Text('Merchandising record', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          const SizedBox(height: 10),
          DropdownButtonFormField<String>(
            value: _product,
            isExpanded: true,
            hint: const Text('Product (optional)'),
            items: [for (final p in widget.products) DropdownMenuItem(value: p['id'].toString(), child: Text('${p['name']}', overflow: TextOverflow.ellipsis))],
            onChanged: (v) => setState(() => _product = v),
          ),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('POSM / branding present'), value: _posm, onChanged: (v) => setState(() => _posm = v)),
          if (_posm) TextField(controller: _posmType, decoration: const InputDecoration(labelText: 'POSM type (e.g. shelf strip, wobbler)')),
          const SizedBox(height: 8),
          TextField(controller: _position, decoration: const InputDecoration(labelText: 'Shelf position (e.g. eye level)')),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Planogram followed'), value: _planogram, onChanged: (v) => setState(() => _planogram = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Prices correct'), value: _price, onChanged: (v) => setState(() => _price = v)),
          SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Competitor activity seen'), value: _competitor, onChanged: (v) => setState(() => _competitor = v)),
          if (_competitor) TextField(controller: _notes, maxLines: 2, decoration: const InputDecoration(labelText: 'What did you see?')),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: () => Navigator.pop(context, {
              if (_product != null) 'product_id': _product,
              'posm_present': _posm,
              if (_posm && _posmType.text.trim().isNotEmpty) 'posm_type': _posmType.text.trim(),
              if (_position.text.trim().isNotEmpty) 'shelf_position': _position.text.trim(),
              'planogram_compliant': _planogram,
              'price_compliant': _price,
              'competitor_present': _competitor,
              if (_competitor && _notes.text.trim().isNotEmpty) 'competitor_notes': _notes.text.trim(),
              'captured_at': nowIso(),
            }),
            child: const Text('Save record'),
          ),
        ]),
      ),
    );
  }
}
