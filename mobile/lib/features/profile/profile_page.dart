import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../app/theme.dart';
import '../../core/config.dart';
import '../../state/providers.dart';
import '../common/widgets.dart';

class ProfilePage extends ConsumerWidget {
  const ProfilePage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    final user = session.user;
    final roles = (user?['roles'] as List?)?.map((r) => (r as Map)['name']).join(', ') ?? '';
    final profile = user?['agent_profile'] is Map ? (user!['agent_profile'] as Map) : null;
    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        AppCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(session.userName, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 8),
            KeyValue('Email', '${user?['email'] ?? '-'}'),
            if (user?['username'] != null) KeyValue('Username', '${user?['username']}'),
            if (user?['phone'] != null) KeyValue('Phone', '${user?['phone']}'),
            KeyValue('Role', roles.isEmpty ? '-' : roles),
            if (profile?['agent_code'] != null) KeyValue('Agent code', '${profile?['agent_code']}'),
            if (profile?['region'] != null) KeyValue('Region', '${profile?['region']}'),
          ]),
        ),
        const SectionTitle('App'),
        AppCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            KeyValue('Version', AppConfig.appVersion),
            KeyValue('Server', session.baseUrl),
          ]),
        ),
        const SectionTitle('Account'),
        OutlinedButton.icon(
          onPressed: () async {
            await session.refreshProfile();
            if (context.mounted) toast(context, 'Profile refreshed.');
          },
          icon: const Icon(Icons.refresh),
          label: const Text('Refresh profile'),
        ),
        const SizedBox(height: 10),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(foregroundColor: Brand.bad),
          onPressed: () async {
            final counts = await ref.read(repoProvider).outboxCounts();
            final unsynced = (counts['PENDING'] ?? 0) + (counts['FAILED'] ?? 0) + (counts['CONFLICT'] ?? 0) + (counts['SYNCING'] ?? 0);
            if (!context.mounted) return;
            final yes = await confirm(context,
                title: 'Sign out?',
                message: unsynced > 0
                    ? 'You still have $unsynced item${unsynced == 1 ? '' : 's'} that have not been sent. They stay safely on this phone and sync after you sign in again - but please sync first if you can.'
                    : 'You will need to sign in again.',
                yes: 'Sign out',
                danger: true);
            if (yes) await session.logout();
          },
          icon: const Icon(Icons.logout),
          label: const Text('Sign out'),
        ),
        const SizedBox(height: 24),
        const Text('To change your password, use the web app or ask your administrator.', style: TextStyle(color: Brand.muted, fontSize: 12.5)),
      ]),
    );
  }
}
