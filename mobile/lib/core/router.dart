import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:hubby_global/features/auth/login_screen.dart';
import 'package:hubby_global/features/auth/register_screen.dart';
import 'package:hubby_global/features/auth/verify_screen.dart';
import 'package:hubby_global/features/dashboard/dashboard_screen.dart';
import 'package:hubby_global/features/orders/orders_screen.dart';
import 'package:hubby_global/features/orders/order_detail_screen.dart';
import 'package:hubby_global/features/products/products_screen.dart';
import 'package:hubby_global/features/inventory/inventory_screen.dart';
import 'package:hubby_global/features/stores/stores_screen.dart';
import 'package:hubby_global/features/billing/billing_screen.dart';

class AppRouter {
  static final router = GoRouter(
    initialLocation: '/login',
    routes: [
      GoRoute(
        path: '/login',
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: '/register',
        builder: (context, state) => const RegisterScreen(),
      ),
      GoRoute(
        path: '/verify',
        builder: (context, state) => const VerifyScreen(),
      ),
      GoRoute(
        path: '/',
        redirect: (context, state) => '/dashboard',
      ),
      GoRoute(
        path: '/dashboard',
        builder: (context, state) => const DashboardScreen(),
        routes: [
          GoRoute(
            path: 'orders',
            builder: (context, state) => const OrdersScreen(),
            routes: [
              GoRoute(
                path: ':id',
                builder: (context, state) => OrderDetailScreen(
                  orderId: state.pathParameters['id'] ?? '',
                ),
              ),
            ],
          ),
          GoRoute(
            path: 'products',
            builder: (context, state) => const ProductsScreen(),
          ),
          GoRoute(
            path: 'inventory',
            builder: (context, state) => const InventoryScreen(),
          ),
          GoRoute(
            path: 'stores',
            builder: (context, state) => const StoresScreen(),
          ),
          GoRoute(
            path: 'billing',
            builder: (context, state) => const BillingScreen(),
          ),
        ],
      ),
    ],
  );
}
