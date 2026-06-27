import 'package:dio/dio.dart';
import 'package:hubby_global/core/api_client.dart';
import 'package:shared_preferences/shared_preferences.dart';

class AuthService {
  final ApiClient _apiClient = ApiClient();

  Future<bool> login(String email, String password) async {
    try {
      final response = await _apiClient.dio.post('/login', data: {
        'email': email,
        'password': password,
        'device_name': 'mobile_app',
      });

      if (response.statusCode == 200) {
        await _saveAuthData(response.data);
        return true;
      }
      return false;
    } catch (e) {
      print('Login Error: $e');
      return false;
    }
  }

  Future<bool> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
    required String organizationName,
  }) async {
    try {
      final response = await _apiClient.dio.post('/register', data: {
        'name': name,
        'email': email,
        'password': password,
        'password_confirmation': passwordConfirmation,
        'organization_name': organizationName,
        'device_name': 'mobile_app',
      });

      if (response.statusCode == 201 || response.statusCode == 200) {
        await _saveAuthData(response.data);
        return true;
      }
      return false;
    } catch (e) {
      print('Register Error: $e');
      return false;
    }
  }

  Future<void> _saveAuthData(Map<String, dynamic> data) async {
    final token = data['access_token'];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('auth_token', token);
    
    // Store user data as JSON
    await prefs.setString('user_data', data['user'].toString()); 

    if (data['user']['organizations'] != null && 
        data['user']['organizations'].isNotEmpty) {
      // Default to the first organization if none is active
      if (!prefs.containsKey('active_org_id')) {
        await prefs.setInt('active_org_id', data['user']['organizations'][0]['id']);
      }
    }
  }

  Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('auth_token');
    await prefs.remove('active_org_id');
  }

  Future<bool> isAuthenticated() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.containsKey('auth_token');
  }
}
