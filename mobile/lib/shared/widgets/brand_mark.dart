import 'package:flutter/material.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/theme/app_palette.dart';

class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 40});
  final double size;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          height: size, width: size,
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [AppPalette.primary, AppPalette.secondary],
              begin: Alignment.topLeft, end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(size * 0.3),
          ),
          child: Icon(LucideIcons.zap, color: Colors.white, size: size * 0.5),
        ),
        const SizedBox(width: 10),
        const Text('Hubby',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, letterSpacing: -0.4)),
      ],
    );
  }
}
