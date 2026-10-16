import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';
import 'visit_scope.dart';

/// On-shelf availability: one check per campaign product.
class OsaPage extends ConsumerWidget {
  const OsaPage({super.key, required this.visitId});
  final String visitId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return VisitScope(
      visitId: visitId,
      title: 'On-shelf availability',
      builder: (c, r, v, editable) {
        final products = r.watch(productsProvider(v.campaignId)).valueOrNull ?? const <Map<String, dynamic>>[];
        final records = r.watch(itemsProvider((visitId: v.localId, kind: 'osa'))).valueOrNull ?? const <Map<String, dynamic>>[];
        if (products.isEmpty) return const EmptyState(icon: Icons.inventory_2_outlined, title: 'No products to check', message: 'This campaign has no products on your phone. Sync to refresh.');
        final latest = <String, Map<String, dynamic>>{};
        for (final rec in records) {
          final pid = rec.str('product_id');
          if (pid != null) latest[pid] = rec; // records are oldest-first, so the newest wins
        }
        final done = latest.length;
        return ListView(padding: const EdgeInsets.all(16), children: [
          AppCard(color: Brand.ink, child: Row(children: [
            const Expanded(child: Text('Products checked', style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w600))),
            Text('$done / ${products.length}', style: const TextStyle(color: Brand.saffron, fontSize: 22, fontWeight: FontWeight.w800)),
          ])),
          const SizedBox(height: 12),
          for (final p in products)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: AppCard(
                onTap: editable ? () => _edit(c, r, v, p, latest[p['id'].toString()]) : null,
                child: Row(children: [
                  Icon(latest.containsKey(p['id'].toString()) ? Icons.check_circle : Icons.radio_button_unchecked, color: latest.containsKey(p['id'].toString()) ? Brand.good : Brand.muted),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${p['name']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text(_summary(latest[p['id'].toString()]), style: const TextStyle(color: Brand.muted, fontSize: 12.5)),
                  ])),
                  if (editable) const Icon(Icons.chevron_right),
                ]),
              ),
            ),
        ]);
      },
    );
  }

  static String _summary(Map<String, dynamic>? r) {
    if (r == null) return 'Not checked';
    if (r['out_of_stock'] == true) return 'Out of stock';
    final parts = <String>['Available'];
    if (r.integer('shelf_quantity') != null) parts.add('shelf ${r.integer('shelf_quantity')}');
    if (r.integer('facings') != null) parts.add('${r.integer('facings')} facings');
    return parts.join(' - ');
  }

  Future<void> _edit(BuildContext context, WidgetRef ref, LocalVisit v, Map<String, dynamic> product, Map<String, dynamic>? existing) async {
    final result = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (c) => _OsaSheet(product: product, existing: existing),
    );
    if (result == null) return;
    await ref.read(repoProvider).addItem(v.localId, 'osa', result);
    if (context.mounted) toast(context, 'Saved.');
  }
}

class _OsaSheet extends StatefulWidget {
  const _OsaSheet({required this.product, required this.existing});
  final Map<String, dynamic> product;
  final Map<String, dynamic>? existing;

  @override
  State<_OsaSheet> createState() => _OsaSheetState();
}

class _OsaSheetState extends State<_OsaSheet> {
  late bool _out = widget.existing?['out_of_stock'] == true;
  late bool _display = widget.existing?['display_compliant'] != false;
  late bool _visible = widget.existing?['visibility_ok'] != false;
  late final TextEditingController _shelf = TextEditingController(text: widget.existing?.integer('shelf_quantity')?.toString() ?? '');
  late final TextEditingController _back = TextEditingController(text: widget.existing?.integer('backroom_quantity')?.toString() ?? '');
  late final TextEditingController _facings = TextEditingController(text: widget.existing?.integer('facings')?.toString() ?? '');
  late final TextEditingController _price = TextEditingController(text: widget.existing?.dbl('price_on_shelf')?.toString() ?? '');

  @override
  void dispose() {
    _shelf.dispose();
    _back.dispose();
    _facings.dispose();
    _price.dispose();
    super.dispose();
  }

  int? _int(TextEditingController c) => int.tryParse(c.text.trim());

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Text('${widget.product['name']}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          const SizedBox(height: 8),
          SegmentedButton<bool>(
            showSelectedIcon: false,
            segments: const [ButtonSegment(value: false, label: Text('Available')), ButtonSegment(value: true, label: Text('Out of stock'))],
            selected: {_out},
            onSelectionChanged: (s) => setState(() => _out = s.first),
          ),
          if (!_out) ...[
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: TextField(controller: _shelf, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'On shelf'))),
              const SizedBox(width: 10),
              Expanded(child: TextField(controller: _back, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Back room'))),
            ]),
            const SizedBox(height: 10),
            Row(children: [
              Expanded(child: TextField(controller: _facings, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Facings'))),
              const SizedBox(width: 10),
              Expanded(child: TextField(controller: _price, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Shelf price'))),
            ]),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Display meets the standard'), value: _display, onChanged: (v) => setState(() => _display = v)),
            SwitchListTile(contentPadding: EdgeInsets.zero, title: const Text('Clearly visible to shoppers'), value: _visible, onChanged: (v) => setState(() => _visible = v)),
          ],
          const SizedBox(height: 10),
          FilledButton(
            onPressed: () => Navigator.pop(context, {
              'product_id': widget.product['id'],
              'available': !_out,
              'out_of_stock': _out,
              if (!_out && _int(_shelf) != null) 'shelf_quantity': _int(_shelf),
              if (!_out && _int(_back) != null) 'backroom_quantity': _int(_back),
              if (!_out && _int(_facings) != null) 'facings': _int(_facings),
              if (!_out && double.tryParse(_price.text.trim()) != null) 'price_on_shelf': double.tryParse(_price.text.trim()),
              if (!_out) 'display_compliant': _display,
              if (!_out) 'visibility_ok': _visible,
              'captured_at': nowIso(),
            }),
            child: const Text('Save'),
          ),
        ]),
      ),
    );
  }
}
