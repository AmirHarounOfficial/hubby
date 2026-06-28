import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../core/theme/app_palette.dart';
import '../features/stores/cubit/stores_cubit.dart';
import '../features/dashboard/dashboard_page.dart';
import '../features/orders/orders_page.dart';
import '../features/products/products_page.dart';
import '../features/inventory/inventory_page.dart';
import '../features/more/more_page.dart';
import '../l10n/strings.dart';

/// Bottom-nav shell. Five primary tabs; secondary sections live under "More".
class AppShell extends StatefulWidget {
  const AppShell({super.key});
  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _index = 0;

  @override
  void initState() {
    super.initState();
    final stores = context.read<StoresCubit>();
    stores.refresh();
    stores.loadOauthOptions();
  }

  @override
  Widget build(BuildContext context) {
    final pages = const [
      DashboardPage(),
      OrdersPage(),
      ProductsPage(),
      InventoryPage(),
      MorePage(),
    ];
    return Scaffold(
      body: SafeArea(child: IndexedStack(index: _index, children: pages)),
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(
          color: AppPalette.card,
          border: Border(top: BorderSide(color: AppPalette.border)),
          boxShadow: [BoxShadow(color: Color(0x0A1E293B), blurRadius: 16, offset: Offset(0, -6))],
        ),
        child: NavigationBar(
          selectedIndex: _index,
          onDestinationSelected: (i) => setState(() => _index = i),
          destinations: [
            _dest(LucideIcons.layoutDashboard, context.t('nav.dashboard')),
            _dest(LucideIcons.shoppingCart, context.t('nav.orders')),
            _dest(LucideIcons.package, context.t('nav.products')),
            _dest(LucideIcons.boxes, context.t('nav.inventory')),
            _dest(LucideIcons.menu, context.t('nav.more')),
          ],
        ),
      ),
    );
  }

  NavigationDestination _dest(IconData icon, String label) =>
      NavigationDestination(icon: Icon(icon, size: 22), label: label);
}
