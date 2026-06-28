import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/format.dart';
import '../../l10n/strings.dart';
import '../../core/network/api_client.dart';
import '../../core/platforms.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_theme.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import '../../shared/widgets/money_text.dart';
import 'product_form_page.dart';

class ProductDetailPage extends StatefulWidget {
  const ProductDetailPage({super.key, required this.productId});
  final int productId;
  @override
  State<ProductDetailPage> createState() => _ProductDetailPageState();
}

class _ProductDetailPageState extends State<ProductDetailPage> {
  late Future<Map<String, dynamic>> _future = _load();
  Map<String, dynamic>? _loaded;
  bool _dirty = false; // edited/deleted — caller should refresh its list

  Future<Map<String, dynamic>> _load() async {
    final res = await context.read<ApiClient>().dio.get('/products/${widget.productId}');
    final map = (res.data as Map).cast<String, dynamic>();
    _loaded = map;
    return map;
  }

  Future<void> _toggleSync(int platformProductId) async {
    try {
      await context.read<ApiClient>().dio.post('/platform-products/$platformProductId/toggle-sync');
      if (mounted) {
        showToast(context, 'Sync setting updated.', ToastKind.success);
        setState(() => _future = _load());
      }
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    }
  }

  Future<void> _edit() async {
    if (_loaded == null) return;
    final changed = await Navigator.of(context).push<bool>(
        MaterialPageRoute(builder: (_) => ProductFormPage(product: _loaded)));
    if (changed == true && mounted) {
      _dirty = true;
      setState(() => _future = _load());
    }
  }

  Future<void> _delete() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete product'),
        content: const Text('This will permanently delete the product. Continue?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(context.t('common.cancel'))),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppPalette.destructive),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(context.t('common.delete')),
          ),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await context.read<ApiClient>().dio.delete('/products/${widget.productId}');
      if (!mounted) return;
      showToast(context, 'Product deleted.', ToastKind.success);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) Navigator.of(context).pop(_dirty);
      },
      child: Scaffold(
        appBar: AppBar(title: const Text('Product'), actions: [
          IconButton(
              icon: const Icon(LucideIcons.trash2, size: 20, color: AppPalette.destructive),
              tooltip: context.t('common.delete'),
              onPressed: _delete),
        ]),
        body: AsyncView<Map<String, dynamic>>(
          future: _future,
          onRetry: () => setState(() => _future = _load()),
          builder: (context, p) {
            final variants = (p['variants'] as List?) ?? [];
            final platformProducts = (p['platform_products'] as List?) ?? [];
            final storeCount = platformProducts.map((pp) => pp['store_id']).toSet().length;
            final totalStock = variants.isNotEmpty
                ? variants.fold<int>(0, (a, v) => a + ((v['stock'] ?? 0) as num).toInt())
                : ((p['stock'] ?? 0) as num).toInt();
            return ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              children: [
                _hero(p),
                const SizedBox(height: 16),
                _titleBlock(p, totalStock),
                const SizedBox(height: 16),
                Row(children: [
                  Expanded(child: _stat(LucideIcons.tag, context.t('common.price'),
                      MoneyText(p['price'], style: AppText.number(size: 16)), AppPalette.primary)),
                  const SizedBox(width: 10),
                  Expanded(child: _stat(LucideIcons.layers, context.t('products.inStock'),
                      Text('$totalStock', style: AppText.number(size: 16)),
                      totalStock == 0 ? AppPalette.destructive : AppPalette.secondary)),
                  const SizedBox(width: 10),
                  Expanded(child: _stat(LucideIcons.store, context.t('products.channels'),
                      Text('$storeCount', style: AppText.number(size: 16)), AppPalette.warning)),
                ]),
                const SizedBox(height: 16),
                _linkedStores(platformProducts),
                if (variants.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  _variantsCard(variants),
                ],
                if ((p['description']?.toString() ?? '').isNotEmpty) ...[
                  const SizedBox(height: 16),
                  AppCard(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(context.t('products.description'), style: const TextStyle(fontWeight: FontWeight.bold)),
                      const SizedBox(height: 8),
                      Text(p['description'].toString(),
                          style: const TextStyle(color: AppPalette.foregroundSoft, height: 1.5)),
                    ]),
                  ),
                ],
                const SizedBox(height: 20),
                SizedBox(
                  width: double.infinity,
                  child: FilledButton.icon(
                    onPressed: _edit,
                    icon: const Icon(LucideIcons.pencil, size: 16),
                    label: Text(context.t('products.editProduct')),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  Widget _hero(Map p) {
    final url = p['image_url']?.toString();
    return AspectRatio(
      aspectRatio: 16 / 10,
      child: Container(
        decoration: BoxDecoration(
          color: AppPalette.surfaceAlt,
          borderRadius: BorderRadius.circular(AppPalette.rXl),
          border: Border.all(color: AppPalette.border),
          image: (url != null && url.isNotEmpty)
              ? DecorationImage(image: NetworkImage(url), fit: BoxFit.cover)
              : null,
        ),
        child: (url == null || url.isEmpty)
            ? const Center(child: Icon(LucideIcons.image, color: AppPalette.mutedForeground, size: 40))
            : null,
      ),
    );
  }

  Widget _titleBlock(Map p, int stock) {
    final category = p['category']?['name']?.toString();
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(
          child: Text(p['name']?.toString() ?? '',
              style: const TextStyle(fontSize: 21, fontWeight: FontWeight.w700, height: 1.2)),
        ),
        const SizedBox(width: 10),
        _stockBadge(stock),
      ]),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 8, children: [
        if ((p['sku']?.toString() ?? '').isNotEmpty)
          _chip(LucideIcons.hash, p['sku'].toString(), AppPalette.primary),
        if (category != null) _chip(LucideIcons.tag, category, AppPalette.mutedForeground),
      ]),
    ]);
  }

  Widget _chip(IconData icon, String label, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: AppPalette.surfaceAlt,
          borderRadius: BorderRadius.circular(AppPalette.rPill),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 12, color: color),
          const SizedBox(width: 5),
          Text(label, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
        ]),
      );

  Widget _stockBadge(int stock) {
    final (label, color) = stock == 0
        ? (context.t('products.outOfStock'), AppPalette.destructive)
        : stock < 10
            ? (context.t('products.lowStock'), AppPalette.warning)
            : (context.t('products.healthy'), AppPalette.secondary);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(AppPalette.rPill)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Container(width: 6, height: 6, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
        const SizedBox(width: 6),
        Text(label, style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700)),
      ]),
    );
  }

  Widget _stat(IconData icon, String label, Widget value, Color color) => AppCard(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            height: 30, width: 30,
            decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(AppPalette.rSm)),
            child: Icon(icon, size: 16, color: color),
          ),
          const SizedBox(height: 10),
          value,
          Text(label, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
        ]),
      );

  Widget _variantsCard(List variants) {
    return AppCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Text(context.t('products.variants'), style: const TextStyle(fontWeight: FontWeight.bold)),
          const Spacer(),
          Text('${variants.length}', style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
        ]),
        const SizedBox(height: 4),
        ...variants.map((v) {
          final s = ((v['stock'] ?? 0) as num).toInt();
          final color = s == 0 ? AppPalette.destructive : s < 10 ? AppPalette.warning : AppPalette.secondary;
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 7),
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(v['name']?.toString() ?? v['sku']?.toString() ?? 'Variant',
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  if ((v['sku']?.toString() ?? '').isNotEmpty)
                    Text(v['sku'].toString(),
                        style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                ]),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(color: color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(AppPalette.rPill)),
                child: Text('$s in stock', style: TextStyle(color: color, fontSize: 11, fontWeight: FontWeight.w700)),
              ),
            ]),
          );
        }),
      ]),
    );
  }

  Widget _linkedStores(List platformProducts) {
    if (platformProducts.isEmpty) {
      return AppCard(
        child: Row(children: [
          Container(
            height: 36, width: 36,
            decoration: BoxDecoration(color: AppPalette.surfaceAlt, borderRadius: BorderRadius.circular(AppPalette.rSm)),
            child: const Icon(LucideIcons.store, size: 18, color: AppPalette.mutedForeground),
          ),
          const SizedBox(width: 12),
          const Expanded(child: Text('Not linked to any store yet.\nEdit the product to push it to your channels.',
              style: TextStyle(color: AppPalette.mutedForeground, fontSize: 13, height: 1.4))),
        ]),
      );
    }
    return AppCard(
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(context.t('products.channels'), style: const TextStyle(fontWeight: FontWeight.bold)),
        const SizedBox(height: 2),
        const Text('Toggle per-channel sync. Disabled channels keep their own stock.',
            style: TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
        const SizedBox(height: 6),
        ...platformProducts.map((pp) {
          final store = pp['store'];
          final meta = platformFor(store?['platform']);
          final enabled = pp['sync_enabled'] == true;
          final id = pp['id'];
          final sub = [
            if ((pp['external_id']?.toString() ?? '').isNotEmpty) 'ID ${pp['external_id']}',
            if (pp['price'] != null) formatMoney(pp['price']),
          ].join('  ·  ');
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Row(children: [
              Container(
                height: 36, width: 36,
                decoration: BoxDecoration(color: meta.color.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(AppPalette.rSm)),
                child: Icon(meta.icon, color: meta.color, size: 18),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(store?['name']?.toString() ?? meta.name,
                      maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  Text(sub.isEmpty ? meta.name : sub,
                      maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                ]),
              ),
              Switch(
                value: enabled,
                activeThumbColor: AppPalette.primary,
                onChanged: id is int ? (_) => _toggleSync(id) : null,
              ),
            ]),
          );
        }),
      ]),
    );
  }
}
