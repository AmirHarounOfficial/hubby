import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hubby_global/core/platforms.dart';
import 'package:hubby_global/shared/widgets/platform_logo.dart';

void main() {
  testWidgets('PlatformLogo renders every platform logo without throwing', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              for (final p in kPlatforms) PlatformLogo(platformId: p.id, size: 24),
              const PlatformLogo(platformId: 'unknown_platform', size: 24), // icon fallback path
            ],
          ),
        ),
      ),
    );

    // Let flutter_svg parse/decode the bundled assets.
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pump(const Duration(milliseconds: 300));

    expect(tester.takeException(), isNull);
    // One PlatformLogo per platform + the fallback.
    expect(find.byType(PlatformLogo), findsNWidgets(kPlatforms.length + 1));
  });
}
