import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/network/api_client.dart';
import '../../core/platforms.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_theme.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import '../../shared/widgets/money_text.dart';
import '../stores/cubit/stores_cubit.dart';
import 'customer_detail_page.dart';

class CustomersPage extends StatefulWidget {
  const CustomersPage({super.key});
  @override
  State<CustomersPage> createState() => _CustomersPageState();
}

class _CustomersPageState extends State<CustomersPage> {
  String _search = '';
  String _platform = 'All';
  Timer? _debounce;
  late Future<List<dynamic>> _future = _load();

  Future<List<dynamic>> _load() async {
    final res = await context.read<ApiClient>().dio.get('/customers', queryParameters: {
      'per_page': 30,
      if (_search.isNotEmpty) 'search': _search,
      if (_platform != 'All') 'platform': _platform.toLowerCase(),
    });
    return (res.data['data'] as List?) ?? [];
  }

  void _reload() => setState(() => _future = _load());

  void _onSearch(String v) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 400), () { _search = v; _reload(); });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final platforms = context.watch<StoresCubit>().state.connectedPlatforms;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('nav.customers'))),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(children: [
              TextField(
                onChanged: _onSearch,
                decoration: InputDecoration(
                  hintText: context.t('customers.searchHint'),
                  prefixIcon: const Icon(LucideIcons.search, size: 18),
                ),
              ),
              const SizedBox(height: 10),
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(children: [
                  for (final p in ['All', ...platforms.map((e) => platformFor(e).name)])
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 8),
                      child: ChoiceChip(
                        label: Text(p == 'All' ? context.t('common.all') : p),
                        selected: _platform == p,
                        onSelected: (_) { setState(() => _platform = p); _reload(); },
                        selectedColor: AppPalette.primary,
                        labelStyle: TextStyle(
                            color: _platform == p ? Colors.white : AppPalette.foreground, fontSize: 12),
                        backgroundColor: AppPalette.card,
                        side: const BorderSide(color: AppPalette.border),
                      ),
                    ),
                ]),
              ),
            ]),
          ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async => _reload(),
              child: AsyncView<List<dynamic>>(
                future: _future,
                onRetry: _reload,
                builder: (context, items) {
                  if (items.isEmpty) {
                    return Center(child: Text(context.t('customers.none'),
                        style: const TextStyle(color: AppPalette.mutedForeground)));
                  }
                  return ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 10),
                    itemBuilder: (context, i) {
                      final c = items[i];
                      final platformsCsv = (c['platforms']?.toString() ?? '')
                          .split(',').where((e) => e.isNotEmpty).toList();
                      return AppCard(
                        onTap: () => Navigator.of(context).push(MaterialPageRoute(
                            builder: (_) => CustomerDetailPage(email: c['customer_email']?.toString() ?? ''))),
                        child: Row(children: [
                          CircleAvatar(
                            radius: 22,
                            backgroundColor: AppPalette.primary.withValues(alpha: 0.12),
                            child: Text((c['name']?.toString() ?? 'C').characters.first.toUpperCase(),
                                style: const TextStyle(color: AppPalette.primary, fontWeight: FontWeight.bold, fontSize: 16)),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(c['name']?.toString() ?? 'Anonymous',
                                    maxLines: 1, overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(fontWeight: FontWeight.w600)),
                                Text(c['customer_email']?.toString() ?? '',
                                    maxLines: 1, overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
                                if (platformsCsv.isNotEmpty)
                                  Padding(
                                    padding: const EdgeInsets.only(top: 5),
                                    child: Row(
                                      children: platformsCsv.map((p) {
                                        final m = platformFor(p);
                                        return Padding(
                                          padding: const EdgeInsetsDirectional.only(end: 6),
                                          child: Icon(m.icon, size: 14, color: m.color),
                                        );
                                      }).toList(),
                                    ),
                                  ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 8),
                          Column(
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              MoneyText(c['total_spend'],
                                  style: AppText.number(size: 15, color: AppPalette.secondary)),
                              const SizedBox(height: 2),
                              Text('${c['total_orders'] ?? 0} ${context.t('customers.orders')}',
                                  style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
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
      ),
    );
  }
}
