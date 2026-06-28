import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/brand_mark.dart';
import 'bloc/auth_bloc.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});
  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  void _submit() {
    if (_formKey.currentState!.validate()) {
      context.read<AuthBloc>().add(AuthLoginRequested(_email.text.trim(), _password.text));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const BrandMark(),
                    const SizedBox(height: 28),
                    Text(context.t('auth.signIn'),
                        style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 6),
                    Text(context.t('auth.signInSub'),
                        style: const TextStyle(color: AppPalette.mutedForeground)),
                    const SizedBox(height: 24),
                    BlocConsumer<AuthBloc, AuthState>(
                      listenWhen: (p, c) => c.error != null && c.error != p.error,
                      listener: (context, state) {},
                      builder: (context, state) {
                        final loading = state.status == AuthStatus.submitting;
                        return Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            if (state.error != null) _ErrorBanner(state.error!),
                            TextFormField(
                              controller: _email,
                              keyboardType: TextInputType.emailAddress,
                              decoration: InputDecoration(
                                labelText: context.t('auth.email'),
                                prefixIcon: const Icon(LucideIcons.mail, size: 18),
                              ),
                              validator: (v) =>
                                  (v == null || !v.contains('@')) ? 'Enter a valid email' : null,
                            ),
                            const SizedBox(height: 14),
                            TextFormField(
                              controller: _password,
                              obscureText: true,
                              decoration: InputDecoration(
                                labelText: context.t('auth.password'),
                                prefixIcon: const Icon(LucideIcons.lock, size: 18),
                              ),
                              validator: (v) =>
                                  (v == null || v.isEmpty) ? 'Enter your password' : null,
                            ),
                            Align(
                              alignment: AlignmentDirectional.centerEnd,
                              child: TextButton(
                                onPressed: () => context.push('/forgot-password'),
                                child: Text(context.t('auth.forgot')),
                              ),
                            ),
                            const SizedBox(height: 6),
                            FilledButton(
                              onPressed: loading ? null : _submit,
                              child: loading
                                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
                                  : Text(context.t('auth.signIn')),
                            ),
                          ],
                        );
                      },
                    ),
                    const SizedBox(height: 16),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(context.t('auth.noAccount'),
                            style: const TextStyle(color: AppPalette.mutedForeground)),
                        TextButton(
                          onPressed: () => context.push('/register'),
                          child: Text(context.t('auth.signUp')),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner(this.message);
  final String message;
  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppPalette.destructive.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppPalette.destructive.withValues(alpha: 0.3)),
      ),
      child: Text(message, style: const TextStyle(color: AppPalette.destructive, fontSize: 13)),
    );
  }
}
