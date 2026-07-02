import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import '../../core/platforms.dart';

/// Renders a platform's real brand logo (assets/platforms/{id}.svg) at [size]
/// height, keeping the artwork's own aspect ratio. Unknown platforms fall back
/// to the Lucide icon. Width is capped so wide wordmarks can't blow out a row.
class PlatformLogo extends StatelessWidget {
  const PlatformLogo({super.key, required this.platformId, this.size = 22, this.fallbackColor});

  final String? platformId;
  final double size;

  /// Only used for the icon fallback (real logos keep their own colours).
  final Color? fallbackColor;

  @override
  Widget build(BuildContext context) {
    final meta = platformFor(platformId);
    if (kPlatformLogoIds.contains(meta.id)) {
      return ConstrainedBox(
        constraints: BoxConstraints(maxHeight: size, maxWidth: size * 3),
        child: SvgPicture.asset(
          'assets/platforms/${meta.id}.svg',
          height: size,
          fit: BoxFit.contain,
          semanticsLabel: '${meta.name} logo',
        ),
      );
    }
    return Icon(meta.icon, size: size, color: fallbackColor ?? meta.color);
  }
}
