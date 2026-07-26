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

/// Cycle counting (spec 08 §4.4/§6.5).
///
/// Two things shape this screen:
///
///  * **Counts are absolute, never deltas.** The app keeps a running total per item and sends that
///    total with a monotonic client_seq. The server keeps the highest seq it has seen, so a replay
///    or an out-of-order delivery can never inflate a count. Scanning is just a convenient way to
///    increment the local total.
///  * **Blind by default.** The server strips expected_qty for non-supervisors, so this screen
///    simply has nothing to show — the operator counts the shelf, not the system.
class CountPage extends StatefulWidget {
  const CountPage({super.key});

  @override
  State<CountPage> createState() => _CountPageState();
}

class _CountPageState extends State<CountPage> {
  final GlobalKey<ScanSourceState> _scanKey = GlobalKey<ScanSourceState>();

  Map<String, dynamic>? _session;
  /// barcode → running absolute total held on this device.
  final Map<String, int> _totals = {};
  final Map<String, String> _labels = {};
  bool _busy = false;

  Future<void> _startSession() async {
    setState(() => _busy = true);
    try {
      final api = context.read<ApiClient>();
      final res = await api.dio.post('/count-sessions', data: {'mode': 'blind', 'scope_type': 'full'});
      if (!mounted) return;
      setState(() => _session = Map<String, dynamic>.from(res.data as Map));
    } catch (e) {
      if (!mounted) return;
      showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Push the current absolute total for an item.
  Future<void> _send(String barcode, int total, ScanInput input) async {
    final outcome = await context.read<ScanQueue>().submit(
          endpoint: '/count-sessions/${_session!['id']}/count',
          payload: {'barcode': barcode, 'counted_qty': total, 'input_method': input.name},
          // The server resolves conflicts by highest client_seq — this is what makes an
          // out-of-order replay harmless.
          needsClientSeq: true,
        );

    if (!mounted) return;

    if (outcome.queued || outcome.result == 'accepted' || outcome.result == 'duplicate') {
      ScanFeedback.success();
      final entry = outcome.data?['entry'] as Map?;
      if (entry?['name'] != null) setState(() => _labels[barcode] = '${entry!['name']}');
      return;
    }

    await ScanFeedback.showFailure(
      context,
      title: outcome.result == 'unknown_barcode'
          ? context.t('warehouse.unknownBarcode')
          : context.t('warehouse.scanRejected'),
      detail: barcode,
    );
    // The scan did not land, so roll the local total back to stay truthful.
    setState(() => _totals[barcode] = (_totals[barcode] ?? 1) - 1);
  }

  Future<void> _onScan(String barcode, ScanInput input) async {
    if (_session == null || _busy) return;

    setState(() {
      _busy = true;
      _totals[barcode] = (_totals[barcode] ?? 0) + 1;
    });

    await _send(barcode, _totals[barcode]!, input);

    if (!mounted) return;
    setState(() => _busy = false);
    // Counting five identical units means scanning the same label five times.
    _scanKey.currentState?.resetDuplicateGuard();
  }

  /// "Type total" — the explicit alternative to scanning each unit, for a shelf of 200.
  Future<void> _typeTotal(String barcode) async {
    final controller = TextEditingController(text: '${_totals[barcode] ?? 0}');
    final value = await showDialog<int>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(context.t('warehouse.typeTotal')),
        content: TextField(
          controller: controller,
          keyboardType: TextInputType.number,
          autofocus: true,
          decoration: InputDecoration(labelText: context.t('warehouse.countedQty')),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(), child: Text(context.t('common.cancel'))),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(int.tryParse(controller.text.trim())),
            child: Text(context.t('warehouse.save')),
          ),
        ],
      ),
    );

    if (value == null || !mounted) return;
    setState(() => _totals[barcode] = value);
    await _send(barcode, value, ScanInput.manual);
  }

  Future<void> _submit() async {
    final queue = context.read<ScanQueue>();
    if (queue.pending > 0) {
      showToast(context, context.t('warehouse.syncBeforeComplete'), ToastKind.error);
      return;
    }

    setState(() => _busy = true);
    try {
      final api = context.read<ApiClient>();
      await api.dio.post('/count-sessions/${_session!['id']}/submit');
      if (!mounted) return;
      // Submission is not application — a supervisor still has to approve before stock moves.
      showToast(context, context.t('warehouse.countSubmitted'), ToastKind.success);
      setState(() {
        _session = null;
        _totals.clear();
        _labels.clear();
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

    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('warehouse.count')),
        actions: [
          if (session != null)
            TextButton(onPressed: _busy ? null : _submit, child: Text(context.t('warehouse.submit'))),
        ],
      ),
      body: SafeArea(
        child: session == null
            ? Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(LucideIcons.clipboardCheck, size: 64, color: Theme.of(context).hintColor),
                      const SizedBox(height: 16),
                      Text(context.t('warehouse.startCountTitle'),
                          style: Theme.of(context).textTheme.titleLarge, textAlign: TextAlign.center),
                      const SizedBox(height: 8),
                      Text(context.t('warehouse.startCountSub'),
                          style: TextStyle(color: Theme.of(context).hintColor), textAlign: TextAlign.center),
                      const SizedBox(height: 24),
                      FilledButton.icon(
                        onPressed: _busy ? null : _startSession,
                        icon: const Icon(LucideIcons.plus),
                        label: Text(context.t('warehouse.startCount')),
                      ),
                    ],
                  ),
                ),
              )
            : ScanSource(
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
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: Row(children: [
                        Text('${session['code']}', style: Theme.of(context).textTheme.titleSmall),
                        const Spacer(),
                        Text(context.t('warehouse.blindCount'),
                            style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                      ]),
                    ),
                    const SizedBox(height: 8),
                    Expanded(
                      child: _totals.isEmpty
                          ? EmptyState(
                              icon: LucideIcons.scanLine,
                              title: context.t('warehouse.scanPrompt'),
                              subtitle: context.t('warehouse.countScanSub'),
                            )
                          : ListView(
                              padding: const EdgeInsets.symmetric(horizontal: 16),
                              children: _totals.entries.map((e) {
                                return Padding(
                                  padding: const EdgeInsets.only(bottom: 8),
                                  child: AppCard(
                                    child: Row(
                                      children: [
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              Text(_labels[e.key] ?? e.key,
                                                  style: const TextStyle(fontWeight: FontWeight.w600)),
                                              if (_labels.containsKey(e.key))
                                                Text(e.key,
                                                    style: TextStyle(
                                                        color: Theme.of(context).hintColor, fontSize: 12)),
                                            ],
                                          ),
                                        ),
                                        Text('${e.value}',
                                            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 20)),
                                        IconButton(
                                          icon: const Icon(LucideIcons.pencil, size: 18),
                                          tooltip: context.t('warehouse.typeTotal'),
                                          onPressed: () => _typeTotal(e.key),
                                        ),
                                      ],
                                    ),
                                  ),
                                );
                              }).toList(),
                            ),
                    ),
                  ],
                ),
              ),
      ),
    );
  }
}
