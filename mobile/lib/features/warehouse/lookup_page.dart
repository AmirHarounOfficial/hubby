import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:provider/provider.dart';
import 'package:lucide_icons/lucide_icons.dart';

import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import 'data/scan_queue.dart';
import 'scanner/scan_feedback.dart';
import 'scanner/scan_source.dart';

/// Scan-to-identify (spec 08 §6.5). The simplest complete loop, and the one operators use most:
/// point at a label, find out what it is and how many we think we have.
///
/// Lookup is intentionally read-only and online-only — queueing a lookup for later would answer a
/// question the operator asked ten minutes ago, which is worse than saying "no signal".
class LookupPage extends StatefulWidget {
  const LookupPage({super.key});

  @override
  State<LookupPage> createState() => _LookupPageState();
}

class _LookupPageState extends State<LookupPage> {
  final GlobalKey<ScanSourceState> _scanKey = GlobalKey<ScanSourceState>();
  final List<Map<String, dynamic>> _history = [];
  bool _busy = false;

  Future<void> _onScan(String barcode, ScanInput input) async {
    if (_busy) return;
    setState(() => _busy = true);

    final queue = context.read<ScanQueue>();
    final outcome = await queue.submit(
      endpoint: '/scan',
      payload: {
        'barcode': barcode,
        'session_type': 'lookup',
        'input_method': input.name,
      },
    );

    if (!mounted) return;
    setState(() => _busy = false);

    if (outcome.queued) {
      await ScanFeedback.showFailure(
        context,
        title: context.t('warehouse.offlineLookupTitle'),
        detail: context.t('warehouse.offlineLookupBody'),
      );
      return;
    }

    final data = outcome.data ?? {};
    if ((data['kind'] as String?) == 'unknown') {
      await ScanFeedback.showFailure(
        context,
        title: context.t('warehouse.unknownBarcode'),
        detail: barcode,
      );
      return;
    }

    ScanFeedback.success();
    setState(() => _history.insert(0, data));
  }

  @override
  Widget build(BuildContext context) {
    final hidDetected = _scanKey.currentState?.hidDetected ?? false;

    return Scaffold(
      appBar: AppBar(title: Text(context.t('warehouse.lookup'))),
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
                  active: !hidDetected,
                  onDetect: (b) => _scanKey.currentState?.handle(b, ScanInput.camera),
                ),
              ),
              if (hidDetected)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Row(
                    children: [
                      const Icon(LucideIcons.bluetooth, size: 16, color: Colors.blue),
                      const SizedBox(width: 8),
                      Text(context.t('warehouse.scannerConnected')),
                    ],
                  ),
                ),
              const SizedBox(height: 8),
              Expanded(
                child: _history.isEmpty
                    ? EmptyState(
                        icon: LucideIcons.scanLine,
                        title: context.t('warehouse.scanPrompt'),
                        subtitle: context.t('warehouse.scanPromptSub'),
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        itemCount: _history.length,
                        itemBuilder: (_, i) => _ResultCard(data: _history[i]),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ResultCard extends StatelessWidget {
  const _ResultCard({required this.data});

  final Map<String, dynamic> data;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final kind = data['kind'] as String?;
    final product = data['product'] as Map?;
    final variant = data['variant'] as Map?;
    final location = data['location'] as Map?;
    final order = data['order'] as Map?;

    final (title, subtitle, trailing) = switch (kind) {
      'item' => (
          (product?['name'] ?? variant?['sku'] ?? '—').toString(),
          (variant?['sku'] ?? product?['sku'] ?? '').toString(),
          '${variant?['stock'] ?? product?['stock'] ?? 0}',
        ),
      'location' => (
          (location?['code'] ?? '—').toString(),
          context.t('warehouse.locationLabel'),
          '',
        ),
      'order' => (
          (order?['external_id'] ?? '—').toString(),
          context.t('warehouse.orderLabel'),
          (order?['status'] ?? '').toString(),
        ),
      _ => ('—', '', ''),
    };

    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: AppCard(
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                  if (subtitle.isNotEmpty)
                    Text(subtitle, style: theme.textTheme.bodySmall?.copyWith(color: theme.hintColor)),
                  if (data['pack_size'] != null && (data['pack_size'] as num) > 1)
                    Text(
                      '${context.t('warehouse.caseOf')} ${data['pack_size']}',
                      style: theme.textTheme.bodySmall?.copyWith(color: Colors.orange),
                    ),
                  // A bad check digit never blocks the scan, but the operator should know the
                  // label is suspect — a site printing bad labels wants to find out early.
                  if (data['check_digit_valid'] == false)
                    Text(
                      context.t('warehouse.checkDigitWarning'),
                      style: theme.textTheme.bodySmall?.copyWith(color: Colors.orange),
                    ),
                ],
              ),
            ),
            if (trailing.isNotEmpty)
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                children: [
                  Text(trailing, style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
                  if (kind == 'item')
                    Text(context.t('warehouse.inStock'), style: theme.textTheme.bodySmall?.copyWith(color: theme.hintColor)),
                ],
              ),
          ],
        ),
      ),
    );
  }
}
