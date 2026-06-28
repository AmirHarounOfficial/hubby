import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_palette.dart';

/// Returns true if an adjustment was applied (so the caller can refresh).
Future<bool> openAdjustSheet(BuildContext context, Map<String, dynamic> item) async {
  return await showModalBottomSheet<bool>(
        context: context,
        isScrollControlled: true,
        backgroundColor: AppPalette.card,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
        ),
        builder: (sheetCtx) => Padding(
          padding: EdgeInsets.only(bottom: MediaQuery.of(sheetCtx).viewInsets.bottom),
          child: _AdjustSheet(item: item, api: context.read<ApiClient>()),
        ),
      ) ??
      false;
}

class _AdjustSheet extends StatefulWidget {
  const _AdjustSheet({required this.item, required this.api});
  final Map<String, dynamic> item;
  final ApiClient api;
  @override
  State<_AdjustSheet> createState() => _AdjustSheetState();
}

class _AdjustSheetState extends State<_AdjustSheet> {
  final _change = TextEditingController();
  final _reason = TextEditingController();
  int? _variantId;
  bool _loading = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final variants = widget.item['variants'] as List?;
    if (variants != null && variants.isNotEmpty) _variantId = variants.first['id'] as int?;
  }

  Future<void> _submit() async {
    final delta = int.tryParse(_change.text);
    if (delta == null || delta == 0) {
      setState(() => _error = 'Enter a non-zero change (e.g. 10 or -5).');
      return;
    }
    setState(() { _loading = true; _error = null; });
    try {
      await widget.api.dio.post('/inventory/adjust', data: {
        'product_id': widget.item['id'],
        if (_variantId != null) 'variant_id': _variantId,
        'change': delta,
        'reason': _reason.text.isEmpty ? 'Manual adjustment' : _reason.text,
      });
      if (mounted) Navigator.of(context).pop(true);
    } catch (e) {
      setState(() { _loading = false; _error = ApiClient.messageFrom(e); });
    }
  }

  @override
  Widget build(BuildContext context) {
    final variants = (widget.item['variants'] as List?) ?? [];
    return Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Adjust stock — ${widget.item['name'] ?? ''}',
              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
          const SizedBox(height: 16),
          if (_error != null)
            Padding(padding: const EdgeInsets.only(bottom: 10),
                child: Text(_error!, style: const TextStyle(color: AppPalette.destructive))),
          if (variants.isNotEmpty)
            DropdownButtonFormField<int>(
              initialValue: _variantId,
              decoration: const InputDecoration(labelText: 'Variant'),
              items: variants
                  .map((v) => DropdownMenuItem<int>(
                        value: v['id'] as int,
                        child: Text('${v['name'] ?? v['sku'] ?? 'Variant'} — ${v['stock'] ?? 0} in stock'),
                      ))
                  .toList(),
              onChanged: (v) => setState(() => _variantId = v),
            ),
          const SizedBox(height: 12),
          TextField(
            controller: _change,
            keyboardType: const TextInputType.numberWithOptions(signed: true),
            decoration: const InputDecoration(
              labelText: 'Change', hintText: 'e.g. 10 or -5'),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _reason,
            decoration: const InputDecoration(labelText: 'Reason', hintText: 'Restock, Damaged, Recount'),
          ),
          const SizedBox(height: 18),
          FilledButton(
            onPressed: _loading ? null : _submit,
            child: _loading
                ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
                : const Text('Apply adjustment'),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}
