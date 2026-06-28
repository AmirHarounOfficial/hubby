import 'dart:async';
import 'package:flutter/widgets.dart';
import 'package:go_router/go_router.dart';
import '../../app/app_shell.dart';
import '../../features/auth/bloc/auth_bloc.dart';
import '../../features/auth/forgot_password_page.dart';
import '../../features/auth/login_page.dart';
import '../../features/auth/register_page.dart';
import '../../features/analytics/analytics_page.dart';
import '../../features/billing/billing_page.dart';
import '../../features/customers/customers_page.dart';
import '../../features/notifications/notifications_page.dart';
import '../../features/settings/settings_page.dart';
import '../../features/stores/stores_page.dart';

GoRouter buildRouter(AuthBloc authBloc) {
  return GoRouter(
    initialLocation: '/',
    refreshListenable: _BlocRefresh(authBloc.stream),
    redirect: (context, state) {
      final status = authBloc.state.status;
      if (status == AuthStatus.unknown) return null; // splash handled by app
      final loggedIn = status == AuthStatus.authenticated;
      final authRoutes = {'/login', '/register', '/forgot-password'};
      final onAuth = authRoutes.contains(state.matchedLocation);
      if (!loggedIn && !onAuth) return '/login';
      if (loggedIn && onAuth) return '/';
      return null;
    },
    routes: [
      GoRoute(path: '/', builder: (_, _) => const AppShell()),
      GoRoute(path: '/login', builder: (_, _) => const LoginPage()),
      GoRoute(path: '/register', builder: (_, _) => const RegisterPage()),
      GoRoute(path: '/forgot-password', builder: (_, _) => const ForgotPasswordPage()),
      GoRoute(path: '/stores', builder: (_, _) => const StoresPage()),
      GoRoute(path: '/customers', builder: (_, _) => const CustomersPage()),
      GoRoute(path: '/analytics', builder: (_, _) => const AnalyticsPage()),
      GoRoute(path: '/billing', builder: (_, _) => const BillingPage()),
      GoRoute(path: '/settings', builder: (_, _) => const SettingsPage()),
      GoRoute(path: '/notifications', builder: (_, _) => const NotificationsPage()),
    ],
  );
}

/// Bridges a Bloc stream to a Listenable so GoRouter re-evaluates redirects.
class _BlocRefresh extends ChangeNotifier {
  _BlocRefresh(Stream<dynamic> stream) {
    notifyListeners();
    _sub = stream.asBroadcastStream().listen((_) => notifyListeners());
  }
  late final StreamSubscription<dynamic> _sub;
  @override
  void dispose() {
    _sub.cancel();
    super.dispose();
  }
}
