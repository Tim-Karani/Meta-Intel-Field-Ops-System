import 'package:field_ops_mobile/core/api_exception.dart';
import 'package:field_ops_mobile/core/util.dart';
import 'package:field_ops_mobile/models/outbox_op.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('backoff grows and is capped at 15 minutes', () {
    expect(backoffFor(0), const Duration(seconds: 15));
    expect(backoffFor(1), const Duration(seconds: 30));
    expect(backoffFor(3), const Duration(seconds: 120));
    expect(backoffFor(20), const Duration(minutes: 15));
  });

  test('a longer server hint wins over our backoff', () {
    expect(backoffFor(0, serverHint: const Duration(minutes: 2)), const Duration(minutes: 2));
    expect(backoffFor(6, serverHint: const Duration(seconds: 5)), const Duration(minutes: 15));
  });

  test('status codes map to the right retry policy', () {
    expect(ApiException.kindForStatus(null), ApiErrorKind.network);
    expect(ApiException.kindForStatus(401), ApiErrorKind.unauthorized);
    expect(ApiException.kindForStatus(403), ApiErrorKind.forbidden);
    expect(ApiException.kindForStatus(404), ApiErrorKind.notFound);
    expect(ApiException.kindForStatus(409), ApiErrorKind.conflict);
    expect(ApiException.kindForStatus(422), ApiErrorKind.validation);
    expect(ApiException.kindForStatus(429), ApiErrorKind.rateLimited);
    expect(ApiException.kindForStatus(503), ApiErrorKind.server);

    ApiException e(int s) => ApiException(kind: ApiException.kindForStatus(s), message: 'x', status: s);
    expect(e(503).retryable, isTrue);
    expect(e(429).retryable, isTrue);
    expect(e(422).retryable, isFalse);
    expect(e(422).permanent, isTrue);
    expect(e(401).permanent, isFalse); // needs sign-in, not a data fix
  });

  test('field errors are appended to the message', () {
    final e = ApiException(kind: ApiErrorKind.validation, message: 'The given data was invalid.', errors: {'items.0.quantity': ['Too many.']});
    expect(e.display, contains('Too many.'));
  });

  test('distance is roughly right', () {
    // About 111 km per degree of latitude.
    final d = distanceMeters(0, 36, 1, 36);
    expect(d, greaterThan(110000));
    expect(d, lessThan(112000));
    expect(distanceMeters(-1.2673, 36.8118, -1.2673, 36.8118), closeTo(0, 0.001));
  });

  test('outbox labels are human readable', () {
    expect(OutboxOp.labelFor('visit_checkin'), 'Check in');
    expect(OutboxOp.labelFor('photo_upload'), 'Photo upload');
  });
}
