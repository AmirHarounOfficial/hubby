import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import '../../shared/widgets/money_text.dart';

class BillingPage extends StatefulWidget {
  const BillingPage({super.key});
  @override
  State<BillingPage> createState() => _BillingPageState();
}

class _BillingPageState extends State<BillingPage> {
  late Future<Map<String, dynamic>> _future = _load();
  int? _busyPlan;
  bool _cancelling = false;

  Future<Map<String, dynamic>> _load() async {
    final api = context.read<ApiClient>();
    final r = await Future.wait([api.dio.get('/billing/plans'), api.dio.get('/billing/status')]);
    return {
      'plans': (r[0].data as List?) ?? [],
      'subscription': (r[1].data is Map) ? r[1].data['subscription'] : null,
    };
  }

  Future<void> _cancel() async {
    setState(() => _cancelling = true);
    try {
      await context.read<ApiClient>().dio.post('/billing/cancel');
      if (mounted) {
        showToast(context, 'Subscription cancelled.', ToastKind.success);
        setState(() => _future = _load());
      }
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _cancelling = false);
    }
  }

  Future<void> _subscribe(int planId) async {
    setState(() => _busyPlan = planId);
    try {
      final res = await context.read<ApiClient>().dio.post('/billing/subscribe', data: {'plan_id': planId});
      if (!mounted) return;
      if (res.data['checkout_url'] != null) {
        showToast(context, 'Continue to checkout in your browser.', ToastKind.info);
      } else {
        showToast(context, 'Subscription updated.', ToastKind.success);
      }
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _busyPlan = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('nav.billing'))),
      body: AsyncView<Map<String, dynamic>>(
        future: _future,
        onRetry: () => setState(() => _future = _load()),
        builder: (context, data) {
          final plans = (data['plans'] as List?) ?? [];
          final sub = data['subscription'];
          final activePlanId = sub?['plan_id'];
          return ListView(
          padding: const EdgeInsets.all(16),
          children: [
          if (sub != null) Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: AppCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t('billing.currentPlan'), style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
                const SizedBox(height: 4),
                Text(sub['plan']?['name']?.toString() ?? 'Active',
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                Text('Status: ${sub['status'] ?? ''}', style: const TextStyle(color: AppPalette.mutedForeground)),
                if (sub['ends_at'] != null)
                  Text(
                      '${context.t('billing.nextBilling')} ${DateFormat.yMMMd().format(DateTime.tryParse(sub['ends_at'].toString()) ?? DateTime(2020))}',
                      style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
                if (sub['status'] == 'active') ...[
                  const SizedBox(height: 12),
                  OutlinedButton(
                    onPressed: _cancelling ? null : _cancel,
                    style: OutlinedButton.styleFrom(foregroundColor: AppPalette.destructive),
                    child: Text(_cancelling ? 'Cancelling…' : context.t('billing.cancelSub')),
                  ),
                ],
              ]),
            ),
          ),
          ...plans.map((p) {
            final features = (p['features'] is List) ? (p['features'] as List) : const [];
            final isCurrent = activePlanId == p['id'];
            final recommended = (p['name']?.toString().toLowerCase().contains('pro') ?? false);
            return Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: AppCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(children: [
                      Text(p['name']?.toString() ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      if (recommended) ...[
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                          decoration: BoxDecoration(
                              color: AppPalette.primary, borderRadius: BorderRadius.circular(20)),
                          child: Text(context.t('billing.recommended'),
                              style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w700)),
                        ),
                      ],
                    ]),
                    MoneyText(p['price'], suffix: ' / mo',
                        style: const TextStyle(color: AppPalette.mutedForeground)),
                    if ((p['description']?.toString() ?? '').isNotEmpty) ...[
                      const SizedBox(height: 6),
                      Text(p['description'].toString(),
                          style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 13)),
                    ],
                    const SizedBox(height: 10),
                    ...features.map((f) => Padding(
                          padding: const EdgeInsets.symmetric(vertical: 3),
                          child: Row(children: [
                            const Icon(LucideIcons.check, size: 14, color: AppPalette.secondary),
                            const SizedBox(width: 8),
                            Expanded(child: Text(f.toString(), style: const TextStyle(fontSize: 13))),
                          ]),
                        )),
                    const SizedBox(height: 12),
                    SizedBox(
                      width: double.infinity,
                      child: FilledButton(
                        onPressed: isCurrent || _busyPlan == p['id'] ? null : () => _subscribe(p['id']),
                        child: _busyPlan == p['id']
                            ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
                            : Text(isCurrent ? context.t('billing.currentPlan') : 'Switch to ${p['name']}'),
                      ),
                    ),
                  ],
                ),
              ),
            );
          }),
          ],
          );
        },
      ),
    );
  }
}
