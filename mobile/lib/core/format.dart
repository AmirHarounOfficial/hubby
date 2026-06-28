import 'package:intl/intl.dart';

/// Saudi Riyal currency symbol — the same token for both English and Arabic.
///
/// The official *new* riyal sign is Unicode U+20C0 (adopted 2025). It is so new
/// that the app's bundled fonts (Inter/Cairo) don't include the glyph yet, so it
/// renders as a blank box. Until the official "Saudi Riyal" font is bundled
/// (see `fonts/README.md`), we show the ISO code "SAR", which renders everywhere.
///
/// To switch to the official glyph: bundle the font, then set `kSar = kSarGlyph`.
const String kSarGlyph = '\u{20C0}'; // official new sign — requires the SAR font
const String kSar = 'SAR'; // safe interim that renders on every device today

final NumberFormat _plain = NumberFormat('#,##0.00');
final NumberFormat _compact = NumberFormat.compact();

/// Number only (no symbol) — used by the [MoneyText] widget which draws the
/// official riyal glyph as a vector beside it.
String formatNumber(dynamic amount) => _plain.format(double.tryParse('${amount ?? 0}') ?? 0);
String formatNumberCompact(dynamic amount) => _compact.format(double.tryParse('${amount ?? 0}') ?? 0);

/// True when an amount should use the riyal glyph (SAR / null), vs a foreign ISO code.
bool isSar(String? code) => code == null || code.isEmpty || code.toUpperCase() == 'SAR';

/// `SAR 1,234.00` — same symbol regardless of locale. The optional [code] is
/// kept for API records that carry a non-SAR currency; non-SAR codes fall back
/// to their ISO code so we never mislabel a foreign amount as riyals.
String formatMoney(dynamic amount, [String? code]) {
  final value = double.tryParse('${amount ?? 0}') ?? 0;
  final symbol = (code == null || code.isEmpty || code.toUpperCase() == 'SAR') ? kSar : code.toUpperCase();
  return '$symbol ${_plain.format(value)}';
}

/// Compact form for KPIs (e.g. `SAR 12.3K`).
String formatMoneyCompact(dynamic amount, [String? code]) {
  final value = double.tryParse('${amount ?? 0}') ?? 0;
  final symbol = (code == null || code.isEmpty || code.toUpperCase() == 'SAR') ? kSar : code.toUpperCase();
  return '$symbol ${_compact.format(value)}';
}

double asNum(dynamic v) => double.tryParse('${v ?? 0}') ?? 0;
