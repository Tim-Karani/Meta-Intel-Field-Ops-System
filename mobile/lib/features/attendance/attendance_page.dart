import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/location_service.dart';
import '../../core/util.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

class AttendancePage extends ConsumerStatefulWidget {
  const AttendancePage({super.key});

  @override
  ConsumerState<AttendancePage> createState() => _AttendancePageState();
}

class _AttendancePageState extends ConsumerState<AttendancePage> {
  bool _busy = false;

  Future<GpsReading?> _fix() async {
    final loc = ref.read(locationServiceProvider);
    try {
      return await loc.current();
    } on LocationException catch (e) {
      // Clocking in must still work without GPS; the location is recorded when available.
      final last = await loc.lastKnown();
      if (last == null && mounted) toast(context, '${e.message} Clocking in without a location.');
      return last;
    }
  }

  Future<void> _in() async {
    setState(() => _busy = true);
    try {
      final gps = await _fix();
      await ref.read(repoProvider).clockIn(gps: gps);
      if (mounted) toast(context, 'Clocked in.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _out() async {
    final yes = await confirm(context, title: 'Clock out for the day?', message: 'Make sure your visits are submitted first.', yes: 'Clock out');
    if (!yes) return;
    setState(() => _busy = true);
    try {
      final gps = await _fix();
      await ref.read(repoProvider).clockOut(gps: gps);
      if (mounted) toast(context, 'Clocked out.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _at(Object? raw) {
    if (raw is! String) return null;
    try {
      return (jsonDecode(raw) as Map)['at']?.toString();
    } catch (_) {
      return null;
    }
  }

  @override
  Widget build(BuildContext context) {
    final row = ref.watch(attendanceTodayProvider).valueOrNull;
    final inAt = _at(row?['clock_in']);
    final outAt = _at(row?['clock_out']);
    final status = row?['status'] as String?;
    return Scaffold(
      appBar: AppBar(title: const Text('Attendance')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        AppCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(fmtDate(todayStr()), style: const TextStyle(color: Brand.muted, fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: _Time('Clock in', inAt)),
              Expanded(child: _Time('Clock out', outAt)),
            ]),
            if (status != null) Padding(padding: const EdgeInsets.only(top: 10), child: StatusChip(status)),
          ]),
        ),
        const SizedBox(height: 16),
        if (inAt == null)
          FilledButton.icon(onPressed: _busy ? null : _in, icon: const Icon(Icons.login), label: const Text('Clock in'))
        else if (outAt == null)
          FilledButton.icon(onPressed: _busy ? null : _out, icon: const Icon(Icons.logout), label: const Text('Clock out'))
        else
          const AppCard(color: Color(0xFFE6F5EE), child: Text('Your day is complete. Well done.', style: TextStyle(fontWeight: FontWeight.w700))),
        const SizedBox(height: 12),
        const Text('Clocking works offline. The time recorded is the time you tapped, and it is sent when you are online.', style: TextStyle(color: Brand.muted, fontSize: 12.5)),
      ]),
    );
  }
}

class _Time extends StatelessWidget {
  const _Time(this.label, this.iso);
  final String label;
  final String? iso;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: const TextStyle(color: Brand.muted, fontSize: 13)),
        Text(iso == null ? '--:--' : fmtDateTime(iso).split(', ').last, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
      ]);
}
