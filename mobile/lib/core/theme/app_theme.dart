import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'app_palette.dart';

/// World-class light theme for HubbyGlobal.
///
/// Plus Jakarta Sans for headings (distinctive, premium), Inter for body
/// (Swiss-clean for dense data), Cairo for Arabic. Soft layered elevation,
/// tabular figures for numerics, and an 18px radius language throughout.
class AppTheme {
  static ThemeData light(String localeCode) {
    final base = ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      scaffoldBackgroundColor: AppPalette.background,
      splashFactory: InkSparkle.splashFactory,
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppPalette.primary,
        primary: AppPalette.primary,
        secondary: AppPalette.secondary,
        error: AppPalette.destructive,
        surface: AppPalette.card,
        brightness: Brightness.light,
      ),
    );

    final textTheme = fontFor(localeCode, base.textTheme).apply(
      bodyColor: AppPalette.foreground,
      displayColor: AppPalette.foreground,
    );

    return base.copyWith(
      textTheme: textTheme,
      dividerColor: AppPalette.border,
      dividerTheme: const DividerThemeData(color: AppPalette.border, thickness: 1, space: 1),
      cardTheme: CardThemeData(
        color: AppPalette.card,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppPalette.rLg),
          side: const BorderSide(color: AppPalette.border),
        ),
        margin: EdgeInsets.zero,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: AppPalette.background,
        foregroundColor: AppPalette.foreground,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: _heading(localeCode, 19, FontWeight.w700).copyWith(color: AppPalette.foreground),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppPalette.card,
        hintStyle: const TextStyle(color: AppPalette.mutedForeground),
        labelStyle: const TextStyle(color: AppPalette.mutedForeground),
        floatingLabelStyle: const TextStyle(color: AppPalette.primary, fontWeight: FontWeight.w600),
        helperStyle: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
        prefixIconColor: AppPalette.mutedForeground,
        border: _inputBorder(AppPalette.borderStrong),
        enabledBorder: _inputBorder(AppPalette.borderStrong),
        focusedBorder: _inputBorder(AppPalette.primary, width: 1.6),
        errorBorder: _inputBorder(AppPalette.destructive),
        focusedErrorBorder: _inputBorder(AppPalette.destructive, width: 1.6),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppPalette.primary,
          foregroundColor: Colors.white,
          // Readable disabled state — grey on grey, never white-on-light.
          disabledBackgroundColor: AppPalette.surfaceAlt,
          disabledForegroundColor: AppPalette.mutedForeground,
          elevation: 0,
          padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 15),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppPalette.rMd)),
          textStyle: _heading(localeCode, 15, FontWeight.w600),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppPalette.foregroundSoft,
          backgroundColor: AppPalette.card,
          side: const BorderSide(color: AppPalette.borderStrong),
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppPalette.rMd)),
          textStyle: _heading(localeCode, 14, FontWeight.w600),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: AppPalette.primary,
          textStyle: _heading(localeCode, 14, FontWeight.w600),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        height: 66,
        backgroundColor: AppPalette.card,
        elevation: 0,
        surfaceTintColor: Colors.transparent,
        indicatorColor: AppPalette.primarySoft,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(
              size: 22,
              color: states.contains(WidgetState.selected) ? AppPalette.primary : AppPalette.mutedForeground,
            )),
        labelTextStyle: WidgetStateProperty.resolveWith((states) => _heading(
              localeCode,
              11.5,
              states.contains(WidgetState.selected) ? FontWeight.w600 : FontWeight.w500,
            ).copyWith(color: states.contains(WidgetState.selected) ? AppPalette.primary : AppPalette.mutedForeground)),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: AppPalette.card,
        selectedColor: AppPalette.primary,
        side: const BorderSide(color: AppPalette.borderStrong),
        labelStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: AppPalette.foreground),
        secondaryLabelStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.white),
        iconTheme: const IconThemeData(color: AppPalette.foregroundSoft, size: 16),
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppPalette.rPill)),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith(
            (s) => s.contains(WidgetState.selected) ? AppPalette.primary : Colors.white),
        trackColor: WidgetStateProperty.resolveWith(
            (s) => s.contains(WidgetState.selected) ? AppPalette.primarySoft : AppPalette.surfaceAlt),
        trackOutlineColor: WidgetStateProperty.all(AppPalette.borderStrong),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: AppPalette.card,
        elevation: 6,
        contentTextStyle: const TextStyle(color: AppPalette.foreground),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(AppPalette.rMd),
          side: const BorderSide(color: AppPalette.border),
        ),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: AppPalette.card,
        surfaceTintColor: Colors.transparent,
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: AppPalette.card,
        surfaceTintColor: Colors.transparent,
        elevation: 12,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppPalette.rXl)),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppPalette.primary,
        linearTrackColor: AppPalette.surfaceAlt,
      ),
    );
  }

  static OutlineInputBorder _inputBorder(Color color, {double width = 1.2}) => OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppPalette.rMd),
        borderSide: BorderSide(color: color, width: width),
      );

  static TextStyle _heading(String localeCode, double size, FontWeight weight) {
    final fn = localeCode == 'ar' ? GoogleFonts.cairo : GoogleFonts.inter;
    return fn(fontSize: size, fontWeight: weight, letterSpacing: -0.2);
  }

  /// Inter for Latin (loads reliably + excellent for dense data), Cairo for
  /// Arabic. Premium hierarchy comes from weight + tight tracking, not a second
  /// runtime-fetched family.
  static TextTheme fontFor(String localeCode, TextTheme base) {
    if (localeCode == 'ar') return GoogleFonts.cairoTextTheme(base);
    final t = GoogleFonts.interTextTheme(base);
    TextStyle? d(TextStyle? s) => s?.copyWith(letterSpacing: -0.4, fontWeight: FontWeight.w700);
    return t.copyWith(
      displayLarge: d(t.displayLarge),
      displayMedium: d(t.displayMedium),
      displaySmall: d(t.displaySmall),
      headlineLarge: d(t.headlineLarge),
      headlineMedium: d(t.headlineMedium),
      headlineSmall: d(t.headlineSmall),
      titleLarge: t.titleLarge?.copyWith(fontWeight: FontWeight.w700, letterSpacing: -0.3),
    );
  }
}

/// Typographic helpers — tabular figures keep KPI/price columns from shifting.
class AppText {
  static TextStyle number({double size = 22, FontWeight weight = FontWeight.w700, Color? color}) =>
      GoogleFonts.inter(
        fontSize: size,
        fontWeight: weight,
        color: color,
        letterSpacing: -0.5,
        fontFeatures: const [FontFeature.tabularFigures()],
      );
}
