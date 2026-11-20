import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/util.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';
import 'visit_scope.dart';

/// Stock count: shelf and back-room quantity for each campaign product (one row per product, newest wins).
class StockCountPage extends ConsumerWidget {
  const StockCountPage({super.key, required this.visitId});
  final String visitId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return VisitScope(
      visitId: visitId,
      title: 'Stock count',
      builder: (c, r, v, editable) {
        final products = r.watch(productsProvider(v.campaignId)).valueOrNull ?? const <Map<String, dynamic>>[];
        final records = r.watch(itemsProvider((visitId: v.localId, kind: 'stockcount'))).valueOrNull ?? const <Map<String, dynamic>>[];
        if (products.isEmpty) return const EmptyState(icon: Icons.inventory_outlined, title: 'No products to count', message: 'This campaign has no products on your phone. Sync to refresh.');
        final latest = <String, Map<String, dynamic>>{};
        for (final rec in records) {
          final pid = rec.str('product_id');
          if (pid != null) latest[pid] = rec;
        }
        return ListView(padding: const EdgeInsets.all(16), children: [
          AppCard(color: Brand.ink, child: Row(children: [
            const Expanded(child: Text('Products counted', style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w600))),
            Text('${latest.length} / ${products.length}', style: const TextStyle(color: Brand.saffron, fontSize: 22, fontWeight: FontWeight.w800)),
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
    if (r == null) return 'Not counted';
    final s = r.integer('shelf_quantity') ?? 0;
    final b = r.integer('backroom_quantity') ?? 0;
    return 'Shelf $s - Back room $b - Total ${s + b}';
  }

  Future<void> _edit(BuildContext context, WidgetRef ref, LocalVisit v, Map<String, dynamic> product, Map<String, dynamic>? existing) async {
    final result = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (c) => _CountSheet(product: product, existing: existing),
    );
    if (result == null) return;
    await ref.read(repoProvider).addItem(v.localId, 'stockcount', result);
    if (context.mounted) toast(context, 'Saved.');
  }
}

class _CountSheet extends StatefulWidget {
  const _CountSheet({required this.product, required this.existing});
  final Map<String, dynamic> product;
  final Map<String, dynamic>? existing;

  @override
  State<_CountSheet> createState() => _CountSheetState();
}

class _CountSheetState extends State<_CountSheet> {
  late final TextEditingController _shelf = TextEditingController(text: widget.existing?.integer('shelf_quantity')?.toString() ?? '');
  late final TextEditingController _back = TextEditingController(text: widget.existing?.integer('backroom_quantity')?.toString() ?? '');
  String? _error;

  @override
  void dispose() {
    _shelf.dispose();
    _back.dispose();
    super.dispose();
  }

  void _save() {
    final shelf = int.tryParse(_shelf.text.trim());
    final back = int.tryParse(_back.text.trim()) ?? 0;
    if (shelf == null || shelf < 0 || back < 0) {
      setState(() => _error = 'Enter the shelf quantity (0 if none).');
      return;
    }
    Navigator.pop(context, {'product_id': widget.product['id'], 'shelf_quantity': shelf, 'backroom_quantity': back, 'captured_at': nowIso()});
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          Text('${widget.product['name']}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: TextField(controller: _shelf, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'On shelf'))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _back, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Back room'))),
          ]),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w600))),
          const SizedBox(height: 14),
          FilledButton(onPressed: _save, child: const Text('Save')),
        ]),
      ),
    );
  }
}

/// Expiries: product, expiry date and quantity. Several can be recorded for one product (different dates or batches).
class ExpiriesPage extends ConsumerWidget {
  const ExpiriesPage({super.key, required this.visitId});
  final String visitId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return VisitScope(
      visitId: visitId,
      title: 'Expiries',
      fab: (c, r, v, e) => FloatingActionButton.extended(
        backgroundColor: Brand.saffron,
        foregroundColor: Brand.ink,
        onPressed: () => _add(c, r, v),
        icon: const Icon(Icons.add),
        label: const Text('Add expiry'),
      ),
      builder: (c, r, v, editable) {
        final items = r.watch(itemsProvider((visitId: v.localId, kind: 'expiry'))).valueOrNull ?? const <Map<String, dynamic>>[];
        final products = r.watch(productsProvider(v.campaignId)).valueOrNull ?? const <Map<String, dynamic>>[];
        if (items.isEmpty) return const EmptyState(icon: Icons.event_busy_outlined, title: 'No expiries yet', message: 'Record stock that is close to or past its expiry date.');
        return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
          for (final e in items)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: AppCard(
                child: Row(children: [
                  const Icon(Icons.event_busy_outlined, color: Brand.muted),
                  const SizedBox(width: 12),
                  Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(productName(products, e.str('product_id')), style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text('Expires ${fmtDate(e.str('expiry_date'))}${(e.str('batch_no') ?? '').isNotEmpty ? ' - batch ${e.str('batch_no')}' : ''}', style: const TextStyle(color: Brand.muted, fontSize: 12.5)),
                  ])),
                  Text('${e.integer('quantity')}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                ]),
              ),
            ),
        ]);
      },
    );
  }

  Future<void> _add(BuildContext context, WidgetRef ref, LocalVisit v) async {
    final products = await ref.read(repoProvider).products(campaignId: v.campaignId);
    if (!context.mounted) return;
    if (products.isEmpty) {
      toast(context, 'No products are linked to this campaign. Sync to refresh.', error: true);
      return;
    }
    final result = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (c) => _ExpirySheet(products: products),
    );
    if (result == null) return;
    await ref.read(repoProvider).addItem(v.localId, 'expiry', result);
    if (context.mounted) toast(context, 'Expiry saved.');
  }
}

class _ExpirySheet extends StatefulWidget {
  const _ExpirySheet({required this.products});
  final List<Map<String, dynamic>> products;

  @override
  State<_ExpirySheet> createState() => _ExpirySheetState();
}

class _ExpirySheetState extends State<_ExpirySheet> {
  String? _productId;
  DateTime? _date;
  final _qty = TextEditingController();
  final _batch = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _qty.dispose();
    _batch.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final now = DateTime.now();
    final d = await showDatePicker(context: context, initialDate: _date ?? now, firstDate: DateTime(now.year - 2), lastDate: DateTime(now.year + 10));
    if (d != null) setState(() => _date = d);
  }

  String _iso(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  void _save() {
    final qty = int.tryParse(_qty.text.trim());
    if (_productId == null) return setState(() => _error = 'Choose a product.');
    if (_date == null) return setState(() => _error = 'Choose the expiry date.');
    if (qty == null || qty < 1) return setState(() => _error = 'Enter the quantity.');
    Navigator.pop(context, {
      'product_id': _productId,
      'expiry_date': _iso(_date!),
      'quantity': qty,
      if (_batch.text.trim().isNotEmpty) 'batch_no': _batch.text.trim(),
      'captured_at': nowIso(),
    });
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          const Text('Add expiry', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _productId,
            isExpanded: true,
            hint: const Text('Product'),
            items: [for (final p in widget.products) DropdownMenuItem(value: p['id'].toString(), child: Text('${p['name']}', overflow: TextOverflow.ellipsis))],
            onChanged: (id) => setState(() => _productId = id),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(onPressed: _pick, icon: const Icon(Icons.event), label: Text(_date == null ? 'Expiry date' : 'Expires ${_iso(_date!)}')),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(child: TextField(controller: _qty, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Quantity'))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _batch, decoration: const InputDecoration(labelText: 'Batch (optional)'))),
          ]),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w600))),
          const SizedBox(height: 14),
          FilledButton(onPressed: _save, child: const Text('Save expiry')),
        ]),
      ),
    );
  }
}

/// Orders: products, quantity and price (value). An order is not a sale: nothing is handed over.
class OrdersPage extends ConsumerWidget {
  const OrdersPage({super.key, required this.visitId});
  final String visitId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return VisitScope(
      visitId: visitId,
      title: 'Orders',
      fab: (c, r, v, e) => FloatingActionButton.extended(
        backgroundColor: Brand.saffron,
        foregroundColor: Brand.ink,
        onPressed: () => _newOrder(c, r, v),
        icon: const Icon(Icons.add),
        label: const Text('New order'),
      ),
      builder: (c, r, v, editable) {
        final orders = r.watch(itemsProvider((visitId: v.localId, kind: 'order'))).valueOrNull ?? const <Map<String, dynamic>>[];
        final products = r.watch(productsProvider(v.campaignId)).valueOrNull ?? const <Map<String, dynamic>>[];
        if (orders.isEmpty) return const EmptyState(icon: Icons.shopping_cart_outlined, title: 'No orders yet', message: 'Record each order taken at this outlet.');
        var grand = 0.0;
        final cards = <Widget>[];
        for (final o in orders) {
          final items = o.list('items').map((e) => (e as Map).cast<String, dynamic>()).toList();
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
                  Expanded(child: Text(fmtDateTime(o.str('captured_at') ?? o.str('_created_at')), style: const TextStyle(color: Brand.muted))),
                  Text(money(total), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                ]),
                const SizedBox(height: 6),
                for (final it in items) Text('${it.integer('quantity')} x ${productName(products, it.str('product_id'))}${it.dbl('unit_price') != null ? '  @ ${money(it.dbl('unit_price'))}' : ''}'),
              ]),
            ),
          ));
        }
        return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
          AppCard(color: Brand.ink, child: Row(children: [
            const Expanded(child: Text('Orders this visit', style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w600))),
            Text(money(grand), style: const TextStyle(color: Brand.saffron, fontSize: 22, fontWeight: FontWeight.w800)),
          ])),
          const SizedBox(height: 12),
          ...cards,
        ]);
      },
    );
  }

  Future<void> _newOrder(BuildContext context, WidgetRef ref, LocalVisit v) async {
    final products = await ref.read(repoProvider).products(campaignId: v.campaignId);
    if (!context.mounted) return;
    if (products.isEmpty) {
      toast(context, 'No products are linked to this campaign. Sync to refresh.', error: true);
      return;
    }
    final result = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (c) => _OrderSheet(products: products),
    );
    if (result == null) return;
    await ref.read(repoProvider).addItem(v.localId, 'order', result);
    if (context.mounted) toast(context, 'Order saved.');
  }
}

class _OrderLine {
  String? productId;
  int quantity = 1;
  double? price;
}

class _OrderSheet extends StatefulWidget {
  const _OrderSheet({required this.products});
  final List<Map<String, dynamic>> products;

  @override
  State<_OrderSheet> createState() => _OrderSheetState();
}

class _OrderSheetState extends State<_OrderSheet> {
  final List<_OrderLine> _lines = [_OrderLine()];
  String? _error;

  void _save() {
    final items = <Map<String, dynamic>>[];
    for (final l in _lines) {
      if (l.productId == null) return setState(() => _error = 'Choose a product for every line.');
      if (l.quantity < 1) return setState(() => _error = 'Quantity must be at least 1.');
      items.add({'product_id': l.productId, 'quantity': l.quantity, if (l.price != null) 'unit_price': l.price});
    }
    Navigator.pop(context, {'captured_at': nowIso(), 'items': items});
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          const Text('New order', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
          const SizedBox(height: 12),
          for (var i = 0; i < _lines.length; i++) _line(i),
          TextButton.icon(onPressed: () => setState(() => _lines.add(_OrderLine())), icon: const Icon(Icons.add), label: const Text('Add another product')),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: Brand.bad, fontWeight: FontWeight.w600))),
          const SizedBox(height: 14),
          FilledButton(onPressed: _save, child: const Text('Save order')),
        ]),
      ),
    );
  }

  Widget _line(int i) {
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
              key: ValueKey('oqty-$i-${l.productId}'),
              initialValue: '${l.quantity}',
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Qty'),
              onChanged: (s) => l.quantity = int.tryParse(s) ?? 0,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: TextFormField(
              key: ValueKey('oprice-$i-${l.productId}'),
              initialValue: l.price?.toString() ?? '',
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'Unit price'),
              onChanged: (s) => l.price = double.tryParse(s),
            ),
          ),
        ]),
      ]),
    );
  }
}
