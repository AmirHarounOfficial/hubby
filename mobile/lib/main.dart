import 'package:flutter/material.dart';
import 'package:hubby_global/core/router.dart';
import 'package:hubby_global/shared/theme/app_theme.dart';

void main() {
  runApp(const HubbyGlobalApp());
}

class HubbyGlobalApp extends StatelessWidget {
  const HubbyGlobalApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp.router(
      title: 'HubbyGlobal',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.darkTheme,
      routerConfig: AppRouter.router,
    );
  }
}
