import 'dart:convert';
import 'dart:typed_data';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:hubby_global/core/network/api_client.dart';
import 'package:hubby_global/core/storage/session_store.dart';
import 'package:hubby_global/core/theme/app_theme.dart';
import 'package:hubby_global/features/products/products_page.dart';
import 'package:hubby_global/features/stores/cubit/stores_cubit.dart';

/// Returns canned JSON for every request so we can render the real grid offline.
class _FakeAdapter implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
      RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    final products = List.generate(6, (i) => {
          'id': i + 1,
          'name': 'Sample Product ${i + 1} with a fairly long name to test wrapping',
          'sku': 'SKU-${1000 + i}',
          'price': (19.99 + i * 10),
          'stock': [0, 4, 25, 120, 8, 60][i],
          'image_url': null,
          'category_id': null,
          'variants': const [],
        });
    final body = jsonEncode({'data': products, 'current_page': 1, 'per_page': 50, 'total': 6, 'last_page': 1});
    return ResponseBody.fromString(
      body,
      200,
      headers: {Headers.contentTypeHeader: [Headers.jsonContentType]},
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  testWidgets('ProductsPage renders the grid with data without throwing', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final api = ApiClient(SessionStore());
    api.dio.httpClientAdapter = _FakeAdapter();

    await tester.pumpWidget(
      MultiRepositoryProvider(
        providers: [RepositoryProvider.value(value: api)],
        child: MultiBlocProvider(
          providers: [BlocProvider(create: (_) => StoresCubit(api))],
          child: MaterialApp(
            theme: AppTheme.light('en'),
            home: const Scaffold(body: ProductsPage()),
          ),
        ),
      ),
    );

    // Resolve the products future + settle layout/animations.
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pump(const Duration(milliseconds: 600));

    expect(tester.takeException(), isNull);
    expect(find.text('Sample Product 1 with a fairly long name to test wrapping'), findsOneWidget);
  });
}
