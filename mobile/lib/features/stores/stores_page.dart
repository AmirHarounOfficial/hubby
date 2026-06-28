import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/network/api_client.dart';
import '../../core/platforms.dart';
import '../../core/theme/app_palette.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../l10n/strings.dart';
import 'cubit/stores_cubit.dart';
import 'connect_sheet.dart';

class StoresPage extends StatelessWidget {
  const StoresPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('stores.title'))),
      body: BlocBuilder<StoresCubit, StoresState>(
        builder: (context, state) {
          if (state.loading) return const LoadingView();
          return RefreshIndicator(
            onRefresh: () => context.read<StoresCubit>().refresh(),
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Wrap(
                  spacing: 8, runSpacing: 8,
                  children: kPlatforms
                      .map((p) => ActionChip(
                            avatar: Icon(p.icon, size: 16, color: p.color),
                            label: Text(p.name),
                            onPressed: () => openConnectSheet(context, p.id),
                          ))
                      .toList(),
                ),
                const SizedBox(height: 16),
                if (state.stores.isEmpty)
                  ConnectPrompt(description: context.t('stores.firstStore'))
                else ...[
                  _summary(state.stores),
                  const SizedBox(height: 12),
                  _masterInfo(context, state.stores),
                  const SizedBox(height: 12),
                  ...state.stores.map((s) => _storeCard(context, s)),
                ],
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _summary(List stores) {
    int count(String s) => stores.where((e) => e['status'] == s).length;
    final cells = [
      ['Stores', '${stores.length}', AppPalette.foreground],
      ['Connected', '${count('connected')}', AppPalette.secondary],
      ['Syncing', '${count('syncing')}', AppPalette.warning],
      ['Errors', '${count('error')}', AppPalette.destructive],
    ];
    return Row(
      children: cells
          .map((c) => Expanded(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 3),
                  child: AppCard(
                    padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
                    child: Column(children: [
                      Text(c[1] as String,
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: c[2] as Color)),
                      Text(c[0] as String,
                          style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 10)),
                    ]),
                  ),
                ),
              ))
          .toList(),
    );
  }

  Widget _masterInfo(BuildContext context, List stores) {
    String? latest;
    for (final s in stores) {
      final t = s['last_synced_at']?.toString();
      if (t != null && (latest == null || t.compareTo(latest) > 0)) latest = t;
    }
    return AppCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Container(
            height: 34, width: 34,
            decoration: BoxDecoration(
                color: AppPalette.primary.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(10)),
            child: const Icon(LucideIcons.crown, color: AppPalette.primary, size: 18),
          ),
          const SizedBox(width: 10),
          Expanded(child: Text(context.t('stores.masterStore'), style: const TextStyle(fontWeight: FontWeight.bold))),
        ]),
        const SizedBox(height: 8),
        const Text(
            'Your master store is the central inventory source. When stock changes there, Hubby pushes the update to every other connected store.',
            style: TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
        const SizedBox(height: 8),
        Row(children: [
          const Icon(LucideIcons.refreshCw, size: 14, color: AppPalette.mutedForeground),
          const SizedBox(width: 6),
          Text('Last network sync ${_timeAgo(latest)}',
              style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
        ]),
      ]),
    );
  }

  String _timeAgo(String? iso) {
    if (iso == null) return 'never';
    final d = DateTime.tryParse(iso);
    if (d == null) return 'never';
    final s = DateTime.now().difference(d).inSeconds;
    if (s < 60) return 'just now';
    if (s < 3600) return '${s ~/ 60}m ago';
    if (s < 86400) return '${s ~/ 3600}h ago';
    return '${s ~/ 86400}d ago';
  }

  Widget _storeCard(BuildContext context, dynamic s) {
    final meta = platformFor(s['platform']);
    final status = s['status']?.toString() ?? 'disconnected';
    final color = switch (status) {
      'connected' => AppPalette.secondary,
      'syncing' => AppPalette.warning,
      'error' => AppPalette.destructive,
      _ => AppPalette.mutedForeground,
    };
    final domain = s['domain']?.toString() ?? '';
    final broken = status == 'disconnected' || status == 'error';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(meta.icon, color: meta.color, size: 26),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(s['name']?.toString() ?? meta.name, style: const TextStyle(fontWeight: FontWeight.bold)),
                  Row(children: [
                    Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
                    const SizedBox(width: 6),
                    Text(status, style: TextStyle(color: color, fontSize: 12)),
                  ]),
                  if (domain.isNotEmpty)
                    Text(domain, maxLines: 1, overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                  Text('Last synced ${_timeAgo(s['last_synced_at']?.toString())}',
                      style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                ],
              ),
            ),
            if (s['is_master'] == true)
              Tooltip(message: context.t('stores.masterStore'),
                  child: const Icon(LucideIcons.crown, color: AppPalette.primary, size: 18))
            else
              IconButton(
                icon: const Icon(LucideIcons.crown, size: 18, color: AppPalette.mutedForeground),
                tooltip: context.t('stores.setMaster'),
                onPressed: () async {
                  await context.read<ApiClient>().dio.post('/stores/${s['id']}/set-master');
                  if (context.mounted) {
                    showToast(context, '${s['name']} is now the master store.', ToastKind.success);
                    context.read<StoresCubit>().refresh();
                  }
                },
              ),
            IconButton(
              icon: const Icon(LucideIcons.refreshCw, size: 18),
              onPressed: () async {
                await context.read<ApiClient>().dio.post('/stores/${s['id']}/sync');
                if (context.mounted) {
                  showToast(context, 'Sync started.', ToastKind.success);
                  context.read<StoresCubit>().refresh();
                }
              },
            ),
            IconButton(
              icon: const Icon(LucideIcons.trash2, size: 18, color: AppPalette.destructive),
              tooltip: context.t('common.disconnect'),
              onPressed: () => _confirmDelete(context, s),
            ),
          ]),
          if (status == 'error') ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                  color: AppPalette.destructive.withValues(alpha: 0.08), borderRadius: BorderRadius.circular(10)),
              child: Row(children: const [
                Icon(LucideIcons.alertTriangle, size: 16, color: AppPalette.destructive),
                SizedBox(width: 8),
                Expanded(child: Text('Sync failed. Reconnect this store to restore syncing.',
                    style: TextStyle(color: AppPalette.destructive, fontSize: 12))),
              ]),
            ),
          ],
          if (broken) ...[
            const SizedBox(height: 8),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: () => openConnectSheet(context, s['platform']?.toString() ?? meta.id),
                icon: const Icon(LucideIcons.plug, size: 16),
                label: Text(context.t('common.reconnect')),
              ),
            ),
          ],
        ]),
      ),
    );
  }

  Future<void> _confirmDelete(BuildContext context, dynamic s) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Disconnect store'),
        content: Text('Disconnect “${s['name'] ?? 'this store'}”? This removes it from your dashboard.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppPalette.destructive),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Disconnect'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    await context.read<ApiClient>().dio.delete('/stores/${s['id']}');
    if (context.mounted) {
      showToast(context, 'Store disconnected.', ToastKind.success);
      context.read<StoresCubit>().refresh();
    }
  }
}
