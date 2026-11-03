import 'dart:convert';

enum OpStatus { pending, syncing, synced, failed, conflict }

OpStatus opStatusFrom(String s) => OpStatus.values.firstWhere((e) => e.name.toUpperCase() == s, orElse: () => OpStatus.pending);

/// One queued change that still has to reach the server. Statuses follow the sync design:
/// PENDING -> SYNCING -> SYNCED, or FAILED (needs the user) / CONFLICT (server state disagrees).
class OutboxOp {
  OutboxOp({
    required this.seq,
    required this.id,
    required this.type,
    required this.entityId,
    required this.payload,
    required this.status,
    required this.retryCount,
    required this.nextAttemptAt,
    required this.lastAttemptAt,
    required this.error,
    required this.httpStatus,
    required this.createdAt,
  });

  final int seq;
  final String id;
  final String type;
  final String? entityId;
  final Map<String, dynamic> payload;
  final OpStatus status;
  final int retryCount;
  final String? nextAttemptAt;
  final String? lastAttemptAt;
  final String? error;
  final int? httpStatus;
  final String createdAt;

  factory OutboxOp.fromRow(Map<String, Object?> r) {
    Map<String, dynamic> payload;
    try {
      payload = (jsonDecode((r['payload'] as String?) ?? '{}') as Map).cast<String, dynamic>();
    } catch (_) {
      payload = {};
    }
    return OutboxOp(
      seq: r['seq'] as int,
      id: r['id'] as String,
      type: r['type'] as String,
      entityId: r['entity_id'] as String?,
      payload: payload,
      status: opStatusFrom((r['status'] as String?) ?? 'PENDING'),
      retryCount: (r['retry_count'] as int?) ?? 0,
      nextAttemptAt: r['next_attempt_at'] as String?,
      lastAttemptAt: r['last_attempt_at'] as String?,
      error: r['error'] as String?,
      httpStatus: r['http_status'] as int?,
      createdAt: r['created_at'] as String,
    );
  }

  /// Plain-language reason for a failure, and what to do about it. The raw server text is kept in [error].
  String get explanation {
    final what = label.toLowerCase();
    switch (httpStatus) {
      case 403:
        if (type == 'outlet_create') {
          return 'The server refused to add this outlet because your account is not allowed to add outlets (or you are not part of that campaign). '
              'Nothing you entered for it can be sent until this is fixed. Ask your supervisor to give your account the "Add outlets in the field" permission, then tap Retry - or discard this outlet.';
        }
        return 'The server refused "$what": your account does not have permission for this action. Ask your supervisor or administrator. The data is safe on this phone.';
      case 404:
        return 'The server no longer has the record this "$what" belongs to (it may have been removed or reassigned). Ask your supervisor.';
      case 409:
        return 'The server already has a different result for this "$what". Your supervisor needs to look at it.';
      case 422:
      case 400:
        return 'The server did not accept this "$what": ${error ?? 'invalid data'}. Fix the data if you can, or ask your supervisor.';
      default:
        return error ?? 'This step could not be sent.';
    }
  }

  bool get needsAttention => status == OpStatus.failed || status == OpStatus.conflict;

  /// Human label for the Sync Center.
  String get label => OutboxOp.labelFor(type);

  static String labelFor(String type) {
    switch (type) {
      case 'outlet_create':
        return 'Add outlet';
      case 'assignment_accept':
        return 'Accept assignment';
      case 'attendance_clock_in':
        return 'Clock in';
      case 'attendance_clock_out':
        return 'Clock out';
      case 'visit_create':
        return 'Start visit';
      case 'visit_checkin':
        return 'Check in';
      case 'visit_responses':
        return 'Form answers';
      case 'visit_sale':
        return 'Sale';
      case 'visit_osa':
        return 'On-shelf availability';
      case 'visit_merch':
        return 'Merchandising';
      case 'photo_upload':
        return 'Photo upload';
      case 'photo_delete':
        return 'Remove photo';
      case 'visit_checkout':
        return 'Check out';
      case 'visit_submit':
        return 'Submit visit';
      case 'issue_create':
        return 'Issue report';
      case 'notification_read':
        return 'Mark notification read';
      default:
        return type;
    }
  }
}

/// Backoff for retryable failures: 15 s, 30 s, 1 min ... capped at 15 min.
Duration backoffFor(int retryCount, {Duration? serverHint}) {
  final secs = 15 * (1 << retryCount.clamp(0, 6).toInt());
  final d = Duration(seconds: secs > 900 ? 900 : secs);
  if (serverHint != null && serverHint > d) return serverHint;
  return d;
}
