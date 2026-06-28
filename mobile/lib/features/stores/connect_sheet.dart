import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../core/network/api_client.dart';
import '../../core/platforms.dart';
import '../../core/theme/app_palette.dart';
import '../../shared/widgets/app_widgets.dart';
import 'cubit/stores_cubit.dart';

void openConnectSheet(BuildContext context, String platformId) {
  showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    backgroundColor: AppPalette.card,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
    ),
    builder: (_) => Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: _ConnectSheet(platformId: platformId, parent: context),
    ),
  );
}

class _ConnectSheet extends StatefulWidget {
  const _ConnectSheet({required this.platformId, required this.parent});
  final String platformId;
  final BuildContext parent;
  @override
  State<_ConnectSheet> createState() => _ConnectSheetState();
}

class _ConnectSheetState extends State<_ConnectSheet> {
  final _name = TextEditingController();
  final _domain = TextEditingController();
  final _token = TextEditingController();
  final _secret = TextEditingController();
  bool _loading = false;
  String? _error;

  Future<void> _submit(PlatformMeta p) async {
    if (_name.text.isEmpty || _domain.text.isEmpty || _token.text.isEmpty) {
      setState(() => _error = 'Fill in name, ${p.domainLabel.toLowerCase()} and token.');
      return;
    }
    setState(() { _loading = true; _error = null; });
    try {
      await widget.parent.read<ApiClient>().dio.post('/stores/connect', data: {
        'name': _name.text.trim(),
        'platform': p.id,
        'domain': _domain.text.trim(),
        'access_token': _token.text.trim(),
        if (p.secretLabel != null) 'api_secret': _secret.text.trim(),
      });
      if (!mounted) return;
      Navigator.of(context).pop();
      widget.parent.read<StoresCubit>().refresh();
      showToast(widget.parent, 'Store connected. Initial sync started.', ToastKind.success);
    } catch (e) {
      setState(() { _loading = false; _error = ApiClient.messageFrom(e); });
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = platformFor(widget.platformId);
    return Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(children: [
            Icon(p.icon, color: p.color),
            const SizedBox(width: 10),
            Text('Connect ${p.name}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
          ]),
          const SizedBox(height: 8),
          Text(p.help, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
          const SizedBox(height: 16),
          if (_error != null)
            Padding(padding: const EdgeInsets.only(bottom: 10),
                child: Text(_error!, style: const TextStyle(color: AppPalette.destructive))),
          _field(_name, 'Store name'),
          const SizedBox(height: 10),
          _field(_domain, p.domainLabel, hint: p.domainHint),
          const SizedBox(height: 10),
          _field(_token, p.tokenLabel, obscure: true),
          if (p.secretLabel != null) ...[
            const SizedBox(height: 10),
            _field(_secret, p.secretLabel!, obscure: true),
          ],
          const SizedBox(height: 18),
          FilledButton(
            onPressed: _loading ? null : () => _submit(p),
            child: _loading
                ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
                : const Text('Connect store'),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  Widget _field(TextEditingController c, String label, {String? hint, bool obscure = false}) =>
      TextField(controller: c, obscureText: obscure,
          decoration: InputDecoration(labelText: label, hintText: hint));
}
