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
import '../orders/order_detail_page.dart';

class CustomerDetailPage extends StatefulWidget {
  const CustomerDetailPage({super.key, required this.email});
  final String email;
  @override
  State<CustomerDetailPage> createState() => _CustomerDetailPageState();
}

class _CustomerDetailPageState extends State<CustomerDetailPage> {
  late Future<Map<String, dynamic>> _future = _load();

  Future<Map<String, dynamic>> _load() async {
    final res = await context.read<ApiClient>().dio.get('/customers/${Uri.encodeComponent(widget.email)}');
    return (res.data as Map).cast<String, dynamic>();
  }

  /// Loyalty tiers mirror the web thresholds (SAR): Bronze / Silver / Gold / Platinum.
  (String, Color) _tier(double spend) {
    if (spend >= 6000) return ('Platinum', AppPalette.primary);
    if (spend >= 3000) return ('Gold', AppPalette.warning);
    if (spend >= 1000) return ('Silver', AppPalette.mutedForeground);
    return ('Bronze', AppPalette.secondary);
  }

  @override
  Widget build(BuildContext context) {
    final df = DateFormat.yMMMd();
    return Scaffold(
      appBar: AppBar(title: Text(context.t('nav.customers'))),
      body: AsyncView<Map<String, dynamic>>(
        future: _future,
        onRetry: () => setState(() => _future = _load()),
        builder: (context, c) {
          final orders = (c['orders'] as List?) ?? [];
          final currency = c['currency']?.toString();
          final totalOrders = (c['total_orders'] ?? orders.length) as num;
          final totalSpend = asNum(c['total_spend']);
          final aov = totalOrders > 0 ? totalSpend / totalOrders : 0;
          final tier = _tier(totalSpend);

          // Derive most-used platform + first/last order from the order history.
          final dates = orders
              .map((o) => DateTime.tryParse(o['created_at']?.toString() ?? ''))
              .whereType<DateTime>()
              .toList()
            ..sort();
          final platformCounts = <String, int>{};
          for (final o in orders) {
            final pl = o['store']?['platform']?.toString();
            if (pl != null) platformCounts[pl] = (platformCounts[pl] ?? 0) + 1;
          }
          final topPlatform = platformCounts.entries.isEmpty
              ? null
              : (platformCounts.entries.toList()..sort((a, b) => b.value.compareTo(a.value))).first.key;

          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              AppCard(
                child: Column(children: [
                  CircleAvatar(
                    radius: 28,
                    backgroundColor: AppPalette.primary.withValues(alpha: 0.12),
                    child: Text((c['name']?.toString() ?? 'C').characters.first.toUpperCase(),
                        style: const TextStyle(color: AppPalette.primary, fontWeight: FontWeight.bold, fontSize: 22)),
                  ),
                  const SizedBox(height: 10),
                  Text(c['name']?.toString() ?? 'Anonymous',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
                  Text(c['email']?.toString() ?? widget.email,
                      style: const TextStyle(color: AppPalette.mutedForeground)),
                  const SizedBox(height: 16),
                  Row(children: [
                    Expanded(child: _stat('Orders',
                        Text('$totalOrders', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)))),
                    Expanded(child: _stat('Spend',
                        MoneyText(totalSpend, code: currency,
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18)))),
                  ]),
                ]),
              ),
              const SizedBox(height: 16),
              AppCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('customers.contactDetails'), style: const TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 10),
                  _kv(LucideIcons.mail, 'Email', c['email']?.toString() ?? widget.email),
                  if (dates.isNotEmpty) _kv(LucideIcons.calendar, 'First order', df.format(dates.first)),
                  if (dates.isNotEmpty) _kv(LucideIcons.clock, 'Latest order', df.format(dates.last)),
                ]),
              ),
              const SizedBox(height: 16),
              Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Expanded(child: _insightCard(context.t('customers.buyingPatterns'), LucideIcons.trendingUp, [
                  '${totalOrders.toInt()} orders placed',
                  if (topPlatform != null) 'Mostly via ${platformFor(topPlatform).name}',
                  'AOV ${formatMoney(aov, currency)}',
                ])),
                const SizedBox(width: 12),
                Expanded(
                  child: AppCard(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        const Icon(LucideIcons.award, size: 16, color: AppPalette.primary),
                        const SizedBox(width: 8),
                        Text(context.t('customers.loyalty'), style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                      ]),
                      const SizedBox(height: 10),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                            color: tier.$2.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(20)),
                        child: Text(tier.$1, style: TextStyle(color: tier.$2, fontWeight: FontWeight.w700, fontSize: 13)),
                      ),
                      const SizedBox(height: 8),
                      Text('Lifetime ${formatMoney(totalSpend, currency)}',
                          style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                    ]),
                  ),
                ),
              ]),
              const SizedBox(height: 16),
              AppCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('customers.orderHistory'), style: const TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  if (orders.isEmpty)
                    const Text('No orders yet.', style: TextStyle(color: AppPalette.mutedForeground))
                  else
                    ...orders.map((o) {
                      final meta = platformFor(o['store']?['platform']);
                      return InkWell(
                        onTap: o['id'] is int
                            ? () => Navigator.of(context).push(MaterialPageRoute(
                                builder: (_) => OrderDetailPage(orderId: o['id'] as int)))
                            : null,
                        child: Padding(
                          padding: const EdgeInsets.symmetric(vertical: 8),
                          child: Row(children: [
                            Icon(meta.icon, color: meta.color, size: 18),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                Text('#${(o['external_id'] ?? '').toString().toUpperCase()}',
                                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                                Text(
                                    '${o['store']?['name']?.toString() ?? meta.name} · '
                                    '${df.format(DateTime.tryParse(o['created_at']?.toString() ?? '') ?? DateTime(2020))}',
                                    style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                              ]),
                            ),
                            Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                              MoneyText(o['total'], code: currency ?? o['currency']?.toString(),
                                  style: const TextStyle(fontWeight: FontWeight.w600)),
                              _statusText(o['status']?.toString() ?? ''),
                            ]),
                          ]),
                        ),
                      );
                    }),
                ]),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _stat(String label, Widget value) => Column(children: [
        value,
        Text(label, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
      ]);

  Widget _kv(IconData icon, String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(children: [
          Icon(icon, size: 15, color: AppPalette.mutedForeground),
          const SizedBox(width: 8),
          Text(label, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
          const Spacer(),
          Flexible(child: Text(value, overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12))),
        ]),
      );

  Widget _insightCard(String title, IconData icon, List<String> lines) => AppCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(icon, size: 16, color: AppPalette.primary),
            const SizedBox(width: 8),
            Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          ]),
          const SizedBox(height: 8),
          ...lines.map((l) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Text(l, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
              )),
        ]),
      );

  Widget _statusText(String status) {
    final color = switch (status.toLowerCase()) {
      'paid' || 'delivered' => AppPalette.secondary,
      'processing' => AppPalette.primary,
      'shipped' => Colors.blue,
      'pending' => AppPalette.warning,
      'cancelled' => AppPalette.destructive,
      _ => AppPalette.mutedForeground,
    };
    return Text(status, style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w600));
  }
}
