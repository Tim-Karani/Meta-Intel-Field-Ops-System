enum ApiErrorKind { network, unauthorized, forbidden, notFound, conflict, validation, rateLimited, server, unknown }

/// Every failed API call becomes one of these, so callers branch on [kind] rather than on raw status codes.
class ApiException implements Exception {
  ApiException({
    required this.kind,
    required this.message,
    this.status,
    this.code,
    this.errors = const {},
    this.retryAfter,
  });

  final ApiErrorKind kind;
  final String message;
  final int? status;
  final String? code;
  final Map<String, List<String>> errors;
  final Duration? retryAfter;

  /// Worth trying again later without any change by the user.
  bool get retryable =>
      kind == ApiErrorKind.network || kind == ApiErrorKind.server || kind == ApiErrorKind.rateLimited;

  /// Needs a person to look at it (bad data, no permission, state conflict).
  bool get permanent => !retryable && kind != ApiErrorKind.unauthorized;

  /// One readable line, including the first field error when the server gave details.
  String get display {
    if (errors.isNotEmpty) {
      final first = errors.entries.first;
      final detail = first.value.isNotEmpty ? first.value.first : '';
      if (detail.isNotEmpty && !message.contains(detail)) return '$message $detail';
    }
    return message;
  }

  static ApiErrorKind kindForStatus(int? status) {
    if (status == null) return ApiErrorKind.network;
    if (status == 401) return ApiErrorKind.unauthorized;
    if (status == 403) return ApiErrorKind.forbidden;
    if (status == 404) return ApiErrorKind.notFound;
    if (status == 409 || status == 423) return ApiErrorKind.conflict;
    if (status == 400 || status == 422) return ApiErrorKind.validation;
    if (status == 429) return ApiErrorKind.rateLimited;
    if (status >= 500) return ApiErrorKind.server;
    return ApiErrorKind.unknown;
  }

  @override
  String toString() => 'ApiException($kind, $status, $message)';
}
