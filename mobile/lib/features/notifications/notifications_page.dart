import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:intl/intl.dart';
import 'package:lucide_icons/lucide_icons.dart';
import '../../core/network/api_client.dart';
import '../../core/theme/app_palette.dart';
import '../../l10n/strings.dart';
import '../../shared/widgets/app_widgets.dart';
import '../../shared/widgets/async_builder.dart';

class NotificationsPage extends StatefulWidget {
  const NotificationsPage({super.key});
  @override
  State<NotificationsPage> createState() => _NotificationsPageState();
}

class _NotificationsPageState extends State<NotificationsPage> {
  late Future<List<dynamic>> _future = _load();

  Future<List<dynamic>> _load() async {
    final res = await context.read<ApiClient>().dio.get('/notifications');
    final data = res.data;
    return (data is Map ? data['data'] : data) as List? ?? [];
  }

  Future<void> _markRead(dynamic n) async {
    if (n['read_at'] != null) return;
    try {
      await context.read<ApiClient>().dio.post('/notifications/${n['id']}/read');
      if (mounted) setState(() => _future = _load());
    } catch (_) {/* non-blocking */}
  }

  Future<void> _markAllRead(List items) async {
    final unread = items.where((n) => n['read_at'] == null).toList();
    if (unread.isEmpty) return;
    final api = context.read<ApiClient>();
    try {
      await Future.wait(unread.map((n) => api.dio.post('/notifications/${n['id']}/read')));
      if (mounted) {
        showToast(context, 'All caught up.', ToastKind.success);
        setState(() => _future = _load());
      }
    } catch (e) {
      if (mounted) showToast(context, ApiClient.messageFrom(e), ToastKind.error);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('nav.notifications'))),
      body: AsyncView<List<dynamic>>(
        future: _future,
        onRetry: () => setState(() => _future = _load()),
        builder: (context, items) {
          if (items.isEmpty) return Center(child: Text(context.t('notifications.none')));
          final unreadCount = items.where((n) => n['read_at'] == null).length;
          final df = DateFormat.MMMd().add_jm();
          return Column(
            children: [
              if (unreadCount > 0)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
                  child: Row(children: [
                    Text('$unreadCount ${context.t('notifications.unread')}',
                        style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 13)),
                    const Spacer(),
                    TextButton(onPressed: () => _markAllRead(items), child: Text(context.t('notifications.markAllRead'))),
                  ]),
                ),
              Expanded(
                child: ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: items.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 10),
                  itemBuilder: (context, i) {
                    final n = items[i];
                    final unread = n['read_at'] == null;
                    final type = n['type']?.toString() ?? 'info';
                    final color = switch (type) {
                      'success' => AppPalette.secondary,
                      'warning' => AppPalette.warning,
                      'error' => AppPalette.destructive,
                      _ => AppPalette.primary,
                    };
                    final date = DateTime.tryParse(n['created_at']?.toString() ?? '');
                    return InkWell(
                      onTap: () => _markRead(n),
                      child: Opacity(
                        opacity: unread ? 1 : 0.6,
                        child: AppCard(
                          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Icon(LucideIcons.bell, color: color, size: 18),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(n['title']?.toString() ?? '',
                                      style: TextStyle(
                                          fontWeight: unread ? FontWeight.bold : FontWeight.w500)),
                                  Text(n['message']?.toString() ?? '',
                                      style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 13)),
                                  if (date != null)
                                    Padding(
                                      padding: const EdgeInsets.only(top: 4),
                                      child: Text(df.format(date),
                                          style: const TextStyle(color: AppPalette.mutedForeground, fontSize: 11)),
                                    ),
                                ],
                              ),
                            ),
                            if (unread)
                              Container(width: 8, height: 8,
                                  decoration: const BoxDecoration(color: AppPalette.primary, shape: BoxShape.circle)),
                          ]),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
