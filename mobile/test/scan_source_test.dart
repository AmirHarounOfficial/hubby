import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hubby_global/features/warehouse/scanner/scan_source.dart';

/// The double-read guard (spec 08 §4.1 rule P6).
///
/// A camera reads the same label many times a second, so without this guard a single physical item
/// would be picked five times. The guard is the reason a camera scan is usable at all.
void main() {
  Future<ScanSourceState> pump(
    WidgetTester tester,
    List<(String, ScanInput)> log, {
    Duration window = const Duration(milliseconds: 1200),
  }) async {
    final key = GlobalKey<ScanSourceState>();
    await tester.pumpWidget(
      MaterialApp(
        home: ScanSource(
          key: key,
          duplicateWindow: window,
          onScan: (barcode, input) => log.add((barcode, input)),
          child: const SizedBox(),
        ),
      ),
    );
    return key.currentState!;
  }

  testWidgets('the same barcode inside the window is dropped', (tester) async {
    final log = <(String, ScanInput)>[];
    final state = await pump(tester, log);

    state.handle('4006381333931', ScanInput.camera);
    state.handle('4006381333931', ScanInput.camera); // camera double-read
    state.handle('4006381333931', ScanInput.camera);

    expect(log, hasLength(1), reason: 'a camera double-read must not count three times');
  });

  testWidgets('a different barcode is never suppressed', (tester) async {
    final log = <(String, ScanInput)>[];
    final state = await pump(tester, log);

    state.handle('AAA', ScanInput.camera);
    state.handle('BBB', ScanInput.camera);

    expect(log.map((e) => e.$1), ['AAA', 'BBB']);
  });

  testWidgets('the same barcode is accepted again once the window passes', (tester) async {
    final log = <(String, ScanInput)>[];
    final state = await pump(tester, log, window: const Duration(milliseconds: 10));

    // The guard compares wall-clock DateTime.now(), which tester.pump() does not advance — so this
    // needs a genuine async gap rather than fake-async time travel.
    state.handle('AAA', ScanInput.camera);
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 30)));
    state.handle('AAA', ScanInput.camera);

    // Picking two identical units genuinely means scanning the same label twice.
    expect(log, hasLength(2));
  });

  testWidgets('resetting the guard allows an immediate rescan', (tester) async {
    final log = <(String, ScanInput)>[];
    final state = await pump(tester, log);

    state.handle('AAA', ScanInput.camera);
    state.resetDuplicateGuard();
    state.handle('AAA', ScanInput.camera);

    expect(log, hasLength(2));
  });

  testWidgets('blank scans and disabled sources are ignored', (tester) async {
    final log = <(String, ScanInput)>[];
    final state = await pump(tester, log);

    state.handle('   ', ScanInput.camera);
    state.handle('', ScanInput.manual);

    expect(log, isEmpty);
  });

  testWidgets('the input device is passed through to the workflow', (tester) async {
    final log = <(String, ScanInput)>[];
    final state = await pump(tester, log);

    state.handle('AAA', ScanInput.hid);
    state.handle('BBB', ScanInput.manual);

    expect(log.map((e) => e.$2), [ScanInput.hid, ScanInput.manual]);
  });
}
