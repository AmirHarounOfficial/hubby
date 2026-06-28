import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'theme/app_palette.dart';

/// How a merchant connects each platform, mirrored from the web app.
class PlatformMeta {
  const PlatformMeta({
    required this.id,
    required this.name,
    required this.icon,
    required this.color,
    required this.auth,
    required this.domainLabel,
    required this.domainHint,
    required this.tokenLabel,
    this.secretLabel,
    required this.help,
  });

  final String id;
  final String name;
  final IconData icon;
  final Color color;
  final String auth; // 'oauth' | 'manual'
  final String domainLabel;
  final String domainHint;
  final String tokenLabel;
  final String? secretLabel;
  final String help;
}

const List<PlatformMeta> kPlatforms = [
  PlatformMeta(
    id: 'shopify', name: 'Shopify', icon: LucideIcons.shoppingBag,
    color: Color(0xFF22C55E), auth: 'oauth',
    domainLabel: 'Store domain', domainHint: 'your-store.myshopify.com',
    tokenLabel: 'Admin API access token',
    help: 'Shopify admin → Settings → Apps → Develop apps → API credentials.',
  ),
  PlatformMeta(
    id: 'salla', name: 'Salla', icon: LucideIcons.globe,
    color: AppPalette.primary, auth: 'oauth',
    domainLabel: 'Store URL', domainHint: 'your-store.salla.sa',
    tokenLabel: 'Access token', help: 'Salla Partners portal → your app → Tokens.',
  ),
  PlatformMeta(
    id: 'amazon', name: 'Amazon', icon: LucideIcons.package,
    color: Color(0xFFF59E0B), auth: 'manual',
    domainLabel: 'Seller ID', domainHint: 'A1B2C3D4E5',
    tokenLabel: 'SP-API refresh token',
    help: 'Seller Central → Apps & Services → Develop apps.',
  ),
  PlatformMeta(
    id: 'noon', name: 'Noon', icon: LucideIcons.sun,
    color: Color(0xFFEAB308), auth: 'manual',
    domainLabel: 'Store URL', domainHint: 'your-store.noon.partners',
    tokenLabel: 'API token', help: 'noon Seller portal → Settings → API access.',
  ),
  PlatformMeta(
    id: 'woocommerce', name: 'WooCommerce', icon: LucideIcons.zap,
    color: Color(0xFF9333EA), auth: 'manual',
    domainLabel: 'Store URL', domainHint: 'https://yourstore.com',
    tokenLabel: 'Consumer key', secretLabel: 'Consumer secret',
    help: 'WooCommerce → Settings → Advanced → REST API → Add key.',
  ),
  PlatformMeta(
    id: 'zid', name: 'Zid', icon: LucideIcons.store,
    color: Color(0xFFF97316), auth: 'manual',
    domainLabel: 'Store URL', domainHint: 'your-store.zid.store',
    tokenLabel: 'Access token', help: 'Zid dashboard → Settings → API.',
  ),
];

final Map<String, PlatformMeta> _byId = {for (final p in kPlatforms) p.id: p};

PlatformMeta platformFor(String? id) =>
    _byId[id] ??
    const PlatformMeta(
      id: 'other', name: 'Other', icon: LucideIcons.store,
      color: AppPalette.mutedForeground, auth: 'manual',
      domainLabel: 'Store URL', domainHint: 'your-store.example.com',
      tokenLabel: 'Access token', help: 'Use your store API credentials.',
    );
