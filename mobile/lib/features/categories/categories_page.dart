import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';
import 'category_form_page.dart';

class CategoriesPage extends StatefulWidget {
  const CategoriesPage({super.key});
  @override
  State<CategoriesPage> createState() => _CategoriesPageState();
}

class _CategoriesPageState extends State<CategoriesPage> {
  late Future<List<dynamic>> _future = _load();
  List<dynamic> _all = [];

  Future<List<dynamic>> _load() async {
    final res = await context.read<ApiClient>().dio.get('/categories');
    _all = (res.data as List?) ?? [];
    return _all;
  }

  void _reload() => setState(() => _future = _load());

  /// Order the flat list into a parent→child tree with depth for indentation.
  List<(dynamic, int)> _tree(List<dynamic> cats) {
    final byParent = <int?, List<dynamic>>{};
    for (final c in cats) {
      byParent.putIfAbsent(c['parent_id'] as int?, () => []).add(c);
    }
    final out = <(dynamic, int)>[];
    void walk(int? parent, int depth) {
      for (final c in byParent[parent] ?? const []) {
        out.add((c, depth));
        walk(c['id'] as int?, depth + 1);
      }
    }
    walk(null, 0);
    return out;
  }

  Future<void> _openForm([Map<String, dynamic>? category]) async {
    final changed = await Navigator.of(context).push<bool>(
        MaterialPageRoute(builder: (_) => CategoryFormPage(category: category, categories: _all)));
    if (changed == true) _reload();
  }

  Future<void> _delete(dynamic category) async {
    final hasChildren = _all.any((c) => c['parent_id'] == category['id']);
    var strategy = 'move_to_root';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('Delete category'),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Delete “${category['name']}”?'),
            if (hasChildren) ...[
              const SizedBox(height: 12),
              const Text('This category has subcategories. What should happen to them?',
                  style: TextStyle(fontSize: 13, color: AppPalette.mutedForeground)),
              const SizedBox(height: 8),
              Wrap(spacing: 8, children: [
                for (final opt in const [
                  ['move_to_root', 'Move to top level'],
                  ['cascade', 'Delete them too'],
                ])
                  ChoiceChip(
                    label: Text(opt[1]),
                    selected: strategy == opt[0],
                    onSelected: (_) => setLocal(() => strategy = opt[0]),
                    selectedColor: AppPalette.primary,
                    labelStyle: TextStyle(
                        color: strategy == opt[0] ? Colors.white : AppPalette.foreground, fontSize: 12),
                    backgroundColor: AppPalette.card,
                    side: const BorderSide(color: AppPalette.border),
                  ),
              ]),
            ],
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(context.t('common.cancel'))),
            FilledButton(
              style: FilledButton.styleFrom(backgroundColor: AppPalette.destructive),
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(context.t('common.delete')),
            ),
          ],
        ),
      ),
    );
    if (ok != true) return;
    try {
      await context.read<ApiClient>().dio.delete('/categories/${category['id']}',
          queryParameters: {'strategy': strategy});
      if (!mounted) return;
      showToast(context, 'Category deleted.', ToastKind.success);
      _reload();
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('nav.categories')), actions: [
        IconButton(icon: const Icon(LucideIcons.plus), tooltip: 'New category', onPressed: () => _openForm()),
      ]),
      body: RefreshIndicator(
        onRefresh: () async => _reload(),
        child: AsyncView<List<dynamic>>(
          future: _future,
          onRetry: _reload,
          builder: (context, cats) {
            if (cats.isEmpty) {
              return ListView(children: [
                Padding(
                  padding: const EdgeInsets.all(24),
                  child: AppCard(
                    padding: const EdgeInsets.all(28),
                    child: Column(children: [
                      const Icon(LucideIcons.folderTree, size: 32, color: AppPalette.primary),
                      const SizedBox(height: 12),
                      const Text('No categories yet', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                      const SizedBox(height: 6),
                      const Text('Group your products by creating your first category.',
                          textAlign: TextAlign.center,
                          style: TextStyle(color: AppPalette.mutedForeground, fontSize: 13)),
                      const SizedBox(height: 16),
                      FilledButton.icon(
                        onPressed: () => _openForm(),
                        icon: const Icon(LucideIcons.plus, size: 16),
                        label: const Text('New category'),
                      ),
                    ]),
                  ),
                ),
              ]);
            }
            final tree = _tree(cats);
            return ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: tree.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final (c, depth) = tree[i];
                return Padding(
                  padding: EdgeInsetsDirectional.only(start: depth * 18.0),
                  child: AppCard(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                    child: Row(children: [
                      Icon(depth == 0 ? LucideIcons.folder : LucideIcons.cornerDownRight,
                          size: 18, color: AppPalette.primary),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(c['name']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.w600)),
                          if ((c['description']?.toString() ?? '').isNotEmpty)
                            Text(c['description'].toString(),
                                maxLines: 1, overflow: TextOverflow.ellipsis,
                                style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
                        ]),
                      ),
                      IconButton(
                        icon: const Icon(LucideIcons.pencil, size: 18),
                        onPressed: () => _openForm((c as Map).cast<String, dynamic>()),
                      ),
                      IconButton(
                        icon: const Icon(LucideIcons.trash2, size: 18, color: AppPalette.destructive),
                        onPressed: () => _delete(c),
                      ),
                    ]),
                  ),
                );
              },
            );
          },
        ),
      ),
    );
  }
}
