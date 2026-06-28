import '../../core/network/api_client.dart';
import '../../core/storage/session_store.dart';

class AuthResult {
  AuthResult(this.user, this.organizations);
  final Map<String, dynamic> user;
  final List<dynamic> organizations;
}

class AuthRepository {
  AuthRepository(this._api, this._session);

  final ApiClient _api;
  final SessionStore _session;

  Future<AuthResult> login(String email, String password) async {
    final res = await _api.dio.post('/login', data: {
      'email': email,
      'password': password,
    });
    return _persist(res.data);
  }

  Future<AuthResult> register({
    required String name,
    required String email,
    required String password,
    required String organizationName,
  }) async {
    final res = await _api.dio.post('/register', data: {
      'name': name,
      'email': email,
      'password': password,
      'password_confirmation': password,
      'organization_name': organizationName,
    });
    return _persist(res.data);
  }

  Future<void> forgotPassword(String email) =>
      _api.dio.post('/password/forgot', data: {'email': email});

  Future<AuthResult?> currentUser() async {
    if (await _session.token == null) return null;
    final res = await _api.dio.get('/me');
    final user = res.data as Map<String, dynamic>;
    await _session.setUser(user);
    final orgs = (user['organizations'] as List?) ?? [];
    return AuthResult(user, orgs);
  }

  Future<void> logout() async {
    try {
      await _api.dio.post('/logout');
    } catch (_) {
      // best-effort; clear locally regardless
    }
    await _session.clear();
  }

  Future<AuthResult> _persist(dynamic data) async {
    final token = data['access_token'] as String;
    final user = data['user'] as Map<String, dynamic>;
    final orgs = (user['organizations'] as List?) ?? [];
    final firstOrgId = orgs.isNotEmpty ? orgs.first['id'] as int : null;
    await _session.saveSession(token: token, user: user, organizationId: firstOrgId);
    return AuthResult(user, orgs);
  }
}
