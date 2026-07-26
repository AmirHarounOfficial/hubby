import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import 'scan_feedback.dart';

/// How a barcode reached us. Sent to the server so a warehouse can tell camera scanning from a
/// Bluetooth gun when diagnosing a problem site.
enum ScanInput { camera, hid, manual }

/// A scanning surface that feeds one callback regardless of input device (spec 08 §6.8).
///
/// Camera and HID both call `onScan`, which is why adding Bluetooth-gun support needed **zero**
/// workflow changes — the pages never learn which device produced the barcode.
///
/// Also owns the double-read guard: a camera happily reads the same label 30 times a second, and a
/// duplicate inside [duplicateWindow] is dropped silently (no haptic — buzzing on the commonest
/// false positive teaches operators to ignore the feedback that matters).
class ScanSource extends StatefulWidget {
  const ScanSource({
    super.key,
    required this.onScan,
    required this.child,
    this.enabled = true,
    this.duplicateWindow = const Duration(milliseconds: 1200),
  });

  final void Function(String barcode, ScanInput input) onScan;
  final Widget child;
  final bool enabled;
  final Duration duplicateWindow;

  @override
  State<ScanSource> createState() => ScanSourceState();
}

class ScanSourceState extends State<ScanSource> {
  final FocusNode _focus = FocusNode();
  final StringBuffer _hidBuffer = StringBuffer();
  DateTime? _hidFirstKeyAt;
  Timer? _hidIdleTimer;

  String? _lastBarcode;
  DateTime? _lastScanAt;

  /// True once a HID gun has been detected, so the UI can pause the camera and save battery.
  bool hidDetected = false;

  @override
  void initState() {
    super.initState();
    HardwareKeyboard.instance.addHandler(_onKey);
    _focus.requestFocus();
  }

  @override
  void dispose() {
    HardwareKeyboard.instance.removeHandler(_onKey);
    _hidIdleTimer?.cancel();
    _focus.dispose();
    super.dispose();
  }

  /// Feed a barcode from any source through the duplicate guard.
  void handle(String raw, ScanInput input) {
    final barcode = raw.trim();
    if (barcode.isEmpty || !widget.enabled) return;

    final now = DateTime.now();
    final isRepeat = _lastBarcode == barcode &&
        _lastScanAt != null &&
        now.difference(_lastScanAt!) < widget.duplicateWindow;

    if (isRepeat) {
      ScanFeedback.ignored();
      return;
    }

    _lastBarcode = barcode;
    _lastScanAt = now;
    widget.onScan(barcode, input);
  }

  /// Let a page clear the guard — e.g. when a quantity of 3 genuinely needs three scans of the
  /// same label in a row.
  void resetDuplicateGuard() {
    _lastBarcode = null;
    _lastScanAt = null;
  }

  // --- HID (Bluetooth gun behaves as a keyboard) -----------------------------------------------

  /// A HID scanner types the barcode far faster than a human and terminates with Enter/Tab.
  /// Anything slower is treated as human typing and left alone.
  bool _onKey(KeyEvent event) {
    if (event is! KeyDownEvent || !widget.enabled) return false;

    final now = DateTime.now();
    final key = event.logicalKey;

    if (key == LogicalKeyboardKey.enter || key == LogicalKeyboardKey.tab) {
      return _flushHid(now);
    }

    final char = event.character;
    if (char == null || char.isEmpty || char.codeUnitAt(0) < 0x20) return false;

    _hidFirstKeyAt ??= now;
    _hidBuffer.write(char);

    // Fallback flush for guns that send no terminator.
    _hidIdleTimer?.cancel();
    _hidIdleTimer = Timer(const Duration(milliseconds: 120), () => _flushHid(DateTime.now()));

    return false;
  }

  bool _flushHid(DateTime now) {
    _hidIdleTimer?.cancel();
    final text = _hidBuffer.toString();
    final first = _hidFirstKeyAt;
    _hidBuffer.clear();
    _hidFirstKeyAt = null;

    if (text.length < 4 || first == null) return false;

    // Mean inter-key interval: a gun is ~10ms/char, a human an order of magnitude slower.
    final elapsed = now.difference(first).inMilliseconds;
    final meanInterval = elapsed / text.length;
    if (meanInterval > 30 || elapsed > 500) return false;

    if (!hidDetected && mounted) {
      setState(() => hidDetected = true);
    }
    handle(text, ScanInput.hid);
    return true;
  }

  @override
  Widget build(BuildContext context) {
    // A plain Focus node rather than a hidden TextField: it keeps key events flowing to the
    // HardwareKeyboard handler without adding a zero-size text input, which perturbs layout (and
    // in a widget test blows the stack). The soft keyboard never appears either, which is the
    // behaviour we actually wanted from the hidden-field trick.
    return Focus(
      focusNode: _focus,
      autofocus: true,
      child: widget.child,
    );
  }
}

/// The camera viewfinder. Kept small so a page can put it above a working list rather than
/// full-screen — an operator needs to see what they are picking, not just the camera.
class CameraScanner extends StatefulWidget {
  const CameraScanner({super.key, required this.onDetect, this.active = true, this.height = 220});

  final void Function(String barcode) onDetect;
  final bool active;
  final double height;

  @override
  State<CameraScanner> createState() => _CameraScannerState();
}

class _CameraScannerState extends State<CameraScanner> {
  late final MobileScannerController _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.normal,
    formats: const [
      BarcodeFormat.ean13,
      BarcodeFormat.ean8,
      BarcodeFormat.upcA,
      BarcodeFormat.upcE,
      BarcodeFormat.code128,
      BarcodeFormat.code39,
      BarcodeFormat.itf14,
      BarcodeFormat.qrCode,
      BarcodeFormat.dataMatrix,
    ],
  );

  @override
  void didUpdateWidget(covariant CameraScanner oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.active != oldWidget.active) {
      widget.active ? _controller.start() : _controller.stop();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.active) {
      return SizedBox(
        height: widget.height,
        child: const Center(child: Text('Camera paused — scanner connected')),
      );
    }

    return SizedBox(
      height: widget.height,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: Stack(
          fit: StackFit.expand,
          children: [
            MobileScanner(
              controller: _controller,
              onDetect: (capture) {
                for (final barcode in capture.barcodes) {
                  final value = barcode.rawValue;
                  if (value != null && value.isNotEmpty) {
                    widget.onDetect(value);
                    break;
                  }
                }
              },
            ),
            IgnorePointer(
              child: Center(
                child: Container(
                  width: 240,
                  height: 96,
                  decoration: BoxDecoration(
                    border: Border.all(color: Colors.white70, width: 2),
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
              ),
            ),
            Positioned(
              right: 8,
              top: 8,
              child: IconButton(
                icon: const Icon(Icons.flash_on, color: Colors.white),
                onPressed: () => _controller.toggleTorch(),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
