import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import '../../core/format.dart';
import '../../core/theme/app_palette.dart';

/// The official new Saudi Riyal symbol, drawn as a vector so it renders on every
/// platform regardless of font support. Tints to [color].
class RiyalGlyph extends StatelessWidget {
  const RiyalGlyph({super.key, required this.size, this.color});
  final double size;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ?? DefaultTextStyle.of(context).style.color ?? AppPalette.foreground;
    return SvgPicture.asset(
      'assets/saudi_riyal.svg',
      height: size,
      colorFilter: ColorFilter.mode(c, BlendMode.srcIn),
      // The glyph is slightly taller than wide; height drives the size.
    );
  }
}

/// Renders a currency amount with the official riyal glyph for SAR (or the ISO
/// code for other currencies). Drop-in replacement for `Text(formatMoney(x))`.
class MoneyText extends StatelessWidget {
  const MoneyText(
    this.amount, {
    super.key,
    this.code,
    this.style,
    this.compact = false,
    this.suffix,
  });

  final dynamic amount;
  final String? code;
  final TextStyle? style;
  final bool compact;

  /// Optional trailing text rendered in the same style (e.g. " / mo").
  final String? suffix;

  @override
  Widget build(BuildContext context) {
    final base = (style ?? DefaultTextStyle.of(context).style);
    final number = compact ? formatNumberCompact(amount) : formatNumber(amount);
    final tail = suffix ?? '';

    if (!isSar(code)) {
      return Text('${code!.toUpperCase()} $number$tail', style: base);
    }

    final fontSize = base.fontSize ?? 14;
    final color = base.color ?? AppPalette.foreground;
    return Text.rich(
      TextSpan(children: [
        WidgetSpan(
          alignment: PlaceholderAlignment.middle,
          child: Padding(
            padding: EdgeInsetsDirectional.only(end: fontSize * 0.16),
            child: RiyalGlyph(size: fontSize * 0.92, color: color),
          ),
        ),
        TextSpan(text: '$number$tail'),
      ]),
      style: base,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
    );
  }
}
