import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:provider/provider.dart';
import 'package:go_router/go_router.dart';
import 'package:lucide_icons/lucide_icons.dart';

import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import 'data/scan_queue.dart';

/// The warehouse hub — the entry point an operator lands on (spec 08 §6.5).
///
/// Deliberately a short list of big targets: this screen is used one-handed, often with gloves,
/// while holding something. It also carries the sync state, because an operator needs to know at a
/// glance whether their scans have actually reached the server.
class WarehousePage extends StatelessWidget {
  const WarehousePage({super.key});

  @override
  Widget build(BuildContext context) {
    final queue = context.watch<ScanQueue>();

    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            PageHeader(title: context.t('warehouse.title')),
            const SizedBox(height: 8),
            _SyncBanner(queue: queue),
            const SizedBox(height: 16),
            _Tile(
              icon: LucideIcons.search,
              title: context.t('warehouse.lookup'),
              subtitle: context.t('warehouse.lookupSub'),
              onTap: () => context.push('/warehouse/lookup'),
            ),
            _Tile(
              icon: LucideIcons.packagePlus,
              title: context.t('warehouse.receive'),
              subtitle: context.t('warehouse.receiveSub'),
              onTap: () => context.push('/warehouse/receive'),
            ),
          ],
        ),
      ),
    );
  }
}

class _SyncBanner extends StatelessWidget {
  const _SyncBanner({required this.queue});

  final ScanQueue queue;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    // Three honest states. "Queued" is never dressed up as success — an operator must know when
    // the server has not actually confirmed their work.
    final (icon, label, color) = switch ((queue.online, queue.pending)) {
      (false, _) => (LucideIcons.wifiOff, context.t('warehouse.offline'), Colors.orange),
      (true, 0) => (LucideIcons.checkCircle2, context.t('warehouse.synced'), Colors.green),
      (true, _) => (LucideIcons.uploadCloud, '${queue.pending} ${context.t('warehouse.pendingScans')}', Colors.blue),
    };

    return AppCard(
      child: Row(
        children: [
          Icon(icon, color: color, size: 20),
          const SizedBox(width: 12),
          Expanded(child: Text(label, style: theme.textTheme.bodyMedium)),
          if (queue.pending > 0 && queue.online)
            TextButton(
              onPressed: queue.drain,
              child: Text(context.t('warehouse.syncNow')),
            ),
        ],
      ),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: AppCard(
          child: Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(icon, color: theme.colorScheme.primary, size: 26),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 2),
                    Text(subtitle, style: theme.textTheme.bodySmall?.copyWith(color: theme.hintColor)),
                  ],
                ),
              ),
              Icon(LucideIcons.chevronRight, color: theme.hintColor, size: 20),
            ],
          ),
        ),
      ),
    );
  }
}
