import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../data/session.dart';
import '../features/attendance/attendance_page.dart';
import '../features/auth/login_page.dart';
import '../features/home/dashboard_page.dart';
import '../features/issues/issues_page.dart';
import '../features/notifications/notifications_page.dart';
import '../features/outlets/add_outlet_page.dart';
import '../features/profile/profile_page.dart';
import '../features/shell/home_shell.dart';
import '../features/shell/more_page.dart';
import '../features/sync/sync_center_page.dart';
import '../features/visits/form_page.dart';
import '../features/visits/merch_page.dart';
import '../features/visits/osa_page.dart';
import '../features/visits/photos_page.dart';
import '../features/visits/sales_page.dart';
import '../features/visits/visit_page.dart';
import '../features/visits/visits_page.dart';
import '../features/work/assignment_detail_page.dart';
import '../features/work/assignments_page.dart';
import '../features/work/routes_page.dart';

GoRouter buildRouter(Session session) {
  return GoRouter(
    initialLocation: '/home',
    refreshListenable: session,
    redirect: (context, state) {
      final atLogin = state.matchedLocation == '/login';
      if (!session.signedIn) return atLogin ? null : '/login';
      // An expired session keeps the user inside the app (their work is on the phone); they re-authenticate from Profile or the banner.
      if (atLogin && !session.expired) return '/home';
      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (c, s) => const LoginPage()),
      StatefulShellRoute.indexedStack(
        builder: (c, s, shell) => HomeShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/home', builder: (c, s) => const DashboardPage())]),
          StatefulShellBranch(routes: [GoRoute(path: '/work', builder: (c, s) => const AssignmentsPage())]),
          StatefulShellBranch(routes: [GoRoute(path: '/visits', builder: (c, s) => const VisitsPage())]),
          StatefulShellBranch(routes: [GoRoute(path: '/sync', builder: (c, s) => const SyncCenterPage())]),
          StatefulShellBranch(routes: [GoRoute(path: '/more', builder: (c, s) => const MorePage())]),
        ],
      ),
      GoRoute(path: '/assignment/:id', builder: (c, s) => AssignmentDetailPage(id: s.pathParameters['id']!)),
      GoRoute(path: '/visit/:id', builder: (c, s) => VisitPage(visitId: s.pathParameters['id']!)),
      GoRoute(path: '/visit/:id/form', builder: (c, s) => FormPage(visitId: s.pathParameters['id']!)),
      GoRoute(path: '/visit/:id/sales', builder: (c, s) => SalesPage(visitId: s.pathParameters['id']!)),
      GoRoute(path: '/visit/:id/osa', builder: (c, s) => OsaPage(visitId: s.pathParameters['id']!)),
      GoRoute(path: '/visit/:id/merch', builder: (c, s) => MerchPage(visitId: s.pathParameters['id']!)),
      GoRoute(path: '/visit/:id/photos', builder: (c, s) => PhotosPage(visitId: s.pathParameters['id']!)),
      GoRoute(path: '/outlet/new', builder: (c, s) => const AddOutletPage()),
      GoRoute(path: '/attendance', builder: (c, s) => const AttendancePage()),
      GoRoute(path: '/routes', builder: (c, s) => const RoutesPage()),
      GoRoute(path: '/issues', builder: (c, s) => const IssuesPage()),
      GoRoute(path: '/notifications', builder: (c, s) => const NotificationsPage()),
      GoRoute(path: '/profile', builder: (c, s) => const ProfilePage()),
    ],
    errorBuilder: (c, s) => Scaffold(appBar: AppBar(title: const Text('Not found')), body: const Center(child: Text('That screen does not exist.'))),
  );
}
