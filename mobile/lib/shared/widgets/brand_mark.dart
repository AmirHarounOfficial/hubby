import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';

/// The Hubby wordmark (deep-teal + brand green), drawn from the brand SVG.
/// [size] drives the height; width follows the artwork's aspect ratio.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 40});
  final double size;

  @override
  Widget build(BuildContext context) {
    return SvgPicture.asset(
      'assets/brand/logo.svg',
      height: size,
      fit: BoxFit.contain,
      semanticsLabel: 'Hubby',
    );
  }
}
