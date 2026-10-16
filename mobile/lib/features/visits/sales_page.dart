import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';
import 'visit_scope.dart';

class SalesPage extends ConsumerWidget {
  const SalesPage({super.key, required this.visitId});
  final String visitId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return VisitScope(
      visitId: visitId,
      title: 'Sales',
      fab: (c, r, v, e) => FloatingActionButton.extended(
        backgroundColor: Brand.saffron,
        foregroundColor: Brand.ink,
        onPressed: () => _newSale(c, r, v),
        icon: const Icon(Icons.add),
        label: const Text('New sale'),
      ),
      builder: (c, r, v, editable) {
        final sales = r.watch(itemsProvider((visitId: v.localId, kind: 'sale'))).valueOrNull ?? const <Map<String, dynamic>>[];
        final products = r.watch(productsProvider(v.campaignId)).valueOrNull ?? const <Map<String, dynamic>>[];
        if (sales.isEmpty) return const EmptyState(icon: Icons.attach_money, title: 'No sales yet', message: 'Record each sale made during this visit.');
        var grand = 0.0;
        final cards = <Widget>[];
        for (final s in sales) {
          final items = s.list('items').map((e) => (e as Map).cast<String, dynamic>()).toList();
          var total = 0.0;
          for (final it in items) {
            total += (it.dbl('unit_price') ?? 0) * (it.integer('quantity') ?? 0);
          }
          grand += total;
          cards.add(Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: AppCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(fmtDateTime(s.str('captured_at') ?? s.str('_created_at')), style: const TextStyle(color: Brand.muted))),
                  Text(money(total), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                ]),
                const SizedBox(height: 6),
                for (final it in items)
                  Text('${it.integer('quantity')} x ${productName(products, it.str('product_id'))}${it['is_promo'] == true ? '  (promo)' : ''}${it.dbl('unit_price') != null ? '  @ ${money(it.dbl('unit_price'))}' : ''}'),
                if ((s.str('payment_method') ?? '').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text('Paid by ${s.str('payment_method')}', style: const TextStyle(color: Brand.muted, fontSize: 12.5))),
              ]),
            ),
          ));
        }
        return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
          AppCard(color: Brand.ink, child: Row(children: [
            const Expanded(child: Text('Total this visit', style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w600))),
            Text(money(grand), style: const TextStyle(color: Brand.saffron, fontSize: 22, fontWeight: FontWeight.w800)),
          ])),
          const SizedBox(height: 12),
          ...cards,
        ]);
      },
    );
  }

  Future<void> _newSale(BuildContext context, WidgetRef ref, LocalVisit v) async {
    final products = await ref.read(repoProvider).products(campaignId: v.campaignId);
    if (!context.mounted) return;
    if (products.isEmpty) {
      toast(context, 'No products are linked to this campaign. Sync to refresh.', error: true);
      return;
    }
    final result = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (c) => _SaleSheet(products: products),
    );
    if (result == null) return;
    await ref.read(repoProvider).addItem(v.localId, 'sale', result);
    if (context.mounted) toast(context, 'Sale saved.');
  }
}

class _Line {
  String? productId;
  int quantity = 1;
  double? price;
  bool promo = false;
}

class _SaleSheet extends StatefulWidget {
  const _SaleSheet({required this.products});
  final List<Map<String, dynamic>> products;

  @override
  State<_SaleSheet> createState() => _SaleSheetState();
}

class _SaleSheetState extends State<_SaleSheet> {
  final List<_Line> _lines = [_Line()];
  String _payment = 'cash';
  final _notes = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  void _save() {
    final items = <Map<String, dynamic>>[];
    for (final l in _lines) {
      if (l.productId == null) {
        setState(() => _error = 'Choose a product for every line.');
        return;
      }
      if (l.quantity < 1) {
        setState(() => _error = 'Quantity must be at least 1.');
        return;
      }
      items.add({'product_id': l.productId, 'quantity': l.quantity, if (l.price != null) 'unit_price': l.price, 'is_promo': l.promo});
    }
    Navigator.pop(context, {
      'payment_method': _payment,
      'captured_at': nowIso(),
      if (_notes.text.trim().isNotEmpty) 'notes': _notes.text.trim(),
      'items': items,
    });
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          const Text('New sale', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          const SizedBox(height: 12),
          for (var i = 0; i < _lines.length; i++) _lineEditor(i),
          TextButton.icon(onPressed: () => setState(() => _lines.add(_Line())), icon: const Icon(Icons.add), label: const Text('Add another product')),
          DropdownButtonFormField<String>(
            value: _payment,
            items: const [
              DropdownMenuItem(value: 'cash', child: Text('Cash')),
              DropdownMenuItem(value: 'mpesa', child: Text('M-Pesa')),
              DropdownMenuItem(value: 'card', child: Text('Card')),
              DropdownMenuItem(value: 'credit', child: Text('Credit')),
              DropdownMenuItem(value: 'other', child: Text('Other')),
            ],
            onChanged: (v) => setState(() => _payment = v ?? 'cash'),
            decoration: const InputDecoration(labelText: 'Payment method'),
          ),
          const SizedBox(height: 10),
          TextField(controller: _notes, decoration: const InputDecoration(labelText: 'Notes (optional)')),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w600))),
          const SizedBox(height: 14),
          FilledButton(onPressed: _save, child: const Text('Save sale')),
        ]),
      ),
    );
  }

  Widget _lineEditor(int i) {
    final l = _lines[i];
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Column(children: [
        Row(children: [
          Expanded(
            child: DropdownButtonFormField<String>(
              value: l.productId,
              isExpanded: true,
              hint: const Text('Product'),
              items: [for (final p in widget.products) DropdownMenuItem(value: p['id'].toString(), child: Text('${p['name']}', overflow: TextOverflow.ellipsis))],
              onChanged: (id) => setState(() {
                l.productId = id;
                final p = widget.products.firstWhere((p) => p['id'].toString() == id, orElse: () => <String, dynamic>{});
                l.price ??= p.dbl('unit_price');
              }),
            ),
          ),
          if (_lines.length > 1) IconButton(onPressed: () => setState(() => _lines.removeAt(i)), icon: const Icon(Icons.delete_outline)),
        ]),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(
            child: TextFormField(
              key: ValueKey('qty-$i-${l.productId}'),
              initialValue: '${l.quantity}',
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Qty'),
              onChanged: (s) => l.quantity = int.tryParse(s) ?? 0,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: TextFormField(
              key: ValueKey('price-$i-${l.productId}'),
              initialValue: l.price?.toString() ?? '',
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'Unit price'),
              onChanged: (s) => l.price = double.tryParse(s),
            ),
          ),
        ]),
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          dense: true,
          controlAffinity: ListTileControlAffinity.leading,
          title: const Text('Promotional sale'),
          value: l.promo,
          onChanged: (v) => setState(() => l.promo = v == true),
        ),
      ]),
    );
  }
}
