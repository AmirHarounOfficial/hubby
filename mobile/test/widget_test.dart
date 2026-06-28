import 'package:flutter_test/flutter_test.dart';
import 'package:hubby_global/core/platforms.dart';

void main() {
  test('platform metadata resolves known + unknown ids', () {
    expect(platformFor('shopify').name, 'Shopify');
    expect(platformFor('amazon').name, 'Amazon');
    expect(platformFor('does-not-exist').name, 'Other');
  });
}
