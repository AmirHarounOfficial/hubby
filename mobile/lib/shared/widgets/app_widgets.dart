import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/theme/app_palette.dart';
import 'pressable.dart';

/// Rounded surface with soft, layered elevation — the signature SaaS card.
/// Pass [onTap]/[onLongPress] to make it a press-scale tappable surface.
class AppCard extends StatelessWidget {
  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.elevated = true,
    this.onTap,
    this.onLongPress,
  });
  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;

  /// When false, renders a flat hairline-bordered surface (for nested cards).
  final bool elevated;

  @override
  Widget build(BuildContext context) {
    final surface = Container(
      width: double.infinity,
      padding: padding,
      decoration: BoxDecoration(
        color: AppPalette.card,
        borderRadius: BorderRadius.circular(AppPalette.rLg),
        border: Border.all(color: AppPalette.border),
        boxShadow: elevated ? AppPalette.shadowCard : null,
      ),
      child: child,
    );
    if (onTap == null && onLongPress == null) return surface;
    return Pressable(onTap: onTap, onLongPress: onLongPress, child: surface);
  }
}

/// Animated shimmer placeholder shown while screens load (beats a blocking
/// spinner for perceived performance). Falls back to static blocks when
/// reduced-motion is on.
class LoadingView extends StatelessWidget {
  const LoadingView({super.key});
  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: const [
        Skeleton(height: 86),
        SizedBox(height: 12),
        Skeleton(height: 150),
        SizedBox(height: 12),
        Skeleton(height: 64),
        SizedBox(height: 12),
        Skeleton(height: 64),
        SizedBox(height: 12),
        Skeleton(height: 64),
      ],
    );
  }
}

/// A single shimmering placeholder block.
class Skeleton extends StatefulWidget {
  const Skeleton({super.key, this.height = 64, this.width = double.infinity, this.radius = AppPalette.rLg});
  final double height;
  final double width;
  final double radius;

  @override
  State<Skeleton> createState() => _SkeletonState();
}

class _SkeletonState extends State<Skeleton> with SingleTickerProviderStateMixin {
  late final AnimationController _c =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reduceMotion = MediaQuery.maybeOf(context)?.disableAnimations ?? false;
    final base = Container(
      height: widget.height,
      width: widget.width,
      decoration: BoxDecoration(
        color: AppPalette.surfaceAlt,
        borderRadius: BorderRadius.circular(widget.radius),
        border: Border.all(color: AppPalette.border),
      ),
    );
    if (reduceMotion) return base;
    // Gentle opacity pulse — robust and respects reduced-motion.
    return FadeTransition(
      opacity: Tween<double>(begin: 0.45, end: 1.0)
          .animate(CurvedAnimation(parent: _c, curve: Curves.easeInOut)),
      child: base,
    );
  }
}

class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.message, this.onRetry});
  final String message;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(LucideIcons.alertCircle, color: AppPalette.destructive, size: 32),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center,
                style: const TextStyle(color: AppPalette.mutedForeground)),
            if (onRetry != null) ...[
              const SizedBox(height: 16),
              OutlinedButton(onPressed: onRetry, child: const Text('Retry')),
            ],
          ],
        ),
      ),
    );
  }
}

/// Empty-state nudging the user to connect a store (mirrors web ConnectPrompt).
class ConnectPrompt extends StatelessWidget {
  const ConnectPrompt({super.key, required this.description});
  final String description;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      padding: const EdgeInsets.all(28),
      child: Column(
        children: [
          Container(
            height: 60, width: 60,
            decoration: BoxDecoration(
              color: AppPalette.primary.withValues(alpha: 0.1),
              borderRadius: BorderRadius.circular(18),
            ),
            child: const Icon(LucideIcons.plug, color: AppPalette.primary, size: 28),
          ),
          const SizedBox(height: 16),
          const Text('No platforms connected yet',
              style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
          const SizedBox(height: 6),
          Text(description, textAlign: TextAlign.center,
              style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 13)),
          const SizedBox(height: 18),
          FilledButton.icon(
            onPressed: () => context.push('/stores'),
            icon: const Icon(LucideIcons.store, size: 16),
            label: const Text('Connect a store'),
          ),
        ],
      ),
    );
  }
}

/// Lightweight toast (mirrors the web toast system) via a SnackBar.
enum ToastKind { success, error, info }

void showToast(BuildContext context, String message, [ToastKind kind = ToastKind.info]) {
  final color = switch (kind) {
    ToastKind.success => AppPalette.secondary,
    ToastKind.error => AppPalette.destructive,
    ToastKind.info => AppPalette.primary,
  };
  final icon = switch (kind) {
    ToastKind.success => LucideIcons.checkCircle2,
    ToastKind.error => LucideIcons.alertCircle,
    ToastKind.info => LucideIcons.info,
  };
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      behavior: SnackBarBehavior.floating,
      backgroundColor: AppPalette.card,
      content: Row(children: [
        Icon(icon, color: color, size: 18),
        const SizedBox(width: 10),
        Expanded(child: Text(message, style: const TextStyle(color: AppPalette.foreground))),
      ]),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: AppPalette.border),
      ),
    ));
}
