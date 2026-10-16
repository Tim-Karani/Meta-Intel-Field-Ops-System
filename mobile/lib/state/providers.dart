import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/location_service.dart';
import '../data/repository.dart';
import '../data/session.dart';
import '../data/sync_service.dart';
import '../models/form_def.dart';
import '../models/local_visit.dart';
import '../models/outbox_op.dart';
import '../core/util.dart';

// Overridden in main() with the real, already-initialised instances.
final repoProvider = Provider<Repository>((ref) => throw UnimplementedError('repoProvider not overridden'));
final sessionProvider = Provider<Session>((ref) => throw UnimplementedError('sessionProvider not overridden'));
final syncServiceProvider = Provider<SyncService>((ref) => throw UnimplementedError('syncServiceProvider not overridden'));
final locationServiceProvider = Provider<LocationService>((ref) => LocationService());

/// Ticks whenever local data changes; data providers watch it so screens refresh themselves.
final revisionProvider = StreamProvider<int>((ref) => ref.watch(repoProvider).changes);

final syncStateProvider = StreamProvider<SyncState>((ref) async* {
  final s = ref.watch(syncServiceProvider);
  yield s.state;
  yield* s.stream;
});

final assignmentsProvider = FutureProvider.autoDispose<List<AssignmentView>>((ref) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).assignments();
});

final assignmentProvider = FutureProvider.autoDispose.family<AssignmentView?, String>((ref, id) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).assignment(id);
});

final visitsProvider = FutureProvider.autoDispose<List<LocalVisit>>((ref) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).visits();
});

final visitProvider = FutureProvider.autoDispose.family<LocalVisit?, String>((ref, id) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).visit(id);
});

final visitForAssignmentProvider = FutureProvider.autoDispose.family<LocalVisit?, String>((ref, assignmentId) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).visitForAssignment(assignmentId);
});

final formProvider = FutureProvider.autoDispose.family<FormDef?, String?>((ref, id) async {
  return ref.watch(repoProvider).form(id);
});

final itemsProvider = FutureProvider.autoDispose.family<List<Map<String, dynamic>>, ({String visitId, String kind})>((ref, k) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).items(k.visitId, k.kind);
});

final photosProvider = FutureProvider.autoDispose.family<List<Map<String, Object?>>, String>((ref, visitId) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).photos(visitId);
});

final productsProvider = FutureProvider.autoDispose.family<List<Map<String, dynamic>>, String?>((ref, campaignId) async {
  return ref.watch(repoProvider).products(campaignId: campaignId);
});

final issuesProvider = FutureProvider.autoDispose<List<Map<String, Object?>>>((ref) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).issues();
});

final notificationsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>((ref) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).notifications();
});

final unreadProvider = FutureProvider.autoDispose<int>((ref) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).unreadNotifications();
});

final outboxProvider = FutureProvider.autoDispose<List<OutboxOp>>((ref) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).outbox(includeSynced: true, limit: 200);
});

final attendanceTodayProvider = FutureProvider.autoDispose<Map<String, Object?>?>((ref) async {
  ref.watch(revisionProvider);
  return ref.watch(repoProvider).attendance(todayStr());
});

final lastSyncProvider = FutureProvider.autoDispose<String?>((ref) async {
  ref.watch(revisionProvider);
  ref.watch(syncStateProvider);
  return ref.watch(repoProvider).meta('last_sync');
});
