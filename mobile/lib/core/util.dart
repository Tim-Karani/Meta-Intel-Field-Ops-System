import 'dart:math' as math;

import 'package:intl/intl.dart';
import 'package:uuid/uuid.dart';

const _uuid = Uuid();

/// A fresh random UUID (v4). Used as the offline "local id" and as the server's idempotency key (client_uuid).
String newId() => _uuid.v4();

String nowIso() => DateTime.now().toUtc().toIso8601String();

String todayStr([DateTime? d]) => DateFormat('yyyy-MM-dd').format(d ?? DateTime.now());

String fmtDate(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso);
  return d == null ? '-' : DateFormat('EEE d MMM').format(d.toLocal());
}

String fmtDateTime(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso);
  return d == null ? '-' : DateFormat('d MMM, HH:mm').format(d.toLocal());
}

String fmtTime(String? hhmm) => (hhmm == null || hhmm.length < 5) ? '' : hhmm.substring(0, 5);

String relative(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso);
  if (d == null) return 'never';
  final diff = DateTime.now().difference(d.toLocal());
  if (diff.inSeconds < 45) return 'just now';
  if (diff.inMinutes < 60) return '${diff.inMinutes} min ago';
  if (diff.inHours < 24) return '${diff.inHours} h ago';
  return '${diff.inDays} d ago';
}

String money(num? v) => v == null ? '-' : NumberFormat('#,##0.00').format(v);

/// Great-circle distance in metres (haversine).
double distanceMeters(double lat1, double lon1, double lat2, double lon2) {
  const r = 6371000.0;
  double rad(double d) => d * math.pi / 180;
  final dLat = rad(lat2 - lat1);
  final dLon = rad(lon2 - lon1);
  final a = math.pow(math.sin(dLat / 2), 2) + math.cos(rad(lat1)) * math.cos(rad(lat2)) * math.pow(math.sin(dLon / 2), 2);
  return 2 * r * math.asin(math.min(1, math.sqrt(a)));
}

String fmtDistance(double m) => m < 1000 ? '${m.round()} m' : '${(m / 1000).toStringAsFixed(1)} km';

/// Safe typed reads from decoded JSON.
extension JsonRead on Map<String, dynamic> {
  String? str(String k) {
    final v = this[k];
    return v == null ? null : v.toString();
  }

  double? dbl(String k) {
    final v = this[k];
    if (v is num) return v.toDouble();
    return v == null ? null : double.tryParse(v.toString());
  }

  int? integer(String k) {
    final v = this[k];
    if (v is num) return v.toInt();
    return v == null ? null : int.tryParse(v.toString());
  }

  Map<String, dynamic>? obj(String k) {
    final v = this[k];
    return v is Map ? v.cast<String, dynamic>() : null;
  }

  List<dynamic> list(String k) {
    final v = this[k];
    return v is List ? v : const [];
  }
}
