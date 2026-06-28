import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/theme/app_palette.dart';
import '../../data/repositories/auth_repository.dart';
import '../../l10n/strings.dart';

class ForgotPasswordPage extends StatefulWidget {
  const ForgotPasswordPage({super.key});
  @override
  State<ForgotPasswordPage> createState() => _ForgotPasswordPageState();
}

class _ForgotPasswordPageState extends State<ForgotPasswordPage> {
  final _email = TextEditingController();
  bool _loading = false;
  bool _sent = false;

  Future<void> _submit() async {
    if (!_email.text.contains('@')) return;
    setState(() => _loading = true);
    try {
      await context.read<AuthRepository>().forgotPassword(_email.text.trim());
    } catch (_) {/* never reveal whether the email exists */}
    if (mounted) setState(() { _loading = false; _sent = true; });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(context.t('auth.resetTitle'),
                      style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 6),
                  Text(context.t('auth.resetSub'),
                      style: const TextStyle(color: AppPalette.mutedForeground)),
                  const SizedBox(height: 24),
                  if (_sent)
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: AppPalette.secondary.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: AppPalette.secondary.withValues(alpha: 0.3)),
                      ),
                      child: Row(children: [
                        const Icon(LucideIcons.mailCheck, color: AppPalette.secondary, size: 18),
                        const SizedBox(width: 10),
                        Expanded(child: Text(context.t('auth.resetSent'),
                            style: const TextStyle(color: AppPalette.secondary, fontSize: 13))),
                      ]),
                    )
                  else ...[
                    TextField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      decoration: InputDecoration(
                        labelText: context.t('auth.email'),
                        prefixIcon: const Icon(LucideIcons.mail, size: 18),
                      ),
                    ),
                    const SizedBox(height: 18),
                    FilledButton(
                      onPressed: _loading ? null : _submit,
                      child: _loading
                          ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
                          : Text(context.t('auth.sendLink')),
                    ),
                  ],
                  const SizedBox(height: 12),
                  TextButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: Text(context.t('auth.backToLogin')),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
