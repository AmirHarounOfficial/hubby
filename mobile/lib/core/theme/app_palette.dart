import 'package:flutter/material.dart';

/// Brand palette + design tokens for the HubbyGlobal app.
///
/// The brand hue (indigo + emerald) matches the "Micro SaaS" reference palette;
/// the surrounding system (slate neutrals, elevation, spacing, radii) is tuned
/// for a world-class, multinational commerce-SaaS feel.
class AppPalette {
  // ── Brand ──────────────────────────────────────────────────────────────
  static const primary = Color(0xFF4F46E5); // indigo-600
  static const primaryHover = Color(0xFF4338CA); // indigo-700
  static const primarySoft = Color(0x144F46E5); // 8% tint for fills/halos
  static const secondary = Color(0xFF10B981); // emerald-500
  static const secondarySoft = Color(0x1410B981);
  static const destructive = Color(0xFFEF4444);
  static const destructiveSoft = Color(0x14EF4444);
  static const warning = Color(0xFFF59E0B);
  static const warningSoft = Color(0x14F59E0B);
  static const success = Color(0xFF10B981);
  static const info = Color(0xFF3B82F6);

  // ── Surfaces (light) ───────────────────────────────────────────────────
  static const background = Color(0xFFF8FAFC); // slate-50 — airy canvas
  static const card = Color(0xFFFFFFFF);
  static const surfaceAlt = Color(0xFFF1F5F9); // slate-100 — inset fields/chips
  static const foreground = Color(0xFF0F172A); // slate-900
  static const foregroundSoft = Color(0xFF334155); // slate-700
  static const mutedForeground = Color(0xFF64748B); // slate-500
  static const border = Color(0xFFE9EEF5); // hairline
  static const borderStrong = Color(0xFFD8E0EA);
  static const accent = Color(0xFFF1F5F9);

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
    BoxShadow(color: Color(0x334F46E5), blurRadius: 20, offset: Offset(0, 10), spreadRadius: -6),
  ];

  // ── Spacing (4 / 8 rhythm) ─────────────────────────────────────────────
  static const double s4 = 4, s8 = 8, s12 = 12, s16 = 16, s20 = 20, s24 = 24, s32 = 32;

  // ── Radii ──────────────────────────────────────────────────────────────
  static const double rSm = 10, rMd = 14, rLg = 18, rXl = 24, rPill = 999;

  /// Brand colour for each connected platform (matches web platform metadata).
  static const Map<String, Color> platform = {
    'shopify': Color(0xFF22C55E),
    'salla': primary,
    'amazon': Color(0xFFF59E0B),
    'noon': Color(0xFFEAB308),
    'woocommerce': Color(0xFF9333EA),
    'zid': Color(0xFFF97316),
  };
}
