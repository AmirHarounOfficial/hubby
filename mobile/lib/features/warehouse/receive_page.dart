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

/// Inbound receiving on the phone (spec 08 §6.5).
///
/// This is the workflow that most needs the offline queue: goods-in is often a loading bay with no
/// signal. Scans are queued locally and replayed, and the operator is told plainly when a count is
/// pending rather than being shown a fake success.
///
/// Stock only moves when the receipt is completed — matching the server rule — so an abandoned
/// session on a dead phone leaks nothing.
class ReceivePage extends StatefulWidget {
  const ReceivePage({super.key});

  @override
  State<ReceivePage> createState() => _ReceivePageState();
}

class _ReceivePageState extends State<ReceivePage> {
  final GlobalKey<ScanSourceState> _scanKey = GlobalKey<ScanSourceState>();

  Map<String, dynamic>? _receipt;
  final Map<String, int> _localCounts = {}; // sku/barcode → running count, for offline display
  bool _busy = false;

  Future<void> _startReceipt() async {
    setState(() => _busy = true);
    try {
      final api = context.read<ApiClient>();
      final res = await api.dio.post('/receipts', data: {'type': 'inbound'});
      if (!mounted) return;
      setState(() => _receipt = Map<String, dynamic>.from(res.data as Map));
    } catch (e) {
      if (!mounted) return;
      showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _onScan(String barcode, ScanInput input) async {
    final receipt = _receipt;
    if (receipt == null || _busy) return;

    setState(() => _busy = true);
    final queue = context.read<ScanQueue>();

    final outcome = await queue.submit(
      endpoint: '/receipts/${receipt['id']}/scan',
      payload: {'barcode': barcode, 'input_method': input.name},
    );

    if (!mounted) return;
    setState(() {
      _busy = false;
      // Optimistic local tally so the operator sees progress even with no signal.
      _localCounts[barcode] = (_localCounts[barcode] ?? 0) + 1;
    });

    if (outcome.queued) {
      // Queued is a real state, not a failure — a soft tick, and the banner shows the backlog.
      ScanFeedback.success();
      return;
    }

    final data = outcome.data ?? {};
    if ((data['result'] as String?) == 'unknown_barcode' || (data['resolved']?['kind']) == 'unknown') {
      // Unidentified goods are still received server-side; tell the operator so they can flag it.
      await ScanFeedback.showFailure(
        context,
        title: context.t('warehouse.unknownBarcode'),
        detail: context.t('warehouse.receivedUnidentified'),
      );
      return;
    }

    ScanFeedback.success();
    setState(() => _receipt = {...receipt, 'status': data['receipt_status'] ?? receipt['status']});
  }

  Future<void> _complete() async {
    final receipt = _receipt;
    if (receipt == null) return;

    final queue = context.read<ScanQueue>();
    if (queue.pending > 0) {
      // Completing moves stock. Doing that with scans still queued would apply a partial count.
      showToast(context, context.t('warehouse.syncBeforeComplete'), ToastKind.error);
      return;
    }

    setState(() => _busy = true);
    try {
      final api = context.read<ApiClient>();
      final res = await api.dio.post('/receipts/${receipt['id']}/complete');
      if (!mounted) return;

      final status = (res.data['status'] as String?) ?? '';
      showToast(
        context,
        status == 'review' ? context.t('warehouse.receiptInReview') : context.t('warehouse.receiptCompleted'),
        status == 'review' ? ToastKind.info : ToastKind.success,
      );
      setState(() {
        _receipt = null;
        _localCounts.clear();
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
    final queue = context.watch<ScanQueue>();
    final receipt = _receipt;

    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('warehouse.receive')),
        actions: [
          if (receipt != null)
            TextButton(
              onPressed: _busy ? null : _complete,
              child: Text(context.t('warehouse.complete')),
            ),
        ],
      ),
      body: SafeArea(
        child: receipt == null
            ? _StartPanel(busy: _busy, onStart: _startReceipt)
            : ScanSource(
                key: _scanKey,
                enabled: !_busy,
                onScan: _onScan,
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.all(16),
                      child: CameraScanner(
                        active: !(_scanKey.currentState?.hidDetected ?? false),
                        onDetect: (b) => _scanKey.currentState?.handle(b, ScanInput.camera),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Row(
                        children: [
                          Text(
                            '${context.t('warehouse.receipt')} ${receipt['code'] ?? ''}',
                            style: Theme.of(context).textTheme.titleSmall,
                          ),
                          const Spacer(),
                          if (!queue.online)
                            Chip(
                              avatar: const Icon(LucideIcons.wifiOff, size: 14),
                              label: Text(context.t('warehouse.offlineShort')),
                              visualDensity: VisualDensity.compact,
                            ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 8),
                    Expanded(
                      child: _localCounts.isEmpty
                          ? EmptyState(
                              icon: LucideIcons.scanLine,
                              title: context.t('warehouse.scanPrompt'),
                              subtitle: context.t('warehouse.receiveScanSub'),
                            )
                          : ListView(
                              padding: const EdgeInsets.symmetric(horizontal: 16),
                              children: _localCounts.entries
                                  .map((e) => Padding(
                                        padding: const EdgeInsets.only(bottom: 8),
                                        child: AppCard(
                                          child: Row(
                                            children: [
                                              Expanded(child: Text(e.key)),
                                              Text(
                                                '${e.value}',
                                                style: Theme.of(context)
                                                    .textTheme
                                                    .titleLarge
                                                    ?.copyWith(fontWeight: FontWeight.w800),
                                              ),
                                            ],
                                          ),
                                        ),
                                      ))
                                  .toList(),
                            ),
                    ),
                  ],
                ),
              ),
      ),
    );
  }
}

class _StartPanel extends StatelessWidget {
  const _StartPanel({required this.busy, required this.onStart});

  final bool busy;
  final VoidCallback onStart;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(LucideIcons.packagePlus, size: 64, color: Theme.of(context).hintColor),
            const SizedBox(height: 16),
            Text(
              context.t('warehouse.startReceiptTitle'),
              style: Theme.of(context).textTheme.titleLarge,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              context.t('warehouse.startReceiptSub'),
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: Theme.of(context).hintColor),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 24),
            FilledButton.icon(
              onPressed: busy ? null : onStart,
              icon: const Icon(LucideIcons.plus),
              label: Text(context.t('warehouse.startReceipt')),
            ),
          ],
        ),
      ),
    );
  }
}
