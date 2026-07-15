import 'package:flutter/material.dart';

/// Brand palette + design tokens for the Hubby app.
///
/// The Hubby identity pairs a deep-teal anchor with a vivid brand green, over a
/// clean neutral system (soft grays, elevation, spacing, radii) tuned for a
/// world-class, multinational commerce-SaaS feel.
class AppPalette {
  // ── Brand ──────────────────────────────────────────────────────────────
  static const primary = Color(0xFF0B5A5C); // deep teal — anchor / CTAs
  static const primaryHover = Color(0xFF083F41); // darker teal
  static const primarySoft = Color(0x140B5A5C); // 8% tint for fills/halos
  static const secondary = Color(0xFF4FD34A); // brand green — accents/highlights
  static const secondarySoft = Color(0x144FD34A);
  static const destructive = Color(0xFFF24B4B);
  static const destructiveSoft = Color(0x14F24B4B);
  static const warning = Color(0xFFF6A623);
  static const warningSoft = Color(0x14F6A623);
  static const success = Color(0xFF24C26A);
  static const info = Color(0xFF22D3EE); // AI cyan

  // ── Surfaces (light) ───────────────────────────────────────────────────
  static const background = Color(0xFFF8FAFB); // brand app background
  static const card = Color(0xFFFFFFFF);
  static const surfaceAlt = Color(0xFFEEF2F4); // brand soft gray — inset fields/chips
  static const foreground = Color(0xFF183238); // brand text primary
  static const foregroundSoft = Color(0xFF344A50);
  static const mutedForeground = Color(0xFF60727A); // brand text secondary
  static const border = Color(0xFFDCE5E8); // brand border
  static const borderStrong = Color(0xFFC7D5DA);
  static const accent = Color(0xFFEEF2F4);

  // ── Elevation ──────────────────────────────────────────────────────────
  /// Soft, layered card shadow — the signature of polished SaaS surfaces.
  static const List<BoxShadow> shadowCard = [
    BoxShadow(color: Color(0x0A1E293B), blurRadius: 2, offset: Offset(0, 1)),
    BoxShadow(color: Color(0x0F1E293B), blurRadius: 16, offset: Offset(0, 8), spreadRadius: -6),
  ];

  /// Stronger lift for sheets, popovers and floating bars.
  static const List<BoxShadow> shadowRaised = [
    BoxShadow(color: Color(0x141E293B), blurRadius: 28, offset: Offset(0, 14), spreadRadius: -8),
  ];

  /// Coloured glow used under primary CTAs / hero stats.
  static const List<BoxShadow> shadowPrimary = [
    BoxShadow(color: Color(0x330B5A5C), blurRadius: 20, offset: Offset(0, 10), spreadRadius: -6),
  ];

  // ── Spacing (4 / 8 rhythm) ─────────────────────────────────────────────
  static const double s4 = 4, s8 = 8, s12 = 12, s16 = 16, s20 = 20, s24 = 24, s32 = 32;

  // ── Radii ──────────────────────────────────────────────────────────────
  static const double rSm = 10, rMd = 14, rLg = 18, rXl = 24, rPill = 999;

  /// Brand colour for each connected platform (matches web platform metadata).
  static const Map<String, Color> platform = {
    'shopify': Color(0xFF22C55E),
    'salla': Color(0xFF00C7B1),
    'amazon': Color(0xFFF59E0B),
    'noon': Color(0xFFEAB308),
    'woocommerce': Color(0xFF9333EA),
    'zid': Color(0xFFF97316),
    'trendyol': Color(0xFFF27A1A),
  };
}
