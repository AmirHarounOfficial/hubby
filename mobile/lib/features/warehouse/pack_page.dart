import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:provider/provider.dart';
import 'package:lucide_icons/lucide_icons.dart';

import '../../core/network/api_client.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import 'data/scan_queue.dart';
import 'scanner/scan_feedback.dart';
import 'scanner/scan_source.dart';

/// Packing verification (spec 08 §4.2/§6.5).
///
/// Every item that goes in the box is verified by barcode. An item in the wrong box is a return, a
/// refund and a bad review, so a wrong-item scan is a full-screen block — and the box cannot be
/// closed until every line is fully packed.
///
/// The session is opened by scanning the order (packing slip) rather than picking from a list: the
/// operator already has the paperwork in hand.
class PackPage extends StatefulWidget {
  const PackPage({super.key});

  @override
  State<PackPage> createState() => _PackPageState();
}

class _PackPageState extends State<PackPage> {
  final GlobalKey<ScanSourceState> _scanKey = GlobalKey<ScanSourceState>();
  final TextEditingController _weight = TextEditingController();

  Map<String, dynamic>? _session;
  bool _busy = false;

  @override
  void dispose() {
    _weight.dispose();
    super.dispose();
  }

  /// Before a session exists, a scan is interpreted as "which order am I packing?".
  Future<void> _openFromOrderScan(String barcode, ScanInput input) async {
    setState(() => _busy = true);
    try {
      // Resolve through the queue so the scan gets a proper idempotency key and lands in the audit
      // trail like every other scan.
      final resolved = await context.read<ScanQueue>().submit(
            endpoint: '/scan',
            payload: {'barcode': barcode, 'session_type': 'pack', 'input_method': input.name},
          );

      if (!mounted) return;
      if (resolved.queued) {
        await ScanFeedback.showFailure(
          context,
          title: context.t('warehouse.offlineLookupTitle'),
          detail: context.t('warehouse.packNeedsConnection'),
        );
        return;
      }

      final data = resolved.data ?? {};
      if ((data['kind'] as String?) != 'order') {
        await ScanFeedback.showFailure(
          context,
          title: context.t('warehouse.notAnOrder'),
          detail: context.t('warehouse.notAnOrderBody'),
        );
        return;
      }

      final api = context.read<ApiClient>();
      final orderId = (data['order'] as Map)['id'];
      final res = await api.dio.post('/pack-sessions', data: {'order_id': orderId});
      if (!mounted) return;
      ScanFeedback.success();
      setState(() => _session = Map<String, dynamic>.from(res.data as Map));
    } catch (e) {
      if (!mounted) return;
      await ScanFeedback.showFailure(context, title: context.t('warehouse.cannotPack'), detail: ApiClient.messageFrom(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _refresh() async {
    final session = _session;
    if (session == null) return;
    final api = context.read<ApiClient>();
    final res = await api.dio.get('/pack-sessions/${session['id']}');
    if (!mounted) return;
    setState(() => _session = Map<String, dynamic>.from(res.data as Map));
  }

  Future<void> _onScan(String barcode, ScanInput input) async {
    if (_busy) return;
    if (_session == null) return _openFromOrderScan(barcode, input);

    setState(() => _busy = true);
    final outcome = await context.read<ScanQueue>().submit(
          endpoint: '/pack-sessions/${_session!['id']}/scan',
          payload: {'barcode': barcode, 'input_method': input.name},
        );

    if (!mounted) return;
    setState(() => _busy = false);

    if (outcome.queued) {
      ScanFeedback.success();
      return;
    }

    switch (outcome.result) {
      case 'accepted':
        ScanFeedback.success();
        _scanKey.currentState?.resetDuplicateGuard();
        await _refresh();
      case 'wrong_item':
        await ScanFeedback.showFailure(
          context,
          title: context.t('warehouse.notInThisOrder'),
          detail: context.t('warehouse.notInThisOrderBody'),
        );
      case 'over_pick':
        await ScanFeedback.showFailure(
          context,
          title: context.t('warehouse.overPack'),
          detail: context.t('warehouse.overPackBody'),
        );
      default:
        await ScanFeedback.showFailure(context, title: context.t('warehouse.scanRejected'), detail: outcome.result);
    }
  }

  Future<void> _complete() async {
    final queue = context.read<ScanQueue>();
    if (queue.pending > 0) {
      showToast(context, context.t('warehouse.syncBeforeComplete'), ToastKind.error);
      return;
    }

    setState(() => _busy = true);
    try {
      final api = context.read<ApiClient>();
      await api.dio.post('/pack-sessions/${_session!['id']}/complete', data: {
        if (_weight.text.trim().isNotEmpty) 'weight_grams': int.tryParse(_weight.text.trim()),
      });
      if (!mounted) return;
      showToast(context, context.t('warehouse.packCompleted'), ToastKind.success);
      setState(() {
        _session = null;
        _weight.clear();
      });
    } catch (e) {
      if (!mounted) return;
      showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = _session;
    final items = (session?['items'] as List?) ?? [];
    final verified = session != null && session['status'] == 'verified';

    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('warehouse.pack')),
        actions: [
          if (session != null)
            TextButton(
              // Deliberately disabled until every line is packed — closing a half-packed box is
              // exactly the mistake that ships a short order.
              onPressed: (_busy || !verified) ? null : _complete,
              child: Text(context.t('warehouse.complete')),
            ),
        ],
      ),
      body: SafeArea(
        child: ScanSource(
          key: _scanKey,
          enabled: !_busy,
          onScan: _onScan,
          child: Column(
            children: [
              Padding(
                padding: const EdgeInsets.all(16),
                child: CameraScanner(
                  height: 180,
                  active: !(_scanKey.currentState?.hidDetected ?? false),
                  onDetect: (b) => _scanKey.currentState?.handle(b, ScanInput.camera),
                ),
              ),
              if (session == null)
                Expanded(
                  child: EmptyState(
                    icon: LucideIcons.package,
                    title: context.t('warehouse.scanOrderTitle'),
                    subtitle: context.t('warehouse.scanOrderSub'),
                  ),
                )
              else ...[
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Row(
                    children: [
                      Text('${session['code']}', style: Theme.of(context).textTheme.titleSmall),
                      const Spacer(),
                      if (verified)
                        Row(children: [
                          const Icon(LucideIcons.checkCircle2, color: Colors.green, size: 16),
                          const SizedBox(width: 6),
                          Text(context.t('warehouse.verified')),
                        ]),
                    ],
                  ),
                ),
                const SizedBox(height: 8),
                Expanded(
                  child: ListView.builder(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    itemCount: items.length,
                    itemBuilder: (_, i) {
                      final line = items[i] as Map;
                      final req = (line['qty_required'] as num?)?.toInt() ?? 0;
                      final packed = (line['qty_packed'] as num?)?.toInt() ?? 0;
                      final done = packed >= req;

                      return Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: AppCard(
                          child: Row(
                            children: [
                              Icon(done ? LucideIcons.checkCircle2 : LucideIcons.circle,
                                  color: done ? Colors.green : Colors.grey, size: 20),
                              const SizedBox(width: 12),
                              Expanded(child: Text('${line['name'] ?? line['sku'] ?? '—'}')),
                              Text('$packed / $req',
                                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: TextField(
                    controller: _weight,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(
                      labelText: context.t('warehouse.weightGrams'),
                      border: const OutlineInputBorder(),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
