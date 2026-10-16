import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../data/sync_service.dart';
import '../../state/providers.dart';

/// A white rounded panel. Used instead of the stock Card so styling is identical on every Flutter version.
class AppCard extends StatelessWidget {
  const AppCard({super.key, required this.child, this.padding = const EdgeInsets.all(14), this.onTap, this.color});

  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final box = Container(
      decoration: BoxDecoration(
        color: color ?? Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE3E6EF)),
      ),
      child: Padding(padding: padding, child: child),
    );
    if (onTap == null) return box;
    return Material(
      color: Colors.transparent,
      child: InkWell(borderRadius: BorderRadius.circular(14), onTap: onTap, child: box),
    );
  }
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key});
  final String status;

  static Color colorFor(String s) {
    switch (s) {
      case 'approved':
      case 'completed':
      case 'synced':
      case 'present':
        return Brand.good;
      case 'rejected':
      case 'cancelled':
      case 'failed':
      case 'overdue':
        return Brand.bad;
      case 'started':
      case 'in_progress':
      case 'under_review':
      case 'submitted':
      case 'late':
        return Brand.warn;
      default:
        return Brand.muted;
    }
  }

  @override
  Widget build(BuildContext context) {
    final c = colorFor(status);
    final text = status.replaceAll('_', ' ');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
      decoration: BoxDecoration(color: c.withAlpha(30), borderRadius: BorderRadius.circular(20)),
      child: Text(text[0].toUpperCase() + text.substring(1), style: TextStyle(color: c, fontSize: 12, fontWeight: FontWeight.w700)),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, this.message});
  final IconData icon;
  final String title;
  final String? message;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, size: 48, color: Brand.muted),
            const SizedBox(height: 12),
            Text(title, style: Theme.of(context).textTheme.titleMedium, textAlign: TextAlign.center),
            if (message != null) ...[
              const SizedBox(height: 6),
              Text(message!, style: const TextStyle(color: Brand.muted), textAlign: TextAlign.center),
            ],
          ]),
        ),
      );
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.trailing});
  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(2, 18, 2, 8),
        child: Row(children: [
          Expanded(child: Text(text.toUpperCase(), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800, letterSpacing: .8, color: Brand.muted))),
          if (trailing != null) trailing!,
        ]),
      );
}

void toast(BuildContext context, String message, {bool error = false}) {
  final m = ScaffoldMessenger.of(context);
  m.hideCurrentSnackBar();
  m.showSnackBar(SnackBar(
    content: Text(message),
    backgroundColor: error ? Brand.bad : Brand.ink,
    behavior: SnackBarBehavior.floating,
  ));
}

Future<bool> confirm(BuildContext context, {required String title, required String message, String yes = 'Continue', bool danger = false}) async {
  final r = await showDialog<bool>(
    context: context,
    builder: (c) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
        TextButton(
          onPressed: () => Navigator.pop(c, true),
          child: Text(yes, style: TextStyle(color: danger ? Brand.bad : Brand.ink, fontWeight: FontWeight.w700)),
        ),
      ],
    ),
  );
  return r == true;
}

/// Offline / pending / expired-session strip shown at the top of the main screens.
class SyncBanner extends ConsumerWidget {
  const SyncBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(syncStateProvider).valueOrNull ?? const SyncState();
    final session = ref.watch(sessionProvider);
    String? text;
    Color color = Brand.ink;
    IconData icon = Icons.cloud_done_outlined;
    if (session.expired || s.sessionExpired) {
      text = 'Session expired. Your work is safe on this phone - sign in again to sync.';
      color = Brand.bad;
      icon = Icons.lock_outline;
    } else if (!s.online) {
      text = s.pending > 0 ? 'Offline - ${s.pending} item${s.pending == 1 ? '' : 's'} will sync when you are back online.' : 'Offline - you can keep working.';
      color = Brand.warn;
      icon = Icons.cloud_off_outlined;
    } else if (s.stuck > 0) {
      text = '${s.stuck} item${s.stuck == 1 ? '' : 's'} need your attention in Sync.';
      color = Brand.bad;
      icon = Icons.error_outline;
    } else if (s.running) {
      text = 'Syncing...';
      icon = Icons.sync;
    } else if (s.pending > 0) {
      text = '${s.pending} item${s.pending == 1 ? '' : 's'} waiting to sync.';
      icon = Icons.cloud_upload_outlined;
    }
    if (text == null) return const SizedBox.shrink();
    return Material(
      color: color,
      child: SafeArea(
        top: false,
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          child: Row(children: [
            Icon(icon, size: 16, color: Colors.white),
            const SizedBox(width: 8),
            Expanded(child: Text(text, style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600))),
          ]),
        ),
      ),
    );
  }
}

/// Small label/value row.
class KeyValue extends StatelessWidget {
  const KeyValue(this.label, this.value, {super.key});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 104, child: Text(label, style: const TextStyle(color: Brand.muted, fontSize: 13))),
          Expanded(child: Text(value, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13.5))),
        ]),
      );
}
