import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_palette.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import '../../l10n/strings.dart';
import '../stores/cubit/stores_cubit.dart';
import 'adjust_sheet.dart';

class InventoryPage extends StatefulWidget {
  const InventoryPage({super.key});
  @override
  State<InventoryPage> createState() => _InventoryPageState();
}

class _InventoryPageState extends State<InventoryPage> {
  String _filter = 'all';
  String _search = '';
  late Future<Map<String, dynamic>> _future = _load();

  Future<Map<String, dynamic>> _load() async {
    final api = context.read<ApiClient>();
    final r = await Future.wait([api.dio.get('/inventory'), api.dio.get('/inventory/logs')]);
    return {
      'inventory': (r[0].data as List?) ?? [],
      'logs': (r[1].data['data'] as List?) ?? [],
    };
  }

  int _stockOf(dynamic item) {
    final variants = item['variants'] as List?;
    if (variants != null && variants.isNotEmpty) {
      return variants.fold<int>(0, (a, v) => a + ((v['stock'] ?? 0) as num).toInt());
    }
    return ((item['stock'] ?? 0) as num).toInt();
  }

  @override
  Widget build(BuildContext context) {
    final stores = context.watch<StoresCubit>().state;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              child: PageHeader(
                title: context.t('nav.inventory'),
                trailing: OutlinedButton.icon(
                  onPressed: () async {
                    if (!stores.hasConnectedStore) {
                      showToast(context, 'Connect a store first to sync inventory.', ToastKind.info);
                      return;
                    }
                    try {
                      await context.read<ApiClient>().dio.post('/stores/sync-all');
                      if (mounted) showToast(context, 'Global sync started.', ToastKind.success);
                    } catch (e) {
                      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
                    }
                  },
                  icon: const Icon(LucideIcons.refreshCw, size: 16),
                  label: Text(context.t('common.sync')),
                ),
              ),
            ),
            Expanded(
              child: (!stores.loading && !stores.hasConnectedStore)
                  ? Padding(padding: const EdgeInsets.all(16),
                      child: ConnectPrompt(description: context.t('inventory.connectPrompt')))
                  : RefreshIndicator(
                      onRefresh: () async => setState(() => _future = _load()),
                      child: AsyncView<Map<String, dynamic>>(
                        future: _future,
                        onRetry: () => setState(() => _future = _load()),
                        builder: (context, data) {
                          final inventory = (data['inventory'] as List?) ?? [];
                          final logs = (data['logs'] as List?) ?? [];
                          final filtered = inventory.where((i) {
                            final name = (i['name']?.toString() ?? '').toLowerCase();
                            final sku = (i['sku']?.toString() ?? '').toLowerCase();
                            if (_search.isNotEmpty &&
                                !name.contains(_search.toLowerCase()) &&
                                !sku.contains(_search.toLowerCase())) {
                              return false;
                            }
                            final s = _stockOf(i);
                            if (_filter == 'low') return s > 0 && s < 10;
                            if (_filter == 'out') return s == 0;
                            return true;
                          }).toList();
                          return ListView(
                            padding: const EdgeInsets.all(16),
                            children: [
                              TextField(
                                onChanged: (v) => setState(() => _search = v),
                                decoration: InputDecoration(
                                  hintText: context.t('inventory.searchHint'),
                                  prefixIcon: const Icon(LucideIcons.search, size: 18),
                                ),
                              ),
                              const SizedBox(height: 10),
                              Row(children: [
                                for (final f in [
                                  ['all', context.t('common.all')],
                                  ['low', context.t('inventory.low')],
                                  ['out', context.t('inventory.out')],
                                ])
                                  Padding(
                                    padding: const EdgeInsetsDirectional.only(end: 8),
                                    child: ChoiceChip(
                                      label: Text(f[1]),
                                      selected: _filter == f[0],
                                      onSelected: (_) => setState(() => _filter = f[0]),
                                      selectedColor: AppPalette.primary,
                                      labelStyle: TextStyle(
                                          color: _filter == f[0] ? Colors.white : AppPalette.foreground, fontSize: 12),
                                      backgroundColor: AppPalette.card,
                                      side: const BorderSide(color: AppPalette.border),
                                    ),
                                  ),
                              ]),
                              const SizedBox(height: 12),
                              _syncHealth(stores),
                              const SizedBox(height: 12),
                              ...filtered.map((item) => _row(item)),
                              if (logs.isNotEmpty) ...[
                                const SizedBox(height: 16),
                                _logsCard(logs),
                              ],
                            ],
                          );
                        },
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _row(dynamic item) {
    final stock = _stockOf(item);
    final out = stock == 0, low = stock < 10;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppCard(
        onTap: () async {
          final changed = await openAdjustSheet(context, (item as Map).cast<String, dynamic>());
          if (changed && mounted) {
            showToast(context, 'Stock adjusted.', ToastKind.success);
            setState(() => _future = _load());
          }
        },
        child: Row(children: [
            Icon(out || low ? LucideIcons.alertTriangle : LucideIcons.checkCircle2,
                color: out ? AppPalette.destructive : low ? AppPalette.warning : AppPalette.secondary, size: 20),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(item['name']?.toString() ?? '', maxLines: 1, overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w600)),
                Row(children: [
                  Text(item['sku']?.toString() ?? 'NO-SKU',
                      style: const TextStyle(color: AppPalette.primary, fontSize: 11, fontWeight: FontWeight.w600)),
                  const SizedBox(width: 8),
                  Text(out ? context.t('products.outOfStock') : low ? context.t('products.lowStock') : context.t('inventory.inSync'),
                      style: TextStyle(
                          fontSize: 11,
                          color: out ? AppPalette.destructive : low ? AppPalette.warning : AppPalette.secondary)),
                ]),
              ]),
            ),
            Text('$stock', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold,
                color: out ? AppPalette.destructive : low ? AppPalette.warning : AppPalette.foreground)),
            const SizedBox(width: 8),
            const Icon(LucideIcons.chevronRight, size: 16, color: AppPalette.mutedForeground),
          ]),
        ),
    );
  }

  Widget _syncHealth(StoresState stores) {
    final connected = stores.stores.where((s) => s['status'] == 'connected').length;
    final total = stores.stores.length;
    final pct = total == 0 ? 0.0 : connected / total;
    return AppCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          const Icon(LucideIcons.refreshCw, color: AppPalette.primary, size: 18),
          const SizedBox(width: 8),
          Expanded(child: Text(context.t('inventory.syncHealth'), style: const TextStyle(fontWeight: FontWeight.bold))),
          Text('$connected / $total stores', style: const TextStyle(color: AppPalette.secondary, fontSize: 12)),
        ]),
        const SizedBox(height: 10),
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: LinearProgressIndicator(value: pct, minHeight: 6,
              backgroundColor: AppPalette.accent, color: AppPalette.secondary),
        ),
      ]),
    );
  }

  Widget _logsCard(List logs) {
    final df = DateFormat.MMMd();
    return AppCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(context.t('inventory.recentAdjustments'), style: const TextStyle(fontWeight: FontWeight.bold)),
        const SizedBox(height: 8),
        ...logs.take(8).map((log) {
          final change = (log['change'] ?? 0) as num;
          final up = change > 0;
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 7),
            child: Row(children: [
              Icon(up ? LucideIcons.arrowUpRight : LucideIcons.arrowDownRight,
                  size: 16, color: up ? AppPalette.secondary : AppPalette.destructive),
              const SizedBox(width: 10),
              Expanded(child: Text(log['product']?['name']?.toString() ?? 'Product',
                  maxLines: 1, overflow: TextOverflow.ellipsis)),
              Text('${log['source'] ?? ''} • ${df.format(DateTime.tryParse(log['created_at']?.toString() ?? '') ?? DateTime(2020))}',
                  style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
              const SizedBox(width: 8),
              Text('${up ? '+' : ''}$change',
                  style: TextStyle(fontWeight: FontWeight.bold,
                      color: up ? AppPalette.secondary : AppPalette.destructive)),
            ]),
          );
        }),
      ]),
    );
  }
}
