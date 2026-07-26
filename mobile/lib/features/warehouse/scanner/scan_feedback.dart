import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Scan feedback (spec 08 §4.1 "mispick prevention", layer 3).
///
/// The rule: **failure must be impossible to miss with the phone in a pocket.** Success is
/// deliberately quiet — a soft selection click — because it happens hundreds of times an hour and
/// heavy feedback becomes noise the operator tunes out. Failure is heavy haptic plus a full-screen
/// red overlay that must be dismissed by tapping, so a mis-scan cannot be walked past.
class ScanFeedback {
  /// Quiet tick. Used for every accepted scan.
  static void success() {
    HapticFeedback.selectionClick();
  }

  /// A scan that resolved but changed nothing (a duplicate inside the double-read window).
  /// Deliberately silent: a camera double-read is the number-one false positive, and buzzing for
  /// it teaches operators to ignore the haptics that matter.
  static void ignored() {}

  static void failure() {
    HapticFeedback.heavyImpact();
    SystemSound.play(SystemSoundType.alert);
  }

  /// Blocking red overlay for a rejected scan. Returns once the operator dismisses it.
  static Future<void> showFailure(
    BuildContext context, {
    required String title,
    String? detail,
  }) async {
    failure();
    if (!context.mounted) return;

    await showDialog<void>(
      context: context,
      barrierDismissible: true,
      barrierColor: Colors.red.shade900.withValues(alpha: 0.92),
      builder: (ctx) => GestureDetector(
        onTap: () => Navigator.of(ctx).pop(),
        behavior: HitTestBehavior.opaque,
        child: Material(
          color: Colors.transparent,
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(Icons.error_outline, color: Colors.white, size: 96),
                  const SizedBox(height: 24),
                  Text(
                    title,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 28,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  if (detail != null) ...[
                    const SizedBox(height: 12),
                    Text(
                      detail,
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Colors.white70, fontSize: 16),
                    ),
                  ],
                  const SizedBox(height: 32),
                  const Text(
                    'Tap to dismiss',
                    style: TextStyle(color: Colors.white54, fontSize: 14),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
