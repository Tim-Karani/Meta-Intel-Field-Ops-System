import 'dart:async';

import 'package:dio/dio.dart';

import 'api_exception.dart';
import 'config.dart';

typedef TokenReader = Future<String?> Function();

/// Thin wrapper over Dio that understands the API envelope `{success, message, data, meta}`.
class ApiClient {
  ApiClient({required String baseUrl, required TokenReader readToken, this.onUnauthorized}) : _readToken = readToken {
    _dio = Dio(BaseOptions(
      baseUrl: _normalise(baseUrl),
      connectTimeout: const Duration(seconds: 12),
      receiveTimeout: const Duration(seconds: 40),
      sendTimeout: const Duration(seconds: 90),
      headers: {'Accept': 'application/json', 'X-App-Version': AppConfig.appVersion},
      validateStatus: (s) => s != null && s >= 200 && s < 300,
    ));
    _dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) async {
      if (options.extra['auth'] != false) {
        final t = await _readToken();
        if (t != null) options.headers['Authorization'] = 'Bearer $t';
      }
      handler.next(options);
    }));
  }

  final TokenReader _readToken;
  final void Function()? onUnauthorized;
  late final Dio _dio;

  String get baseUrl => _dio.options.baseUrl;

  static String _normalise(String url) {
    var u = url.trim();
    while (u.endsWith('/')) {
      u = u.substring(0, u.length - 1);
    }
    return '$u/';
  }

  Future<dynamic> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get<dynamic>(path, queryParameters: query));

  Future<dynamic> post(String path, {Object? body, bool auth = true}) =>
      _send(() => _dio.post<dynamic>(path, data: body, options: Options(extra: {'auth': auth})));

  Future<dynamic> put(String path, {Object? body}) => _send(() => _dio.put<dynamic>(path, data: body));

  Future<dynamic> delete(String path) => _send(() => _dio.delete<dynamic>(path));

  /// Multipart upload (photos). [fields] values are sent as strings; null values are skipped.
  Future<dynamic> upload(String path, {required String filePath, required String fileName, Map<String, Object?> fields = const {}}) async {
    final form = FormData();
    fields.forEach((k, v) {
      if (v != null) form.fields.add(MapEntry(k, v.toString()));
    });
    form.files.add(MapEntry('file', await MultipartFile.fromFile(filePath, filename: fileName)));
    return _send(() => _dio.post<dynamic>(path, data: form));
  }

  /// Downloads a protected file (e.g. a photo) as bytes.
  Future<List<int>> bytes(String path) async {
    try {
      final r = await _dio.get<List<int>>(path, options: Options(responseType: ResponseType.bytes));
      return r.data ?? <int>[];
    } on DioException catch (e) {
      throw _convert(e);
    }
  }

  Future<dynamic> _send(Future<Response<dynamic>> Function() call) async {
    try {
      final r = await call();
      final body = r.data;
      if (body is Map<String, dynamic> && body.containsKey('success')) return body['data'];
      return body;
    } on DioException catch (e) {
      final ex = _convert(e);
      if (ex.kind == ApiErrorKind.unauthorized) onUnauthorized?.call();
      throw ex;
    }
  }

  ApiException _convert(DioException e) {
    final res = e.response;
    if (res == null) {
      final timedOut = e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.sendTimeout;
      return ApiException(
        kind: ApiErrorKind.network,
        message: timedOut ? 'The server took too long to answer.' : 'No connection to the server.',
      );
    }
    final status = res.statusCode;
    final data = res.data;
    var message = 'Request failed ($status).';
    String? code;
    final errors = <String, List<String>>{};
    if (data is Map) {
      if (data['message'] is String && (data['message'] as String).isNotEmpty) message = data['message'] as String;
      if (data['code'] is String) code = data['code'] as String;
      final raw = data['errors'];
      if (raw is Map) {
        raw.forEach((k, v) {
          errors[k.toString()] = v is List ? v.map((x) => x.toString()).toList() : [v.toString()];
        });
      }
    }
    Duration? retryAfter;
    final ra = res.headers.value('retry-after');
    if (ra != null) {
      final secs = int.tryParse(ra);
      if (secs != null) retryAfter = Duration(seconds: secs.clamp(1, 3600).toInt());
    }
    return ApiException(
      kind: ApiException.kindForStatus(status),
      message: message,
      status: status,
      code: code,
      errors: errors,
      retryAfter: retryAfter,
    );
  }
}
