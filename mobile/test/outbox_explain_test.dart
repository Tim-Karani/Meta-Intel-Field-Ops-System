import 'package:flutter_test/flutter_test.dart';
import 'package:field_ops_mobile/models/outbox_op.dart';

OutboxOp op(String type, int? http, {String? error}) => OutboxOp(
    seq: 1, id: 'x', type: type, entityId: 'v', payload: const {}, status: OpStatus.failed, retryCount: 0,
    nextAttemptAt: null, lastAttemptAt: null, error: error, httpStatus: http, createdAt: '2026-01-01T00:00:00Z');

void main() {
  test('a refused outlet explains the permission and the way out', () {
    final t = op('outlet_create', 403).explanation;
    expect(t, contains('not allowed to add outlets'));
    expect(t, contains('Retry'));
  });
  test('other 403s name the action', () {
    expect(op('visit_sale', 403).explanation, contains('sale'));
  });
  test('validation errors include the server text', () {
    expect(op('visit_submit', 422, error: 'Missing answers').explanation, contains('Missing answers'));
  });
}
