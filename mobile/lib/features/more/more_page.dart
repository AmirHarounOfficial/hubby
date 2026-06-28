import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/locale/locale_cubit.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../auth/bloc/auth_bloc.dart';
import '../analytics/analytics_page.dart';
import '../billing/billing_page.dart';
import '../categories/categories_page.dart';
import '../customers/customers_page.dart';
import '../notifications/notifications_page.dart';
import '../settings/settings_page.dart';
import '../stores/stores_page.dart';

class MorePage extends StatelessWidget {
  const MorePage({super.key});

  @override
  Widget build(BuildContext context) {
    final items = [
      (LucideIcons.store, context.t('nav.stores'), const StoresPage()),
      (LucideIcons.folderTree, context.t('nav.categories'), const CategoriesPage()),
      (LucideIcons.users, context.t('nav.customers'), const CustomersPage()),
      (LucideIcons.barChart3, context.t('nav.analytics'), const AnalyticsPage()),
      (LucideIcons.creditCard, context.t('nav.billing'), const BillingPage()),
      (LucideIcons.bell, context.t('nav.notifications'), const NotificationsPage()),
      (LucideIcons.settings, context.t('nav.settings'), const SettingsPage()),
    ];
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        BlocBuilder<AuthBloc, AuthState>(
          builder: (context, s) => AppCard(
            child: Row(children: [
              CircleAvatar(
                backgroundColor: AppPalette.primary.withValues(alpha: 0.12),
                child: Text(s.userName.isNotEmpty ? s.userName[0].toUpperCase() : '?',
                    style: const TextStyle(color: AppPalette.primary, fontWeight: FontWeight.bold)),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(s.userName, style: const TextStyle(fontWeight: FontWeight.bold)),
                    Text(s.userEmail, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
                  ],
                ),
              ),
            ]),
          ),
        ),
        const SizedBox(height: 16),
        AppCard(
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              for (final it in items)
                ListTile(
                  leading: Icon(it.$1, size: 20, color: AppPalette.foreground),
                  title: Text(it.$2),
                  trailing: const Icon(LucideIcons.chevronRight, size: 18, color: AppPalette.mutedForeground),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => it.$3)),
                ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        AppCard(
          padding: EdgeInsets.zero,
          child: Column(children: [
            ListTile(
              leading: const Icon(LucideIcons.languages, size: 20),
              title: Text(context.t('settings.language')),
              trailing: Text(
                  Localizations.localeOf(context).languageCode == 'ar' ? 'العربية' : 'English',
                  style: const TextStyle(color: AppPalette.mutedForeground)),
              onTap: () => context.read<LocaleCubit>().toggle(),
            ),
            ListTile(
              leading: const Icon(LucideIcons.logOut, size: 20, color: AppPalette.destructive),
              title: Text(context.t('common.logout'), style: const TextStyle(color: AppPalette.destructive)),
              onTap: () => context.read<AuthBloc>().add(const AuthLoggedOut()),
            ),
          ]),
        ),
      ],
    );
  }
}
