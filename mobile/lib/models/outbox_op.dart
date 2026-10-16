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
