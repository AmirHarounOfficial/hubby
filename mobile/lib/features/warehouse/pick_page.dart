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

/// Picking (spec 08 §4.1/§6.5).
///
/// The screen exists to stop mispicks, so it is built around refusal rather than confirmation: a
/// barcode that is not on this list produces a full-screen block, not a warning. Picking moves no
/// stock — that happens at ship — so a pick is safe to replay.
class PickPage extends StatefulWidget {
  const PickPage({super.key});

  @override
  State<PickPage> createState() => _PickPageState();
}

class _PickPageState extends State<PickPage> {
  final GlobalKey<ScanSourceState> _scanKey = GlobalKey<ScanSourceState>();

  List<dynamic> _lists = [];
  Map<String, dynamic>? _active;
  bool _busy = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _loadLists();
  }

  Future<void> _loadLists() async {
    setState(() => _loading = true);
    try {
      final api = context.read<ApiClient>();
      final res = await api.dio.get('/pick-lists', queryParameters: {'status': 'ready'});
      if (!mounted) return;
      setState(() => _lists = (res.data['data'] as List?) ?? []);
    } catch (e) {
      if (!mounted) return;
      showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _start(int id) async {
    setState(() => _busy = true);
    try {
      final api = context.read<ApiClient>();
      await api.dio.post('/pick-lists/$id/start');
      final res = await api.dio.get('/pick-lists/$id');
      if (!mounted) return;
      setState(() => _active = Map<String, dynamic>.from(res.data as Map));
    } catch (e) {
      if (!mounted) return;
      showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _refreshActive() async {
    final active = _active;
    if (active == null) return;
    final api = context.read<ApiClient>();
    final res = await api.dio.get('/pick-lists/${active['id']}');
    if (!mounted) return;
    setState(() => _active = Map<String, dynamic>.from(res.data as Map));
  }

  Future<void> _onScan(String barcode, ScanInput input) async {
    final active = _active;
    if (active == null || _busy) return;

    setState(() => _busy = true);
    final outcome = await context.read<ScanQueue>().submit(
          endpoint: '/pick-lists/${active['id']}/pick',
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
        // Picking N of the same SKU means scanning the same label N times.
        _scanKey.currentState?.resetDuplicateGuard();
        await _refreshActive();
      case 'wrong_item':
        await ScanFeedback.showFailure(
          context,
          title: context.t('warehouse.wrongItem'),
          detail: context.t('warehouse.wrongItemBody'),
        );
      case 'over_pick':
        await ScanFeedback.showFailure(
          context,
          title: context.t('warehouse.overPick'),
          detail: context.t('warehouse.overPickBody'),
        );
      case 'unknown_barcode':
        await ScanFeedback.showFailure(context, title: context.t('warehouse.unknownBarcode'), detail: barcode);
      default:
        await ScanFeedback.showFailure(context, title: context.t('warehouse.scanRejected'), detail: outcome.result);
    }
  }

  Future<void> _short(Map<String, dynamic> line) async {
    final reason = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Text(context.t('warehouse.shortTitle'), style: Theme.of(ctx).textTheme.titleMedium),
            ),
            for (final r in const ['not_found', 'damaged', 'insufficient', 'wrong_location', 'other'])
              ListTile(
                title: Text(context.t('warehouse.short_$r')),
                onTap: () => Navigator.of(ctx).pop(r),
              ),
          ],
        ),
      ),
    );
    if (reason == null || !mounted) return;

    try {
      final api = context.read<ApiClient>();
      await api.dio.post('/pick-lists/${_active!['id']}/items/${line['id']}/short', data: {'reason': reason});
      await _refreshActive();
    } catch (e) {
      if (!mounted) return;
      showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    }
  }

  Future<void> _complete() async {
    final queue = context.read<ScanQueue>();
    if (queue.pending > 0) {
      showToast(context, context.t('warehouse.syncBeforeComplete'), ToastKind.error);
      return;
    }

    try {
      final api = context.read<ApiClient>();
      final res = await api.dio.post('/pick-lists/${_active!['id']}/complete');
      if (!mounted) return;
      final status = (res.data['status'] as String?) ?? '';
      showToast(
        context,
        status == 'review' ? context.t('warehouse.pickInReview') : context.t('warehouse.pickCompleted'),
        status == 'review' ? ToastKind.info : ToastKind.success,
      );
      setState(() => _active = null);
      await _loadLists();
    } catch (e) {
      if (!mounted) return;
      showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    }
  }

  @override
  Widget build(BuildContext context) {
    final active = _active;

    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('warehouse.pick')),
        actions: [
          if (active != null)
            TextButton(onPressed: _busy ? null : _complete, child: Text(context.t('warehouse.complete'))),
        ],
      ),
      body: SafeArea(
        child: active == null ? _listPicker() : _picking(active),
      ),
    );
  }

  Widget _listPicker() {
    if (_loading) return const LoadingView();
    if (_lists.isEmpty) {
      return EmptyState(
        icon: LucideIcons.clipboardList,
        title: context.t('warehouse.noPickLists'),
        subtitle: context.t('warehouse.noPickListsSub'),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _lists.length,
      itemBuilder: (_, i) {
        final l = _lists[i] as Map;
        return Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: InkWell(
            onTap: _busy ? null : () => _start(l['id'] as int),
            borderRadius: BorderRadius.circular(16),
            child: AppCard(
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${l['code']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                        Text('${l['items_count'] ?? 0} ${context.t('warehouse.lines')}'),
                      ],
                    ),
                  ),
                  const Icon(LucideIcons.chevronRight, size: 18),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _picking(Map<String, dynamic> active) {
    final items = (active['items'] as List?) ?? [];

    return ScanSource(
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
          Expanded(
            child: ListView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              itemCount: items.length,
              itemBuilder: (_, i) {
                final line = Map<String, dynamic>.from(items[i] as Map);
                final required = (line['qty_required'] as num?)?.toInt() ?? 0;
                final picked = (line['qty_picked'] as num?)?.toInt() ?? 0;
                final done = line['status'] == 'picked';
                final isShort = line['status'] == 'short';

                return Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: AppCard(
                    child: Row(
                      children: [
                        Icon(
                          done ? LucideIcons.checkCircle2 : isShort ? LucideIcons.alertTriangle : LucideIcons.circle,
                          color: done ? Colors.green : isShort ? Colors.orange : Colors.grey,
                          size: 20,
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('${line['name'] ?? line['sku'] ?? '—'}',
                                  style: const TextStyle(fontWeight: FontWeight.w600)),
                              Text('${line['sku'] ?? ''}',
                                  style: TextStyle(color: Theme.of(context).hintColor, fontSize: 12)),
                            ],
                          ),
                        ),
                        Text('$picked / $required',
                            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                        if (!done && !isShort)
                          IconButton(
                            icon: const Icon(LucideIcons.xCircle, size: 18),
                            tooltip: context.t('warehouse.cantPick'),
                            onPressed: () => _short(line),
                          ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}
