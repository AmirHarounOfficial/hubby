import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

/// Persists auth + preferences across launches.
class SessionStore {
  static const _kToken = 'auth_token';
  static const _kUser = 'auth_user';
  static const _kOrg = 'active_org_id';
  static const _kLocale = 'locale';

  Future<SharedPreferences> get _prefs => SharedPreferences.getInstance();

  Future<void> saveSession({
    required String token,
    required Map<String, dynamic> user,
    int? organizationId,
  }) async {
    final p = await _prefs;
    await p.setString(_kToken, token);
    await p.setString(_kUser, jsonEncode(user));
    if (organizationId != null) await p.setInt(_kOrg, organizationId);
  }

  Future<String?> get token async => (await _prefs).getString(_kToken);

  Future<Map<String, dynamic>?> get user async {
    final raw = (await _prefs).getString(_kUser);
    if (raw == null) return null;
    return jsonDecode(raw) as Map<String, dynamic>;
  }

  Future<int?> get activeOrgId async => (await _prefs).getInt(_kOrg);

  Future<void> setActiveOrgId(int id) async => (await _prefs).setInt(_kOrg, id);

  Future<void> setUser(Map<String, dynamic> user) async =>
      (await _prefs).setString(_kUser, jsonEncode(user));

  Future<String> get localeCode async => (await _prefs).getString(_kLocale) ?? 'en';
  Future<void> setLocale(String code) async => (await _prefs).setString(_kLocale, code);

  Future<void> clear() async {
    final p = await _prefs;
    await p.remove(_kToken);
    await p.remove(_kUser);
    await p.remove(_kOrg);
  }
}
