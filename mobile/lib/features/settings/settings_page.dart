import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../auth/bloc/auth_bloc.dart';

class SettingsPage extends StatefulWidget {
  const SettingsPage({super.key});
  @override
  State<SettingsPage> createState() => _SettingsPageState();
}

class _SettingsPageState extends State<SettingsPage> {
  late final _name = TextEditingController(text: context.read<AuthBloc>().state.userName);
  late final _email = TextEditingController(text: context.read<AuthBloc>().state.userEmail);
  late final _orgName = TextEditingController(text: _firstOrgName());

  final _currentPw = TextEditingController();
  final _newPw = TextEditingController();
  final _confirmPw = TextEditingController();

  bool _savingProfile = false, _savingPw = false, _savingOrg = false;
  Map<String, bool> _prefs = {};
  bool _prefsLoading = true;

  String _firstOrgName() {
    final orgs = context.read<AuthBloc>().state.organizations;
    return orgs.isNotEmpty ? (orgs.first['name']?.toString() ?? '') : '';
  }

  @override
  void initState() {
    super.initState();
    _loadPrefs();
  }

  Future<void> _loadPrefs() async {
    try {
      final res = await context.read<ApiClient>().dio.get('/notification-preferences');
      setState(() {
        _prefs = (res.data as Map).map((k, v) => MapEntry(k.toString(), v == true));
        _prefsLoading = false;
      });
    } catch (_) {
      setState(() => _prefsLoading = false);
    }
  }

  Future<void> _saveProfile() async {
    setState(() => _savingProfile = true);
    try {
      await context.read<ApiClient>().dio.put('/profile', data: {'name': _name.text.trim(), 'email': _email.text.trim()});
      if (mounted) showToast(context, 'Profile updated.', ToastKind.success);
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _savingProfile = false);
    }
  }

  Future<void> _savePassword() async {
    if (_newPw.text.length < 8) {
      showToast(context, 'New password must be at least 8 characters.', ToastKind.error);
      return;
    }
    if (_newPw.text != _confirmPw.text) {
      showToast(context, 'New password and confirmation do not match.', ToastKind.error);
      return;
    }
    setState(() => _savingPw = true);
    try {
      await context.read<ApiClient>().dio.put('/password', data: {
        'current_password': _currentPw.text,
        'password': _newPw.text,
        'password_confirmation': _confirmPw.text,
      });
      _currentPw.clear();
      _newPw.clear();
      _confirmPw.clear();
      if (mounted) showToast(context, 'Password changed.', ToastKind.success);
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _savingPw = false);
    }
  }

  Future<void> _saveOrg() async {
    setState(() => _savingOrg = true);
    try {
      await context.read<ApiClient>().dio.put('/organization', data: {'name': _orgName.text.trim()});
      if (mounted) showToast(context, 'Organization updated.', ToastKind.success);
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _savingOrg = false);
    }
  }

  Future<void> _togglePref(String key, bool value) async {
    setState(() => _prefs[key] = value);
    try {
      await context.read<ApiClient>().dio.put('/notification-preferences', data: {key: value});
    } catch (e) {
      if (mounted) {
        setState(() => _prefs[key] = !value);
        showToast(context, 'Could not save preference.', ToastKind.error);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('nav.settings'))),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _section(context.t('settings.profile'), [
            TextField(controller: _name, decoration: InputDecoration(labelText: context.t('settings.fullName'))),
            const SizedBox(height: 12),
            TextField(controller: _email, decoration: InputDecoration(labelText: context.t('auth.email'))),
            const SizedBox(height: 14),
            _saveButton(context.t('settings.saveProfile'), _savingProfile, _saveProfile),
          ]),
          const SizedBox(height: 16),
          _section(context.t('settings.changePassword'), [
            TextField(controller: _currentPw, obscureText: true,
                decoration: InputDecoration(labelText: context.t('settings.currentPassword'))),
            const SizedBox(height: 12),
            TextField(controller: _newPw, obscureText: true,
                decoration: InputDecoration(labelText: context.t('settings.newPassword'), helperText: 'Use at least 8 characters')),
            const SizedBox(height: 12),
            TextField(controller: _confirmPw, obscureText: true,
                decoration: InputDecoration(labelText: context.t('settings.confirmNewPassword'))),
            const SizedBox(height: 14),
            _saveButton(context.t('settings.updatePassword'), _savingPw, _savePassword),
          ]),
          const SizedBox(height: 16),
          _section(context.t('settings.organization'), [
            TextField(controller: _orgName, decoration: InputDecoration(labelText: context.t('settings.orgName'))),
            const SizedBox(height: 14),
            _saveButton(context.t('settings.saveOrg'), _savingOrg, _saveOrg),
          ]),
          const SizedBox(height: 16),
          _section(context.t('nav.notifications'), [
            if (_prefsLoading)
              const Padding(padding: EdgeInsets.all(8), child: LinearProgressIndicator())
            else
              ..._prefLabels.entries.map((e) => SwitchListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(e.value),
                    value: _prefs[e.key] ?? false,
                    activeThumbColor: AppPalette.primary,
                    onChanged: (v) => _togglePref(e.key, v),
                  )),
          ]),
          const SizedBox(height: 16),
          OutlinedButton(
            onPressed: () => context.read<AuthBloc>().add(const AuthLoggedOut()),
            style: OutlinedButton.styleFrom(foregroundColor: AppPalette.destructive),
            child: Text(context.t('common.logout')),
          ),
        ],
      ),
    );
  }

  static const _prefLabels = {
    'new_orders': 'New orders',
    'inventory_alerts': 'Inventory alerts',
    'security_updates': 'Security updates',
    'marketing': 'Product news & offers',
  };

  Widget _section(String title, List<Widget> children) => AppCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 14),
          ...children,
        ]),
      );

  Widget _saveButton(String label, bool loading, VoidCallback onTap) => FilledButton(
        onPressed: loading ? null : onTap,
        child: loading
            ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
            : Text(label),
      );
}
