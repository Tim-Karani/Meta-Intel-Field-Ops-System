import 'dart:convert';

import '../core/util.dart';

/// A visit as stored on the device. [localId] is also the idempotency key (client_uuid) sent to the server.
class LocalVisit {
  LocalVisit({
    required this.localId,
    required this.serverId,
    required this.assignmentId,
    required this.formId,
    required this.campaignId,
    required this.locationId,
    required this.status,
    required this.visitDate,
    required this.answers,
    required this.notes,
    required this.checkin,
    required this.checkout,
    required this.reviewNote,
    required this.createdAt,
    required this.updatedAt,
  });

  final String localId;
  final String? serverId;
  final String assignmentId;
  final String? formId;
  final String? campaignId;
  final String? locationId;

  /// scheduled -> started -> completed -> submitted, then (server-owned) under_review / approved / rejected.
  final String status;
  final String? visitDate;
  final Map<String, dynamic> answers;
  final String? notes;
  final Map<String, dynamic>? checkin;
  final Map<String, dynamic>? checkout;
  final String? reviewNote;
  final String createdAt;
  final String updatedAt;

  factory LocalVisit.fromRow(Map<String, Object?> r) {
    Map<String, dynamic>? decode(String? s) {
      if (s == null || s.isEmpty) return null;
      try {
        return (jsonDecode(s) as Map).cast<String, dynamic>();
      } catch (_) {
        return null;
      }
    }

    return LocalVisit(
      localId: r['local_id'] as String,
      serverId: r['server_id'] as String?,
      assignmentId: r['assignment_id'] as String,
      formId: r['form_id'] as String?,
      campaignId: r['campaign_id'] as String?,
      locationId: r['location_id'] as String?,
      status: r['status'] as String,
      visitDate: r['visit_date'] as String?,
      answers: decode(r['answers'] as String?) ?? <String, dynamic>{},
      notes: r['notes'] as String?,
      checkin: decode(r['checkin'] as String?),
      checkout: decode(r['checkout'] as String?),
      reviewNote: r['review_note'] as String?,
      createdAt: r['created_at'] as String,
      updatedAt: r['updated_at'] as String,
    );
  }

  bool get isEditable => status == 'scheduled' || status == 'started' || status == 'completed';
  bool get isActive => isEditable;
  bool get isRejected => status == 'rejected';
  bool get isClosed => status == 'approved';

  String get statusLabel => titleCaseStatus(status);

  static String titleCaseStatus(String s) {
    final words = s.replaceAll('_', ' ');
    return words.isEmpty ? s : words[0].toUpperCase() + words.substring(1);
  }
}

/// A server assignment plus what we know about it locally.
class AssignmentView {
  AssignmentView(this.raw, {required this.removed});

  final Map<String, dynamic> raw;
  final bool removed;

  String get id => raw.str('id') ?? '';
  String get status => raw.str('status') ?? 'pending';
  String get scheduledDate => (raw.str('scheduled_date') ?? '').split('T').first;
  String? get startTime => raw.str('start_time');
  String? get endTime => raw.str('end_time');
  String? get instructions => raw.str('instructions');
  int? get target => raw.integer('target');
  String? get formId => raw.str('form_id');
  String? get campaignId => raw.str('campaign_id');
  String get campaignName => raw.obj('campaign')?.str('name') ?? 'Campaign';
  String? get clientName => raw.obj('campaign')?.obj('client')?.str('name');
  String? get activityName => raw.obj('activity')?.str('name');
  String? get supervisorName => raw.obj('supervisor')?.str('name');
  Map<String, dynamic>? get location => raw.obj('location');
  String? get locationId => raw.str('location_id');
  String get locationName => location?.str('name') ?? 'Location';
  String? get town => location?.str('town');
  String? get address => location?.str('address');
  double? get lat => location?.dbl('latitude');
  double? get lng => location?.dbl('longitude');
  int get radius => location?.integer('geofence_radius_m') ?? 100;
  /// An outlet with no coordinates yet: the first check-in here maps it.
  bool get isUnmapped => lat == null || lng == null;
  bool get needsReview => location?['needs_review'] == true;
  bool get addedInField => location?.str('created_via') == 'field';
  bool get isCancelled => status == 'cancelled';
  bool get isDone => status == 'completed';
  bool get needsAccept => status == 'pending';

  /// Where the visit came from: 'journey_plan' (fixed plan), 'field' (added by the agent) or 'office'.
  String get source => raw.str('source') ?? 'office';

  /// The project's rules (from its template, tuned per campaign). Missing = the permissive defaults.
  ProjectRules get rules => ProjectRules.from(raw.obj('campaign')?.obj('settings'));
}

/// What a project asks of the agent on a visit. Mirrors the server's ProjectSettings.
class ProjectRules {
  const ProjectRules({this.tasks = const {}, this.minPhotos = 0, this.visitLabel = 'Visit', this.visitPhotos = true});

  final Map<String, bool> tasks;
  final int minPhotos;
  final String visitLabel;

  /// Check-in and check-out photos are required (they prove the assigned agent is the one at the outlet).
  final bool visitPhotos;

  factory ProjectRules.from(Map<String, dynamic>? s) {
    if (s == null) return const ProjectRules();
    final t = s.obj('tasks') ?? const <String, dynamic>{};
    return ProjectRules(
      tasks: {for (final k in const ['form', 'sales', 'osa', 'merchandising', 'photos']) k: t[k] != false},
      minPhotos: s.integer('min_photos') ?? 0,
      visitLabel: (s.str('visit_label') ?? '').trim().isEmpty ? 'Visit' : s.str('visit_label')!.trim(),
      visitPhotos: s['visit_photos'] != false,
    );
  }

  bool taskOn(String key) => tasks[key] ?? true;
}

/// A campaign the agent may work in, with whether THEY may add outlets to it (the server decides; this is only shown).
class OutletCampaign {
  OutletCampaign(this.raw);
  final Map<String, dynamic> raw;

  String get id => raw.str('id') ?? '';
  String get name => raw.str('name') ?? 'Campaign';
  String? get clientName => raw.obj('client')?.str('name');
  String? get formId => raw.str('form_id');
  String? get activityId => raw.str('activity_id');
  bool get canAdd => raw['can_add_outlet'] == true;
  String? get denial => raw.str('add_outlet_denial');
  ProjectRules get rules => ProjectRules.from(raw.obj('settings'));
  String get defaultType => raw.obj('settings')?.str('outlet_type') ?? 'retail_store';
}
