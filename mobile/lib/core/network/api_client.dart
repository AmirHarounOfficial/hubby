import 'package:dio/dio.dart';
import '../config.dart';
import '../storage/session_store.dart';

/// Thin Dio wrapper that injects the Sanctum bearer token and the active
/// organization header on every request, and surfaces a global 401 callback.
class ApiClient {
  ApiClient(this._session) {
    dio = Dio(
      BaseOptions(
        baseUrl: AppConfig.apiBaseUrl,
        headers: {'Accept': 'application/json'},
        connectTimeout: const Duration(seconds: 30),
        receiveTimeout: const Duration(seconds: 60),
      ),
    );

    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _session.token;
          final org = await _session.activeOrgId;
          if (token != null) options.headers['Authorization'] = 'Bearer $token';
          if (org != null) options.headers['X-Organization-Id'] = org;
          handler.next(options);
        },
        onError: (e, handler) {
          if (e.response?.statusCode == 401) onUnauthorized?.call();
          handler.next(e);
        },
      ),
    );
  }

  final SessionStore _session;
  late final Dio dio;

  /// Invoked when any request returns 401 (token expired / revoked).
  void Function()? onUnauthorized;

  /// Pull a human-readable message out of a Dio error (Laravel validation/error shape).
  static String messageFrom(Object error, [String fallback = 'Something went wrong.']) {
    if (error is DioException) {
      final data = error.response?.data;
      if (data is Map) {
        if (data['message'] is String && (data['message'] as String).isNotEmpty) {
          return data['message'] as String;
        }
        final errors = data['errors'];
        if (errors is Map && errors.isNotEmpty) {
          final first = errors.values.first;
          if (first is List && first.isNotEmpty) return first.first.toString();
        }
      }
      if (error.type == DioExceptionType.connectionTimeout ||
          error.type == DioExceptionType.receiveTimeout) {
        return 'The server took too long to respond. Please try again.';
      }
      if (error.type == DioExceptionType.connectionError) {
        return 'Could not reach the server. Check your connection.';
      }
    }
    return fallback;
  }
}
