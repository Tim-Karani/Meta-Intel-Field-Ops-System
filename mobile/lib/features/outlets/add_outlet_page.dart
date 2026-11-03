import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app/theme.dart';
import '../../models/local_visit.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

/// Add an outlet while standing at it. Works offline: the outlet, today's assignment and the visit are created on the
/// phone and sent in order when a connection is available. The outlet's position is saved when the agent checks in.
class AddOutletPage extends ConsumerStatefulWidget {
  const AddOutletPage({super.key});

  @override
  ConsumerState<AddOutletPage> createState() => _AddOutletPageState();
}

class _AddOutletPageState extends ConsumerState<AddOutletPage> {
  static const _types = <String, String>{
    'retail_store': 'Retail store',
    'supermarket': 'Supermarket',
    'wholesale': 'Wholesale',
    'kiosk': 'Kiosk',
    'pharmacy': 'Pharmacy',
    'bar_restaurant': 'Bar / restaurant',
    'market': 'Market',
    'event_venue': 'Event venue',
    'other': 'Other',
  };

  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _town = TextEditingController();
  final _address = TextEditingController();
  final _contact = TextEditingController();
  final _phone = TextEditingController();
  final _notes = TextEditingController();
  String _type = 'retail_store';
  String? _campaignId;
  bool _saving = false;
  late final Future<List<OutletCampaign>> _campaigns = ref.read(repoProvider).outletCampaigns();

  @override
  void dispose() {
    for (final c in [_name, _town, _address, _contact, _phone, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save(List<OutletCampaign> options) async {
    if (!(_form.currentState?.validate() ?? false)) return;
    final campaign = options.firstWhere((a) => a.id == _campaignId, orElse: () => options.first);
    setState(() => _saving = true);
    try {
      final visitId = await ref.read(repoProvider).createOutlet(
            campaign: campaign,
            name: _name.text,
            type: _type,
            town: _town.text,
            address: _address.text,
            contactName: _contact.text,
            contactPhone: _phone.text,
            notes: _notes.text,
          );
      ref.read(syncServiceProvider).run();
      if (!mounted) return;
      toast(context, 'Outlet saved on this phone. Check in to save its location.');
      context.pushReplacement('/visit/$visitId');
    } on StateError catch (e) {
      if (mounted) toast(context, e.message, error: true);
    } catch (e) {
      if (mounted) toast(context, '$e', error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!ref.watch(sessionProvider).can('locations.capture')) {
      return Scaffold(
        appBar: AppBar(title: const Text('Add outlet')),
        body: const EmptyState(
            icon: Icons.lock_outline,
            title: 'Not allowed to add outlets',
            message: 'Your account does not have the "Add outlets in the field" permission. Ask your supervisor or administrator to enable it, then sync.'),
      );
    }
    return Scaffold(
      appBar: AppBar(title: const Text('Add outlet')),
      body: FutureBuilder<List<OutletCampaign>>(
        future: _campaigns,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          final all = snap.data ?? const <OutletCampaign>[];
          final options = all.where((c) => c.canAdd).toList();
          if (options.isEmpty) {
            return EmptyState(
                icon: Icons.storefront_outlined,
                title: all.isEmpty ? 'No campaign yet' : 'You cannot add outlets here',
                message: all.isEmpty
                    ? 'You are not part of any campaign yet. Sync to download your campaigns, or ask your supervisor to add you.'
                    : (all.first.denial ?? 'None of your projects allow adding outlets in the field.'));
          }
          if (_campaignId == null) {
            _campaignId = options.first.id;
            _type = _types.containsKey(options.first.defaultType) ? options.first.defaultType : 'retail_store';
          }
          return Form(
            key: _form,
            child: ListView(padding: const EdgeInsets.all(16), children: [
              AppCard(
                color: Brand.saffronSoft,
                child: const Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Icon(Icons.add_location_alt_outlined, color: Brand.ink),
                  SizedBox(width: 10),
                  Expanded(
                      child: Text('Be at the outlet when you add it. Its location is saved from your GPS when you check in, then your supervisor confirms it.',
                          style: TextStyle(fontWeight: FontWeight.w600))),
                ]),
              ),
              const SizedBox(height: 14),
              DropdownButtonFormField<String>(
                value: _campaignId,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Campaign'),
                items: [for (final a in options) DropdownMenuItem(value: a.id, child: Text(a.name, overflow: TextOverflow.ellipsis))],
                onChanged: (v) => setState(() {
                  _campaignId = v;
                  final c = options.where((x) => x.id == v);
                  if (c.isNotEmpty && _types.containsKey(c.first.defaultType)) _type = c.first.defaultType;
                }),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _name,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(labelText: 'Outlet name *'),
                validator: (v) => (v == null || v.trim().length < 2) ? 'Enter the outlet name' : null,
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                value: _type,
                isExpanded: true,
                decoration: const InputDecoration(labelText: 'Type'),
                items: [for (final e in _types.entries) DropdownMenuItem(value: e.key, child: Text(e.value))],
                onChanged: (v) => setState(() => _type = v ?? 'retail_store'),
              ),
              const SizedBox(height: 12),
              TextFormField(controller: _town, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Town / area')),
              const SizedBox(height: 12),
              TextFormField(controller: _address, textCapitalization: TextCapitalization.sentences, decoration: const InputDecoration(labelText: 'Street / landmark')),
              const SizedBox(height: 12),
              TextFormField(controller: _contact, textCapitalization: TextCapitalization.words, decoration: const InputDecoration(labelText: 'Contact person')),
              const SizedBox(height: 12),
              TextFormField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Contact phone')),
              const SizedBox(height: 12),
              TextFormField(controller: _notes, maxLines: 2, textCapitalization: TextCapitalization.sentences, decoration: const InputDecoration(labelText: 'Notes')),
              const SizedBox(height: 20),
              FilledButton.icon(
                onPressed: _saving ? null : () => _save(options),
                icon: _saving ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Icon(Icons.check),
                label: const Text('Save & start outlet'),
              ),
            ]),
          );
        },
      ),
    );
  }
}
