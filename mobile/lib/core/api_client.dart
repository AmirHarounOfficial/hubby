import 'package:dio/dio.dart';
import 'package:shared_preferences/shared_preferences.dart';

class ApiClient {
  late Dio dio;
  // Use localhost for Chrome/Web, 10.0.2.2 for Android Emulator
  static const String baseUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'http://127.0.0.1:8000/api',
  );

  ApiClient() {
    dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
        connectTimeout: const Duration(seconds: 30),
        receiveTimeout: const Duration(seconds: 30),
      ),
    );

    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final prefs = await SharedPreferences.getInstance();
          final token = prefs.getString('auth_token');
          final activeOrgId = prefs.getInt('active_org_id');

          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          if (activeOrgId != null) {
            options.headers['X-Organization-Id'] = activeOrgId;
          }

          print('API REQUEST[${options.method}] => PATH: ${options.path}');
          return handler.next(options);
        },
        onResponse: (response, handler) {
          print(
            'API RESPONSE[${response.statusCode}] => PATH: ${response.requestOptions.path}',
          );
          return handler.next(response);
        },
        onError: (DioException e, handler) {
          print(
            'API ERROR[${e.response?.statusCode}] => PATH: ${e.requestOptions.path}',
          );
          print('ERROR DATA: ${e.response?.data}');
          print('ERROR MESSAGE: ${e.message}');

          if (e.response?.statusCode == 401) {
            // Handle unauthorized (e.g. clear token and redirect)
          }
          return handler.next(e);
        },
      ),
    );
  }
}
