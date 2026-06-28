import 'dart:async';
import 'dart:io';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';
import 'package:lucide_icons/lucide_icons.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import '../../core/network/api_client.dart';
import '../../core/platforms.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_theme.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import '../../shared/widgets/money_text.dart';
import '../stores/cubit/stores_cubit.dart';
import 'order_detail_page.dart';

class OrdersPage extends StatefulWidget {
  const OrdersPage({super.key});
  @override
  State<OrdersPage> createState() => _OrdersPageState();
}

class _OrdersPageState extends State<OrdersPage> {
  String _platform = 'All';
  String _status = 'All';
  String _search = '';
  Timer? _debounce;
  late Future<List<dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  Future<List<dynamic>> _load() async {
    final api = context.read<ApiClient>();
    final res = await api.dio.get('/orders', queryParameters: {
      'per_page': 30,
      if (_platform != 'All') 'platform': _platform.toLowerCase(),
      if (_status != 'All') 'status': _status.toLowerCase(),
      if (_search.isNotEmpty) 'search': _search,
    });
    return (res.data['data'] as List?) ?? [];
  }

  void _reload() => setState(() => _future = _load());

  void _onSearch(String v) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () { _search = v; _reload(); });
  }

  bool _exporting = false;

  Future<void> _export() async {
    setState(() => _exporting = true);
    try {
      final res = await context.read<ApiClient>().dio.get('/orders/export',
          queryParameters: {
            if (_platform != 'All') 'platform': _platform.toLowerCase(),
            if (_status != 'All') 'status': _status.toLowerCase(),
          },
          options: Options(responseType: ResponseType.plain));
      final csv = res.data?.toString() ?? '';
      final rows = csv.trim().isEmpty ? 0 : csv.trim().split('\n').length - 1;

      final stamp = DateFormat('yyyy-MM-dd').format(DateTime.now());
      final dir = await getTemporaryDirectory();
      final file = File('${dir.path}/orders-export-$stamp.csv');
      await file.writeAsString(csv);

      await SharePlus.instance.share(ShareParams(
        files: [XFile(file.path, mimeType: 'text/csv', name: 'orders-export-$stamp.csv')],
        subject: 'Orders export',
        text: 'Orders export ($rows order(s))',
      ));
      if (mounted) showToast(context, 'Exported $rows order(s).', ToastKind.success);
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _exporting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final stores = context.watch<StoresCubit>().state;
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              PageHeader(
                title: context.t('nav.orders'),
                trailing: OutlinedButton.icon(
                  onPressed: (_exporting || !stores.hasConnectedStore) ? null : _export,
                  icon: _exporting
                      ? const SizedBox(height: 14, width: 14, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(LucideIcons.download, size: 16),
                  label: Text(context.t('common.export')),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                onChanged: _onSearch,
                decoration: InputDecoration(
                  hintText: context.t('orders.searchHint'),
                  prefixIcon: const Icon(LucideIcons.search, size: 18),
                ),
              ),
              const SizedBox(height: 10),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(children: [
                  for (final p in ['All', ...stores.connectedPlatforms.map((e) => platformFor(e).name)])
                    _chip(p == 'All' ? context.t('common.all') : p, _platform == p,
                        () { setState(() => _platform = p); _reload(); }),
                ]),
              ),
              const SizedBox(height: 6),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(children: [
                  for (final s in ['All', 'Pending', 'Processing', 'Paid', 'Shipped', 'Cancelled'])
                    _chip(_statusLabel(context, s), _status == s,
                        () { setState(() => _status = s); _reload(); }, AppPalette.secondary),
                ]),
              ),
            ],
          ),
        ),
        Expanded(
          child: (!stores.loading && !stores.hasConnectedStore)
              ? Padding(padding: const EdgeInsets.all(16),
                  child: ConnectPrompt(description: context.t('orders.connectPrompt')))
              : RefreshIndicator(
                  onRefresh: () async => _reload(),
                  child: AsyncView<List<dynamic>>(
                    future: _future,
                    onRetry: _reload,
                    builder: (context, orders) {
                      if (orders.isEmpty) {
                        return Center(child: Text(context.t('orders.none')));
                      }
                      return ListView.separated(
                        padding: const EdgeInsets.all(16),
                        itemCount: orders.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 10),
                        itemBuilder: (context, i) {
                          final o = orders[i];
                          final meta = platformFor(o['store']?['platform']);
                          final email = o['customer_email']?.toString() ?? '';
                          final date = DateTime.tryParse(o['created_at']?.toString() ?? '');
                          return AppCard(
                            onTap: () => Navigator.of(context).push(MaterialPageRoute(
                                builder: (_) => OrderDetailPage(orderId: o['id'] as int))),
                            child: Row(children: [
                              Icon(meta.icon, color: meta.color, size: 22),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text('#${(o['external_id'] ?? '').toString().toUpperCase()}',
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                                    Text(o['customer_name']?.toString() ?? 'Guest',
                                        maxLines: 1, overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
                                    if (email.isNotEmpty)
                                      Text(email, maxLines: 1, overflow: TextOverflow.ellipsis,
                                          style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                                    if (date != null)
                                      Text(DateFormat.yMMMd().add_jm().format(date),
                                          style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                                  ],
                                ),
                              ),
                              Column(
                                crossAxisAlignment: CrossAxisAlignment.end,
                                children: [
                                  MoneyText(o['total'], code: o['currency']?.toString(),
                                      style: AppText.number(size: 14)),
                                  const SizedBox(height: 4),
                                  _statusBadge(o['status']?.toString() ?? ''),
                                ],
                              ),
                            ]),
                          );
                        },
                      );
                    },
                  ),
                ),
        ),
      ],
    );
  }

  String _statusLabel(BuildContext context, String status) {
    switch (status) {
      case 'All':
        return context.t('common.all');
      case 'Pending':
        return context.t('orders.pending');
      case 'Processing':
        return context.t('orders.processing');
      case 'Paid':
        return context.t('orders.paid');
      case 'Shipped':
        return context.t('orders.shipped');
      case 'Cancelled':
        return context.t('orders.cancelled');
      default:
        return status;
    }
  }

  Widget _chip(String label, bool active, VoidCallback onTap, [Color color = AppPalette.primary]) {
    return Padding(
      padding: const EdgeInsetsDirectional.only(end: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: active,
        onSelected: (_) => onTap(),
        selectedColor: color,
        labelStyle: TextStyle(color: active ? Colors.white : AppPalette.foreground, fontSize: 12),
        backgroundColor: AppPalette.card,
        side: const BorderSide(color: AppPalette.border),
      ),
    );
  }

  Widget _statusBadge(String status) {
    final color = switch (status.toLowerCase()) {
      'paid' || 'delivered' => AppPalette.secondary,
      'processing' => AppPalette.primary,
      'shipped' => AppPalette.info,
      'pending' => AppPalette.warning,
      'cancelled' => AppPalette.destructive,
      _ => AppPalette.mutedForeground,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(AppPalette.rPill)),
      child: Text(status.isEmpty ? '—' : status,
          style: TextStyle(color: color, fontSize: 10.5, fontWeight: FontWeight.w700)),
    );
  }
}
