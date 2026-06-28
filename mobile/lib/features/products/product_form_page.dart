import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:image_picker/image_picker.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/network/api_client.dart';
import '../../l10n/strings.dart';
import '../../core/theme/app_palette.dart';
import '../../shared/widgets/app_widgets.dart';
import '../stores/cubit/stores_cubit.dart';

/// Create / edit a product — mirrors the web `products/new` and
/// `products/[id]/edit` forms (name, sku, price, stock, description, image,
/// category, linked stores, and variants).
class ProductFormPage extends StatefulWidget {
  const ProductFormPage({super.key, this.product});

  /// When non-null the form edits this product (must contain id, fields, variants).
  final Map<String, dynamic>? product;

  bool get isEdit => product != null;

  @override
  State<ProductFormPage> createState() => _ProductFormPageState();
}

class _VariantRow {
  _VariantRow({this.id, String name = '', String sku = '', String price = '', String stock = '0'})
      : name = TextEditingController(text: name),
        sku = TextEditingController(text: sku),
        price = TextEditingController(text: price),
        stock = TextEditingController(text: stock);
  final int? id;
  final TextEditingController name, sku, price, stock;
  void dispose() {
    name.dispose();
    sku.dispose();
    price.dispose();
    stock.dispose();
  }
}

class _ProductFormPageState extends State<ProductFormPage> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _sku;
  late final TextEditingController _price;
  late final TextEditingController _stock;
  late final TextEditingController _description;
  late final TextEditingController _imageUrl;
  int? _categoryId;
  final Set<int> _storeIds = {};
  final List<_VariantRow> _variants = [];

  List<dynamic> _categories = [];
  bool _loadingMeta = true;
  bool _saving = false;
  bool _uploading = false;

  @override
  void initState() {
    super.initState();
    final p = widget.product;
    _name = TextEditingController(text: p?['name']?.toString() ?? '');
    _sku = TextEditingController(text: p?['sku']?.toString() ?? '');
    _price = TextEditingController(text: p?['price']?.toString() ?? '');
    _stock = TextEditingController(text: (p?['stock'] ?? 0).toString());
    _description = TextEditingController(text: p?['description']?.toString() ?? '');
    _imageUrl = TextEditingController(text: p?['image_url']?.toString() ?? '');
    _categoryId = p?['category_id'] as int?;
    for (final v in (p?['variants'] as List?) ?? const []) {
      _variants.add(_VariantRow(
        id: v['id'] as int?,
        name: v['name']?.toString() ?? '',
        sku: v['sku']?.toString() ?? '',
        price: v['price']?.toString() ?? '',
        stock: (v['stock'] ?? 0).toString(),
      ));
    }
    for (final pp in (p?['platform_products'] as List?) ?? const []) {
      final id = pp['store_id'];
      if (id is int) _storeIds.add(id);
    }
    _loadMeta();
  }

  Future<void> _loadMeta() async {
    try {
      final res = await context.read<ApiClient>().dio.get('/categories');
      if (mounted) setState(() => _categories = (res.data as List?) ?? []);
    } catch (_) {
      // categories are optional — form still works without them
    } finally {
      if (mounted) setState(() => _loadingMeta = false);
    }
  }

  @override
  void dispose() {
    for (final c in [_name, _sku, _price, _stock, _description, _imageUrl]) {
      c.dispose();
    }
    for (final v in _variants) {
      v.dispose();
    }
    super.dispose();
  }

  Future<void> _pickImage() async {
    final XFile? picked = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      maxWidth: 1280,
      imageQuality: 80, // keeps the file comfortably small so it always uploads
    );
    if (picked == null) return;
    setState(() => _uploading = true);
    try {
      // readAsBytes works on every platform (incl. web), unlike fromFile(path).
      final bytes = await picked.readAsBytes();

      // Derive a clean MIME subtype + a filename WITH an extension so Laravel's
      // `image|mimes:...` validation reliably accepts the file. Without an
      // explicit content type the part is sent as octet-stream and rejected.
      final ext = picked.name.contains('.') ? picked.name.split('.').last.toLowerCase() : '';
      var subtype = (picked.mimeType != null && picked.mimeType!.contains('/'))
          ? picked.mimeType!.split('/').last.toLowerCase()
          : (ext.isNotEmpty ? ext : 'jpeg');
      if (subtype == 'jpg') subtype = 'jpeg';
      const allowed = {'jpeg', 'png', 'gif', 'webp'};
      if (!allowed.contains(subtype)) subtype = 'jpeg';
      final fileExt = subtype == 'jpeg' ? 'jpg' : subtype;
      final filename = picked.name.contains('.') ? picked.name : 'product_image.$fileExt';

      final form = FormData.fromMap({
        'image': MultipartFile.fromBytes(bytes, filename: filename, contentType: DioMediaType('image', subtype)),
      });
      final res = await context.read<ApiClient>().dio.post('/products/upload', data: form);
      final url = res.data?['url']?.toString();
      if (url != null && mounted) {
        setState(() => _imageUrl.text = url);
        showToast(context, 'Image uploaded.', ToastKind.success);
      }
    } catch (e) {
      // Surface the HTTP status so server-side failures are diagnosable.
      var msg = ApiClient.messageFrom(e);
      if (e is DioException && e.response != null) {
        msg = 'Upload failed (${e.response?.statusCode}): $msg';
      }
      if (mounted) showToast(context, msg, ToastKind.error);
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) {
      showToast(context, 'Please fix the highlighted fields.', ToastKind.error);
      return;
    }
    setState(() => _saving = true);
    final payload = {
      'name': _name.text.trim(),
      'sku': _sku.text.trim(),
      'price': double.tryParse(_price.text.trim()) ?? 0,
      'stock': int.tryParse(_stock.text.trim()) ?? 0,
      'description': _description.text.trim(),
      'image_url': _imageUrl.text.trim().isEmpty ? null : _imageUrl.text.trim(),
      'category_id': _categoryId,
      'store_ids': _storeIds.toList(),
      'variants': _variants
          .map((v) => {
                if (v.id != null) 'id': v.id,
                'name': v.name.text.trim(),
                'sku': v.sku.text.trim(),
                'price': double.tryParse(v.price.text.trim()) ?? 0,
                'stock': int.tryParse(v.stock.text.trim()) ?? 0,
              })
          .toList(),
    };
    try {
      final api = context.read<ApiClient>();
      if (widget.isEdit) {
        await api.dio.put('/products/${widget.product!['id']}', data: payload);
      } else {
        await api.dio.post('/products', data: payload);
      }
      if (!mounted) return;
      showToast(context, widget.isEdit ? 'Product updated.' : 'Product created.', ToastKind.success);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final stores = context.watch<StoresCubit>().state.stores;
    return Scaffold(
      appBar: AppBar(title: Text(widget.isEdit ? context.t('products.editProduct') : context.t('products.newProduct'))),
      body: _loadingMeta
          ? const LoadingView()
          : Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                children: [
                  _section(context.t('products.basics'), context.t('products.basicsSub'), [
                    _field(_name, context.t('products.name'), required: true, icon: LucideIcons.tag),
                    _field(_sku, 'SKU', required: true, icon: LucideIcons.hash),
                    Row(children: [
                      Expanded(
                          child: _field(_price, context.t('common.price'), keyboard: TextInputType.number, required: true, prefixText: 'SAR ')),
                      const SizedBox(width: 12),
                      Expanded(child: _field(_stock, context.t('common.stock'), keyboard: TextInputType.number, icon: LucideIcons.layers)),
                    ]),
                    _field(_description, context.t('products.description'), maxLines: 3, last: true),
                  ]),
                  const SizedBox(height: 14),
                  _imageSection(),
                  const SizedBox(height: 14),
                  _section(context.t('products.organization'), context.t('products.organizationSub'), [
                    _categoryDropdown(),
                  ]),
                  const SizedBox(height: 14),
                  _storesSection(stores),
                  const SizedBox(height: 14),
                  _variantsSection(),
                ],
              ),
            ),
      bottomNavigationBar: _loadingMeta
          ? null
          : Container(
              decoration: const BoxDecoration(
                color: AppPalette.card,
                border: Border(top: BorderSide(color: AppPalette.border)),
                boxShadow: [BoxShadow(color: Color(0x141E293B), blurRadius: 20, offset: Offset(0, -8))],
              ),
              child: SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
                  child: SizedBox(
                    height: 50,
                    width: double.infinity,
                    child: FilledButton.icon(
                      onPressed: _saving ? null : _save,
                      icon: _saving
                          ? const SizedBox(height: 18, width: 18, child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
                          : const Icon(LucideIcons.check, size: 18),
                      label: Text(_saving
                          ? 'Saving…'
                          : widget.isEdit
                              ? context.t('products.saveChanges')
                              : context.t('products.create')),
                    ),
                  ),
                ),
              ),
            ),
    );
  }

  Widget _section(String title, String? subtitle, List<Widget> children) => AppCard(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
          if (subtitle != null) ...[
            const SizedBox(height: 2),
            Text(subtitle, style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 12)),
          ],
          const SizedBox(height: 14),
          ...children,
        ]),
      );

  Widget _field(TextEditingController c, String label,
      {bool required = false, int maxLines = 1, TextInputType? keyboard, IconData? icon, String? prefixText, bool last = false}) {
    return Padding(
      padding: EdgeInsets.only(bottom: last ? 0 : 12),
      child: TextFormField(
        controller: c,
        maxLines: maxLines,
        keyboardType: keyboard,
        decoration: InputDecoration(
          labelText: required ? '$label *' : label,
          prefixIcon: icon != null ? Icon(icon, size: 18) : null,
          prefixText: prefixText,
        ),
        validator: required ? (v) => (v == null || v.trim().isEmpty) ? '$label is required' : null : null,
      ),
    );
  }

  Widget _imageSection() {
    final url = _imageUrl.text.trim();
    return _section(context.t('products.media'), null, [
      Row(children: [
        Container(
          height: 80, width: 80,
          decoration: BoxDecoration(
            color: AppPalette.surfaceAlt,
            borderRadius: BorderRadius.circular(AppPalette.rMd),
            border: Border.all(color: AppPalette.border),
            image: url.isNotEmpty ? DecorationImage(image: NetworkImage(url), fit: BoxFit.cover) : null,
          ),
          child: url.isEmpty
              ? const Icon(LucideIcons.image, color: AppPalette.mutedForeground)
              : null,
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            OutlinedButton.icon(
              onPressed: _uploading ? null : _pickImage,
              icon: _uploading
                  ? const SizedBox(height: 14, width: 14, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(LucideIcons.upload, size: 16),
              label: Text(_uploading ? 'Uploading…' : context.t('products.uploadImage')),
            ),
            const SizedBox(height: 4),
            Text(url.isNotEmpty ? 'JPG/PNG/WEBP · 1000×1000 recommended' : 'Max 8MB · JPG, PNG, WEBP',
                style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
            if (url.isNotEmpty)
              TextButton.icon(
                onPressed: () => setState(() => _imageUrl.clear()),
                style: TextButton.styleFrom(padding: EdgeInsets.zero, foregroundColor: AppPalette.destructive),
                icon: const Icon(LucideIcons.x, size: 14),
                label: Text(context.t('common.remove')),
              ),
          ]),
        ),
      ]),
      const SizedBox(height: 12),
      TextFormField(
        controller: _imageUrl,
        onChanged: (_) => setState(() {}),
        decoration: InputDecoration(labelText: context.t('products.orPasteUrl'), isDense: true),
      ),
    ]);
  }

  Widget _categoryDropdown() {
    return DropdownButtonFormField<int?>(
      initialValue: _categoryId,
      isExpanded: true,
      decoration: InputDecoration(labelText: context.t('products.category'), prefixIcon: const Icon(LucideIcons.folderTree, size: 18)),
      items: [
        DropdownMenuItem<int?>(value: null, child: Text(context.t('products.noCategory'))),
        ..._categories.map((c) => DropdownMenuItem<int?>(
              value: c['id'] as int?,
              child: Text(c['name']?.toString() ?? ''),
            )),
      ],
      onChanged: (v) => setState(() => _categoryId = v),
    );
  }

  Widget _storesSection(List stores) {
    return _section(context.t('products.channels'), context.t('products.channelsSub'), [
      if (stores.isEmpty)
        Row(children: const [
          Icon(LucideIcons.plugZap, size: 16, color: AppPalette.mutedForeground),
          SizedBox(width: 8),
          Expanded(child: Text('No connected stores yet — connect one to start syncing.',
              style: TextStyle(color: AppPalette.mutedForeground, fontSize: 13))),
        ])
      else
        Wrap(
          spacing: 8, runSpacing: 8,
          children: stores.map<Widget>((s) {
            final id = s['id'] as int;
            final selected = _storeIds.contains(id);
            return FilterChip(
              label: Text(s['name']?.toString() ?? 'Store'),
              selected: selected,
              onSelected: (v) => setState(() => v ? _storeIds.add(id) : _storeIds.remove(id)),
              selectedColor: AppPalette.primary,
              checkmarkColor: Colors.white,
              labelStyle: TextStyle(
                  color: selected ? Colors.white : AppPalette.foreground, fontSize: 12, fontWeight: FontWeight.w500),
              backgroundColor: AppPalette.surfaceAlt,
              side: BorderSide(color: selected ? AppPalette.primary : AppPalette.borderStrong),
            );
          }).toList(),
        ),
    ]);
  }

  Widget _variantsSection() {
    return _section(context.t('products.variants'), null, [
      if (_variants.isEmpty)
        Row(children: const [
          Icon(LucideIcons.shapes, size: 16, color: AppPalette.mutedForeground),
          SizedBox(width: 8),
          Expanded(child: Text('Add variants for sizes, colors, or materials. The base price/stock applies otherwise.',
              style: TextStyle(color: AppPalette.mutedForeground, fontSize: 12))),
        ]),
      for (int i = 0; i < _variants.length; i++) _variantRow(i),
      const SizedBox(height: 6),
      Align(
        alignment: AlignmentDirectional.centerStart,
        child: OutlinedButton.icon(
          onPressed: () => setState(() => _variants.add(_VariantRow())),
          icon: const Icon(LucideIcons.plus, size: 16),
          label: Text(context.t('products.addVariant')),
        ),
      ),
    ]);
  }

  Widget _variantRow(int i) {
    final v = _variants[i];
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.fromLTRB(12, 6, 6, 12),
      decoration: BoxDecoration(
        color: AppPalette.surfaceAlt,
        borderRadius: BorderRadius.circular(AppPalette.rMd),
        border: Border.all(color: AppPalette.border),
      ),
      child: Column(children: [
        Row(children: [
          Expanded(
            child: TextFormField(
              controller: v.name,
              decoration: const InputDecoration(labelText: 'Variant name', isDense: true, filled: false),
            ),
          ),
          IconButton(
            icon: const Icon(LucideIcons.trash2, size: 18, color: AppPalette.destructive),
            onPressed: () => setState(() => _variants.removeAt(i)..dispose()),
          ),
        ]),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(
            child: TextFormField(
              controller: v.sku,
              decoration: const InputDecoration(labelText: 'SKU', isDense: true),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: TextFormField(
              controller: v.price,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Price', isDense: true),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: TextFormField(
              controller: v.stock,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Stock', isDense: true),
            ),
          ),
        ]),
      ]),
    );
  }
}
