import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/brand_mark.dart';
import 'bloc/auth_bloc.dart';

class RegisterPage extends StatefulWidget {
  const RegisterPage({super.key});
  @override
  State<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends State<RegisterPage> {
  final _name = TextEditingController();
  final _org = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  @override
  void dispose() {
    _name.dispose(); _org.dispose(); _email.dispose(); _password.dispose();
    super.dispose();
  }

  void _submit() {
    if (_formKey.currentState!.validate()) {
      context.read<AuthBloc>().add(AuthRegisterRequested(
            name: _name.text.trim(),
            email: _email.text.trim(),
            password: _password.text,
            organizationName: _org.text.trim(),
          ));
    }
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
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const BrandMark(),
                    const SizedBox(height: 24),
                    Text(context.t('auth.createAccount'),
                        style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold)),
                    const SizedBox(height: 20),
                    BlocBuilder<AuthBloc, AuthState>(
                      builder: (context, state) {
                        final loading = state.status == AuthStatus.submitting;
                        return Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            if (state.error != null)
                              Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: Text(state.error!,
                                    style: const TextStyle(color: AppPalette.destructive)),
                              ),
                            _field(_name, context.t('auth.name'), (v) => v!.isEmpty ? 'Required' : null),
                            const SizedBox(height: 12),
                            _field(_org, context.t('auth.org'), (v) => v!.isEmpty ? 'Required' : null),
                            const SizedBox(height: 12),
                            _field(_email, context.t('auth.email'),
                                (v) => v!.contains('@') ? null : 'Enter a valid email',
                                keyboard: TextInputType.emailAddress),
                            const SizedBox(height: 12),
                            _field(_password, context.t('auth.password'),
                                (v) => v!.length < 8 ? 'Min 8 characters' : null, obscure: true),
                            const SizedBox(height: 18),
                            FilledButton(
                              onPressed: loading ? null : _submit,
                              child: loading
                                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
                                  : Text(context.t('auth.createAccount')),
                            ),
                          ],
                        );
                      },
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

  Widget _field(TextEditingController c, String label, String? Function(String?) val,
      {bool obscure = false, TextInputType? keyboard}) {
    return TextFormField(
      controller: c,
      obscureText: obscure,
      keyboardType: keyboard,
      decoration: InputDecoration(labelText: label),
      validator: val,
    );
  }
}
