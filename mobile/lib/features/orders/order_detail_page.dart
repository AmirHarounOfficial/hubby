import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/format.dart';
import '../../core/network/api_client.dart';
import '../../core/platforms.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import '../../shared/widgets/money_text.dart';
import '../customers/customer_detail_page.dart';

class OrderDetailPage extends StatefulWidget {
  const OrderDetailPage({super.key, required this.orderId});
  final int orderId;
  @override
  State<OrderDetailPage> createState() => _OrderDetailPageState();
}

class _OrderDetailPageState extends State<OrderDetailPage> {
  late Future<Map<String, dynamic>> _future = _load();
  bool _busy = false;

  Future<Map<String, dynamic>> _load() async {
    final res = await context.read<ApiClient>().dio.get('/orders/${widget.orderId}');
    return (res.data as Map).cast<String, dynamic>();
  }

  Future<void> _updateStatus(String status, {bool confirm = false}) async {
    if (confirm) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Cancel order?'),
          content: const Text('This marks the order cancelled and syncs it back to the platform.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('No')),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: AppPalette.destructive),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(context.t('orders.cancelOrder')),
            ),
          ],
        ),
      );
      if (ok != true) return;
    }
    setState(() => _busy = true);
    try {
      await context.read<ApiClient>().dio.put('/orders/${widget.orderId}', data: {'status': status});
      if (mounted) {
        showToast(context, 'Order marked $status.', ToastKind.success);
        setState(() => _future = _load());
      }
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    const steps = ['paid', 'processing', 'shipped', 'delivered'];
    return Scaffold(
      appBar: AppBar(title: const Text('Order')),
      body: AsyncView<Map<String, dynamic>>(
        future: _future,
        onRetry: () => setState(() => _future = _load()),
        builder: (context, o) {
          final status = (o['status'] ?? '').toString().toLowerCase();
          final currency = o['currency']?.toString();
          final items = (o['items'] as List?) ?? [];
          final meta = platformFor(o['store']?['platform']);
          final cancelled = status == 'cancelled';
          final currentStep = steps.indexOf(status);
          final subtotal = items.fold<double>(
              0, (a, it) => a + asNum(it['price']) * ((it['quantity'] ?? 1) as num).toDouble());
          final total = asNum(o['total']);
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              AppCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Icon(meta.icon, color: meta.color, size: 22),
                    const SizedBox(width: 10),
                    Text('#${(o['external_id'] ?? '').toString().toUpperCase()}',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                    const Spacer(),
                    MoneyText(total, code: currency,
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  ]),
                ]),
              ),
              const SizedBox(height: 16),
              _statusActions(status, cancelled),
              AppCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('common.status'), style: const TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 12),
                  if (cancelled)
                    Row(children: const [
                      Icon(LucideIcons.xCircle, color: AppPalette.destructive, size: 18),
                      SizedBox(width: 8),
                      Text('Cancelled', style: TextStyle(color: AppPalette.destructive, fontWeight: FontWeight.w600)),
                    ])
                  else
                    ...List.generate(steps.length, (i) {
                      final done = i <= currentStep;
                      return Padding(
                        padding: const EdgeInsets.symmetric(vertical: 5),
                        child: Row(children: [
                          Icon(done ? LucideIcons.checkCircle2 : LucideIcons.circle,
                              size: 18, color: done ? AppPalette.secondary : AppPalette.border),
                          const SizedBox(width: 10),
                          Text(steps[i][0].toUpperCase() + steps[i].substring(1),
                              style: TextStyle(
                                  color: done ? AppPalette.foreground : AppPalette.mutedForeground,
                                  fontWeight: i == currentStep ? FontWeight.bold : FontWeight.normal)),
                        ]),
                      );
                    }),
                ]),
              ),
              const SizedBox(height: 16),
              AppCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Text(context.t('orders.items'), style: const TextStyle(fontWeight: FontWeight.bold)),
                    const Spacer(),
                    Text('${items.length} product(s)',
                        style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
                  ]),
                  const SizedBox(height: 8),
                  ...items.map((it) {
                    final qty = (it['quantity'] ?? 1) as num;
                    final price = asNum(it['price']);
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6),
                      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(it['product_name']?.toString() ?? it['name']?.toString() ?? '',
                                maxLines: 1, overflow: TextOverflow.ellipsis),
                            Text('SKU ${it['sku']?.toString() ?? 'N/A'}  ·  ${formatMoney(price, currency)} × $qty',
                                style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                          ]),
                        ),
                        const SizedBox(width: 10),
                        MoneyText(price * qty.toDouble(), code: currency,
                            style: const TextStyle(fontWeight: FontWeight.w600)),
                      ]),
                    );
                  }),
                  const Divider(height: 20),
                  _totalRow(context.t('common.subtotal'), subtotal, currency, bold: false),
                  const SizedBox(height: 4),
                  _totalRow(context.t('common.total'), total, currency, bold: true),
                ]),
              ),
              const SizedBox(height: 16),
              _customerCard(o),
              const SizedBox(height: 16),
              _storeCard(o, meta, currency),
            ],
          );
        },
      ),
    );
  }

  Widget _statusActions(String status, bool cancelled) {
    final buttons = <Widget>[];
    if (status == 'pending') {
      buttons.add(FilledButton.icon(
        onPressed: _busy ? null : () => _updateStatus('Paid'),
        icon: const Icon(LucideIcons.checkCircle2, size: 16),
        label: Text(context.t('orders.markPaid')),
      ));
    }
    if (status == 'paid' || status == 'processing') {
      buttons.add(FilledButton.icon(
        onPressed: _busy ? null : () => _updateStatus('Shipped'),
        icon: const Icon(LucideIcons.truck, size: 16),
        label: Text(context.t('orders.fulfill')),
      ));
    }
    if (!cancelled && status != 'shipped' && status != 'delivered') {
      buttons.add(OutlinedButton.icon(
        onPressed: _busy ? null : () => _updateStatus('Cancelled', confirm: true),
        style: OutlinedButton.styleFrom(foregroundColor: AppPalette.destructive),
        icon: const Icon(LucideIcons.xCircle, size: 16),
        label: Text(context.t('orders.cancelOrder')),
      ));
    }
    if (buttons.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(bottom: 16),
      child: Wrap(spacing: 10, runSpacing: 10, children: buttons),
    );
  }

  Widget _customerCard(Map o) {
    final email = o['customer_email']?.toString() ?? '';
    final df = DateFormat.yMMMd().add_jm();
    return AppCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(context.t('orders.customer'), style: const TextStyle(fontWeight: FontWeight.bold)),
        const SizedBox(height: 10),
        Row(children: [
          CircleAvatar(
            radius: 18,
            backgroundColor: AppPalette.primary.withValues(alpha: 0.12),
            child: Text((o['customer_name']?.toString() ?? 'G').characters.first.toUpperCase(),
                style: const TextStyle(color: AppPalette.primary, fontWeight: FontWeight.bold)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(o['customer_name']?.toString() ?? 'Guest', style: const TextStyle(fontWeight: FontWeight.w600)),
              if (email.isNotEmpty)
                Text(email, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
            ]),
          ),
        ]),
        const SizedBox(height: 6),
        Text('Placed ${df.format(DateTime.tryParse(o['created_at']?.toString() ?? '') ?? DateTime(2020))}',
            style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
        if (email.isNotEmpty) ...[
          const SizedBox(height: 8),
          OutlinedButton.icon(
            onPressed: () => Navigator.of(context).push(
                MaterialPageRoute(builder: (_) => CustomerDetailPage(email: email))),
            icon: const Icon(LucideIcons.user, size: 16),
            label: Text(context.t('orders.viewProfile')),
          ),
        ],
      ]),
    );
  }

  Widget _storeCard(Map o, PlatformMeta meta, String? currency) {
    final store = o['store'];
    return AppCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(context.t('orders.store'), style: const TextStyle(fontWeight: FontWeight.bold)),
        const SizedBox(height: 10),
        Row(children: [
          Icon(meta.icon, color: meta.color, size: 20),
          const SizedBox(width: 10),
          Expanded(child: Text(store?['name']?.toString() ?? meta.name,
              style: const TextStyle(fontWeight: FontWeight.w600))),
          Text(meta.name, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
        ]),
        const SizedBox(height: 8),
        _kv('Original order ID', o['external_id']?.toString() ?? '—'),
        _kv('Currency', currency ?? 'SAR'),
      ]),
    );
  }

  Widget _kv(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(children: [
          Text(k, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
          const Spacer(),
          Text(v, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
        ]),
      );

  Widget _totalRow(String label, double amount, String? code, {required bool bold}) => Row(children: [
        Text(label,
            style: TextStyle(
                color: bold ? AppPalette.foreground : AppPalette.mutedForeground,
                fontWeight: bold ? FontWeight.bold : FontWeight.normal)),
        const Spacer(),
        MoneyText(amount, code: code,
            style: TextStyle(fontWeight: bold ? FontWeight.bold : FontWeight.w500, fontSize: bold ? 16 : 14)),
      ]);
}
