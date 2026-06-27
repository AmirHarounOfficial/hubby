import 'package:flutter/material.dart';
import 'package:hubby_global/shared/theme/app_theme.dart';
import 'package:lucide_icons/lucide_icons.dart';

class VerifyScreen extends StatelessWidget {
  const VerifyScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(32.0),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                width: 80,
                height: 80,
                decoration: BoxDecoration(
                  color: AppTheme.secondaryColor.withOpacity(0.1),
                  shape: BoxShape.circle,
                ),
                child: const Icon(LucideIcons.mail, color: AppTheme.secondaryColor, size: 40),
              ),
              const SizedBox(height: 32),
              const Text(
                'Verify your email',
                style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 16),
              const Text(
                'We have sent a verification link to your email address. Please click the link to activate your account.',
                textAlign: TextAlign.center,
                style: TextStyle(color: AppTheme.mutedColor, height: 1.5),
              ),
              const SizedBox(height: 48),
              ElevatedButton(
                onPressed: () {},
                child: const Text('Resend Email'),
              ),
              const SizedBox(height: 16),
              TextButton(
                onPressed: () => Navigator.of(context).pop(),
                child: const Text(
                  'Back to Login',
                  style: TextStyle(color: AppTheme.mutedColor),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
