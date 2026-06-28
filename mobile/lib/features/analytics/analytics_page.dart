import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../core/format.dart';
import '../../core/network/api_client.dart';
import '../../core/platforms.dart';
import '../../core/theme/app_palette.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import '../../shared/widgets/money_text.dart';

class AnalyticsPage extends StatefulWidget {
  const AnalyticsPage({super.key});
  @override
  State<AnalyticsPage> createState() => _AnalyticsPageState();
}

class _AnalyticsPageState extends State<AnalyticsPage> {
  late Future<List<dynamic>> _future = _load();

  Future<List<dynamic>> _load() async {
    final api = context.read<ApiClient>();
    final results = await Future.wait([
      api.dio.get('/analytics/by-platform'),
      api.dio.get('/analytics/top-products'),
      api.dio.get('/analytics/top-customers'),
      api.dio.get('/analytics/orders-timeline', queryParameters: {'days': 30}),
    ]);
    return [results[0].data, results[1].data, results[2].data, results[3].data];
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Analytics')),
      body: AsyncView<List<dynamic>>(
        future: _future,
        onRetry: () => setState(() => _future = _load()),
        builder: (context, data) {
          final byPlatform = (data[0] as List?) ?? [];
          final topProducts = (data[1] as List?) ?? [];
          final topCustomers = (data[2] as List?) ?? [];
          final timeline = (data[3] as List?) ?? [];
          final totalRevenue = byPlatform.fold<double>(0, (a, p) => a + asNum(p['revenue']));
          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              const Text('Revenue overview', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 4),
              const Text('Last 30 days', style: TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
              const SizedBox(height: 10),
              if (timeline.length > 1) _revenueChart(timeline),
              const SizedBox(height: 20),
              const Text('Revenue by platform', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 10),
              AppCard(
                child: Column(
                  children: byPlatform.map((p) {
                    final meta = platformFor(p['platform']);
                    final revenue = asNum(p['revenue']);
                    final pct = totalRevenue > 0 ? (revenue / totalRevenue * 100) : 0;
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      child: Row(children: [
                        Icon(meta.icon, color: meta.color, size: 18),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(meta.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                            Text('${p['count'] ?? 0} orders · ${pct.toStringAsFixed(0)}%',
                                style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                          ]),
                        ),
                        MoneyText(revenue, style: const TextStyle(fontWeight: FontWeight.w600)),
                      ]),
                    );
                  }).toList(),
                ),
              ),
              const SizedBox(height: 20),
              const Text('Top products', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 10),
              AppCard(
                child: Column(
                  children: topProducts.take(8).map((p) => Padding(
                        padding: const EdgeInsets.symmetric(vertical: 8),
                        child: Row(children: [
                          Expanded(child: Text(p['name']?.toString() ?? '',
                              maxLines: 1, overflow: TextOverflow.ellipsis)),
                          Text('${p['units_sold'] ?? 0} sold',
                              style: const TextStyle(color: AppPalette.mutedForeground)),
                        ]),
                      )).toList(),
                ),
              ),
              const SizedBox(height: 20),
              const Text('Top customers', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 10),
              AppCard(
                child: topCustomers.isEmpty
                    ? const Text('No customer data yet.',
                        style: TextStyle(color: AppPalette.mutedForeground))
                    : Column(
                        children: topCustomers.map((c) => Padding(
                              padding: const EdgeInsets.symmetric(vertical: 8),
                              child: Row(children: [
                                CircleAvatar(
                                  radius: 14,
                                  backgroundColor: AppPalette.primary.withValues(alpha: 0.12),
                                  child: Text(
                                    (c['customer_name']?.toString().trim().isNotEmpty ?? false)
                                        ? c['customer_name'].toString()[0].toUpperCase()
                                        : '?',
                                    style: const TextStyle(
                                        color: AppPalette.primary, fontWeight: FontWeight.bold, fontSize: 12),
                                  ),
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                    Text(c['customer_name']?.toString() ?? 'Guest',
                                        maxLines: 1, overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                                    Text('${c['orders_count'] ?? 0} orders',
                                        style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                                  ]),
                                ),
                                MoneyText(c['total_spent'],
                                    style: const TextStyle(fontWeight: FontWeight.bold)),
                              ]),
                            )).toList(),
                      ),
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _revenueChart(List timeline) {
    final spots = <FlSpot>[];
    for (var i = 0; i < timeline.length; i++) {
      spots.add(FlSpot(i.toDouble(), asNum(timeline[i]['revenue'])));
    }
    return AppCard(
      child: SizedBox(
        height: 150,
        child: LineChart(LineChartData(
          gridData: const FlGridData(show: false),
          titlesData: const FlTitlesData(show: false),
          borderData: FlBorderData(show: false),
          lineBarsData: [
            LineChartBarData(
              spots: spots,
              isCurved: true,
              color: AppPalette.secondary,
              barWidth: 3,
              dotData: const FlDotData(show: false),
              belowBarData: BarAreaData(show: true, color: AppPalette.secondary.withValues(alpha: 0.12)),
            ),
          ],
        )),
      ),
    );
  }
}
