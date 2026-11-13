import 'package:flutter_test/flutter_test.dart';
import 'package:field_ops_mobile/core/location_service.dart';
import 'package:field_ops_mobile/core/photo_stamp.dart';
import 'package:field_ops_mobile/models/local_visit.dart';

void main() {
  group('ProjectRules', () {
    test('missing settings mean everything is on', () {
      const r = ProjectRules();
      expect(r.taskOn('sales'), isTrue);
      expect(r.minPhotos, 0);
      expect(r.visitPhotos, isTrue, reason: 'check-in/out photos are required unless a project turns them off');
    });
    test('a project can switch the check-in/out photos off', () {
      expect(ProjectRules.from({'visit_photos': false}).visitPhotos, isFalse);
    });
    test('reads a template setup', () {
      final r = ProjectRules.from({
        'tasks': {'form': true, 'sales': false, 'osa': true, 'merchandising': true, 'photos': true},
        'min_photos': 2,
        'visit_label': 'Activation',
      });
      expect(r.taskOn('sales'), isFalse);
      expect(r.taskOn('osa'), isTrue);
      expect(r.minPhotos, 2);
      expect(r.visitLabel, 'Activation');
    });
  });

  group('OutletCampaign', () {
    test('carries the server decision and reason', () {
      final c = OutletCampaign({'id': 'c1', 'name': 'Acme', 'can_add_outlet': false, 'add_outlet_denial': 'Follow your plan.', 'settings': {'outlet_type': 'kiosk'}});
      expect(c.canAdd, isFalse);
      expect(c.denial, 'Follow your plan.');
      expect(c.defaultType, 'kiosk');
    });
  });

  group('photo stamp', () {
    final gps = GpsReading(latitude: -1.28634, longitude: 36.81722, accuracyM: 11.6, timestamp: DateTime(2026, 10, 21, 14, 24, 5), mocked: false);
    test('a title line is added for check-in and check-out photos', () {
      final lines = stampLines(takenAt: DateTime(2026, 10, 21, 14, 24, 5), gps: gps, outlet: 'Naivas', agent: 'Demo', title: 'CHECK-IN');
      expect(lines.first, 'CHECK-IN');
      expect(lines.length, 4);
    });
    test('has date/time, position and outlet/agent lines', () {
      final lines = stampLines(takenAt: DateTime(2026, 10, 21, 14, 24, 5), gps: gps, outlet: 'Naivas Westlands', agent: 'Demo Agent 1');
      expect(lines.length, 3);
      expect(lines[0], contains('21 Oct 2026'));
      expect(lines[0], contains('14:24:05'));
      expect(lines[1], contains('-1.28634, 36.81722'));
      expect(lines[1], contains('+/-12 m'));
      expect(lines[2], contains('Naivas Westlands'));
      expect(lines[2], contains('Demo Agent 1'));
    });
    test('survives missing GPS and non-ASCII names', () {
      final lines = stampLines(takenAt: DateTime(2026, 1, 2, 3, 4, 5), gps: null, outlet: 'Café Ñandú', agent: 'Wanjiru');
      expect(lines[1], contains('not available'));
      expect(lines[2], isNot(contains('é')));
    });
  });
}
