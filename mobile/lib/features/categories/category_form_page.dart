import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_palette.dart';
import '../../shared/widgets/app_widgets.dart';

/// Create / edit a category (name, description, optional parent).
class CategoryFormPage extends StatefulWidget {
  const CategoryFormPage({super.key, this.category, this.categories = const []});

  /// When non-null the form edits this category.
  final Map<String, dynamic>? category;

  /// Existing categories — used to populate the parent dropdown.
  final List<dynamic> categories;

  bool get isEdit => category != null;

  @override
  State<CategoryFormPage> createState() => _CategoryFormPageState();
}

class _CategoryFormPageState extends State<CategoryFormPage> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _description;
  int? _parentId;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(text: widget.category?['name']?.toString() ?? '');
    _description = TextEditingController(text: widget.category?['description']?.toString() ?? '');
    _parentId = widget.category?['parent_id'] as int?;
  }

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _saving = true);
    final payload = {
      'name': _name.text.trim(),
      'description': _description.text.trim(),
      'parent_id': _parentId,
    };
    try {
      final api = context.read<ApiClient>();
      if (widget.isEdit) {
        await api.dio.put('/categories/${widget.category!['id']}', data: payload);
      } else {
        await api.dio.post('/categories', data: payload);
      }
      if (!mounted) return;
      showToast(context, widget.isEdit ? 'Category updated.' : 'Category created.', ToastKind.success);
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final selfId = widget.category?['id'] as int?;
    final parents = widget.categories.where((c) => c['id'] != selfId).toList();
    return Scaffold(
      appBar: AppBar(title: Text(widget.isEdit ? 'Edit category' : 'New category')),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(labelText: 'Name'),
              validator: (v) => (v == null || v.trim().isEmpty) ? 'Name is required' : null,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _description,
              maxLines: 3,
              decoration: const InputDecoration(labelText: 'Description (optional)'),
            ),
            const SizedBox(height: 12),
            DropdownButtonFormField<int?>(
              initialValue: _parentId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Parent category'),
              items: [
                const DropdownMenuItem<int?>(value: null, child: Text('None (top level)')),
                ...parents.map((c) => DropdownMenuItem<int?>(
                      value: c['id'] as int?,
                      child: Text(c['name']?.toString() ?? ''),
                    )),
              ],
              onChanged: (v) => setState(() => _parentId = v),
            ),
            const SizedBox(height: 24),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: _saving ? null : _save,
                child: _saving
                    ? const SizedBox(height: 18, width: 18,
                        child: CircularProgressIndicator(strokeWidth: 2, color: AppPalette.primary))
                    : Text(widget.isEdit ? 'Save changes' : 'Create category'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
