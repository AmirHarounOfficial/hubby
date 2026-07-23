import 'package:dio/dio.dart';
import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../core/format.dart';
import '../../core/network/api_client.dart';
import '../../core/platforms.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import '../../shared/widgets/money_text.dart';
import '../../shared/widgets/platform_logo.dart';

/// Mobile parity for the web /profit dashboard: KPIs, a coverage note, a
/// revenue-vs-profit trend, and per-product / per-channel breakdowns. Reads the
/// same materialized rollups; gated by cost.access, so a 403 shows a clear note.
class ProfitPage extends StatefulWidget {
  const ProfitPage({super.key});
  @override
  State<ProfitPage> createState() => _ProfitPageState();
}

class _ProfitPageState extends State<ProfitPage> {
  int _days = 30;
  late Future<Map<String, dynamic>> _future = _load();

  String _fmt(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<Map<String, dynamic>> _load() async {
    final api = context.read<ApiClient>();
    final now = DateTime.now();
    final params = {
      'start_date': _fmt(now.subtract(Duration(days: _days - 1))),
      'end_date': _fmt(now),
    };
    try {
      final results = await Future.wait([
        api.dio.get('/analytics/profit', queryParameters: params),
        api.dio.get('/analytics/profit/timeline', queryParameters: params),
        api.dio.get('/analytics/profit/by-sku', queryParameters: params),
        api.dio.get('/analytics/profit/by-channel', queryParameters: params),
      ]);
      return {
        'summary': results[0].data,
        'timeline': results[1].data,
        'bySku': results[2].data,
        'byChannel': results[3].data,
      };
    } on DioException catch (e) {
      if (e.response?.statusCode == 403) return {'forbidden': true};
      rethrow;
    }
  }

  void _setDays(int days) {
    if (days == _days) return;
    setState(() {
      _days = days;
      _future = _load();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('profit.title'))),
      body: AsyncView<Map<String, dynamic>>(
        future: _future,
        onRetry: () => setState(() => _future = _load()),
        builder: (context, data) {
          if (data['forbidden'] == true) {
            return EmptyState(
              icon: Icons.lock_outline,
              title: context.t('profit.noAccess'),
            );
          }

          final summary = (data['summary'] as Map?) ?? {};
          final timeline = (data['timeline'] as List?) ?? [];
          final bySku = (data['bySku'] as List?) ?? [];
          final byChannel = (data['byChannel'] as List?) ?? [];
          final orders = summary['orders'] ?? 0;

          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              Text(context.t('profit.subtitle'),
                  style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
              const SizedBox(height: 12),
              _rangeSelector(),
              const SizedBox(height: 16),
              if (orders == 0)
                EmptyState(icon: Icons.query_stats, title: context.t('profit.empty'))
              else ...[
                _coverageNote(summary['coverage'] as Map? ?? {}),
                const SizedBox(height: 12),
                _kpiGrid(summary),
                const SizedBox(height: 20),
                if (timeline.length > 1) ...[
                  _sectionTitle(context.t('profit.trend')),
                  const SizedBox(height: 10),
                  _trendChart(timeline),
                  const SizedBox(height: 20),
                ],
                if (bySku.isNotEmpty) ...[
                  _sectionTitle(context.t('profit.byProduct')),
                  const SizedBox(height: 10),
                  _bySkuCard(bySku),
                  const SizedBox(height: 20),
                ],
                if (byChannel.isNotEmpty) ...[
                  _sectionTitle(context.t('profit.byChannel')),
                  const SizedBox(height: 10),
                  _byChannelCard(byChannel),
                ],
              ],
            ],
          );
        },
      ),
    );
  }

  Widget _sectionTitle(String text) =>
      Text(text, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16));

  Widget _rangeSelector() {
    final options = [7, 30, 90];
    return Row(
      children: options.map((d) {
        final selected = d == _days;
        return Padding(
          padding: const EdgeInsets.only(right: 8),
          child: ChoiceChip(
            label: Text(context.t('profit.range.$d')),
            selected: selected,
            onSelected: (_) => _setDays(d),
            showCheckmark: false,
            selectedColor: AppPalette.primary,
            labelStyle: TextStyle(
              color: selected ? Colors.white : AppPalette.foreground,
              fontSize: 12,
              fontWeight: FontWeight.w600,
            ),
            backgroundColor: AppPalette.card,
            side: const BorderSide(color: AppPalette.border),
          ),
        );
      }).toList(),
    );
  }

  Widget _coverageNote(Map coverage) {
    final pctRaw = coverage['cost_coverage_pct'];
    final covered = pctRaw == null ? null : (asNum(pctRaw) * 100).round();
    final complete = covered != null && covered >= 100;
    final none = covered != null && covered <= 0;

    final String message;
    if (complete) {
      message = context.t('profit.coverageFull');
    } else if (none || covered == null) {
      message = context.t('profit.coverageNone');
    } else {
      message = context.t('profit.coveragePartial').replaceAll('{p}', '$covered');
    }

    final color = complete ? AppPalette.secondary : AppPalette.warning;
    return AppCard(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(complete ? Icons.verified_outlined : Icons.info_outline, size: 18, color: color),
          const SizedBox(width: 10),
          Expanded(
            child: Text(message, style: const TextStyle(fontSize: 12, color: AppPalette.foregroundSoft)),
          ),
        ],
      ),
    );
  }

  Widget _kpiGrid(Map summary) {
    final netProfit = asNum(summary['net_profit']);
    final margin = summary['margin_pct'] == null ? null : (asNum(summary['margin_pct']) * 100);
    Widget tile(String label, Widget value, {String? hint}) => Expanded(
          child: AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label.toUpperCase(),
                    style: const TextStyle(
                        fontSize: 10, fontWeight: FontWeight.bold, color: AppPalette.mutedForeground, letterSpacing: 0.5)),
                const SizedBox(height: 4),
                value,
                if (hint != null) ...[
                  const SizedBox(height: 2),
                  Text(hint, style: const TextStyle(fontSize: 10, color: AppPalette.mutedForeground)),
                ],
              ],
            ),
          ),
        );

    return Column(
      children: [
        Row(children: [
          tile(context.t('profit.netRevenue'),
              MoneyText(summary['net_revenue'], style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16))),
          const SizedBox(width: 12),
          tile(context.t('profit.cogs'),
              MoneyText(summary['cogs'],
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: AppPalette.mutedForeground))),
        ]),
        const SizedBox(height: 12),
        Row(children: [
          tile(context.t('profit.fees'),
              MoneyText(summary['fees'],
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: AppPalette.mutedForeground))),
          const SizedBox(width: 12),
          tile(
            context.t('profit.netProfit'),
            MoneyText(summary['net_profit'],
                style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                    color: netProfit >= 0 ? AppPalette.secondary : AppPalette.destructive)),
            hint: margin == null
                ? orderCount(summary)
                : '${margin.toStringAsFixed(1)}% ${context.t('profit.margin')} · ${summary['orders']} ${context.t('profit.orders')}',
          ),
        ]),
      ],
    );
  }

  String orderCount(Map summary) => '${summary['orders']} ${context.t('profit.orders')}';

  Widget _trendChart(List timeline) {
    final revenue = <FlSpot>[];
    final profit = <FlSpot>[];
    for (var i = 0; i < timeline.length; i++) {
      revenue.add(FlSpot(i.toDouble(), asNum(timeline[i]['net_revenue'])));
      profit.add(FlSpot(i.toDouble(), asNum(timeline[i]['net_profit'])));
    }
    return AppCard(
      child: Column(
        children: [
          SizedBox(
            height: 160,
            child: LineChart(LineChartData(
              gridData: const FlGridData(show: false),
              titlesData: const FlTitlesData(show: false),
              borderData: FlBorderData(show: false),
              lineBarsData: [
                LineChartBarData(
                  spots: revenue,
                  isCurved: true,
                  color: AppPalette.primary,
                  barWidth: 2.5,
                  dotData: const FlDotData(show: false),
                  belowBarData: BarAreaData(show: true, color: AppPalette.primary.withValues(alpha: 0.08)),
                ),
                LineChartBarData(
                  spots: profit,
                  isCurved: true,
                  color: AppPalette.secondary,
                  barWidth: 2.5,
                  dotData: const FlDotData(show: false),
                  belowBarData: BarAreaData(show: true, color: AppPalette.secondary.withValues(alpha: 0.12)),
                ),
              ],
            )),
          ),
          const SizedBox(height: 10),
          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            _legendDot(AppPalette.primary, context.t('profit.netRevenue')),
            const SizedBox(width: 16),
            _legendDot(AppPalette.secondary, context.t('profit.netProfit')),
          ]),
        ],
      ),
    );
  }

  Widget _legendDot(Color color, String label) => Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 8, height: 8, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
        const SizedBox(width: 6),
        Text(label, style: const TextStyle(fontSize: 11, color: AppPalette.mutedForeground)),
      ]);

  Widget _bySkuCard(List bySku) {
    return AppCard(
      child: Column(
        children: bySku.take(10).map((row) {
          final profit = asNum(row['net_profit']);
          final margin = row['margin_pct'] == null ? null : (asNum(row['margin_pct']) * 100);
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Flexible(
                      child: Text(row['sku']?.toString() ?? '—',
                          maxLines: 1, overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                    ),
                    if (row['is_estimated'] == true) ...[
                      const SizedBox(width: 6),
                      _estimatedBadge(),
                    ],
                  ]),
                  Text(
                    '${row['units'] ?? 0} ${context.t('profit.units')}'
                    '${margin == null ? '' : ' · ${margin.toStringAsFixed(0)}%'}',
                    style: const TextStyle(fontSize: 11, color: AppPalette.mutedForeground),
                  ),
                ]),
              ),
              MoneyText(row['net_profit'],
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: profit >= 0 ? AppPalette.secondary : AppPalette.destructive)),
            ]),
          );
        }).toList(),
      ),
    );
  }

  Widget _byChannelCard(List byChannel) {
    return AppCard(
      child: Column(
        children: byChannel.map((row) {
          final meta = platformFor(row['platform']);
          final profit = asNum(row['net_profit']);
          final margin = row['margin_pct'] == null ? null : (asNum(row['margin_pct']) * 100);
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(children: [
              PlatformLogo(platformId: meta.id, size: 18),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(row['store_name']?.toString() ?? meta.name,
                      maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  Text(
                    '${row['orders'] ?? 0} ${context.t('profit.orders')}'
                    '${margin == null ? '' : ' · ${margin.toStringAsFixed(0)}%'}',
                    style: const TextStyle(fontSize: 11, color: AppPalette.mutedForeground),
                  ),
                ]),
              ),
              MoneyText(row['net_profit'],
                  style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: profit >= 0 ? AppPalette.secondary : AppPalette.destructive)),
            ]),
          );
        }).toList(),
      ),
    );
  }

  Widget _estimatedBadge() => Container(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
        decoration: BoxDecoration(
          color: AppPalette.warningSoft,
          borderRadius: BorderRadius.circular(4),
        ),
        child: Text(context.t('profit.estimated'),
            style: const TextStyle(fontSize: 9, fontWeight: FontWeight.bold, color: AppPalette.warning)),
      );
}
