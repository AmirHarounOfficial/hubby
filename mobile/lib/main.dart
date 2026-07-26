import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:provider/provider.dart';
import 'app.dart';
import 'core/locale/locale_cubit.dart';
import 'core/network/api_client.dart';
import 'core/storage/session_store.dart';
import 'data/repositories/auth_repository.dart';
import 'features/auth/bloc/auth_bloc.dart';
import 'features/stores/cubit/stores_cubit.dart';
import 'features/warehouse/data/scan_queue.dart';
import 'features/warehouse/data/warehouse_db.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final session = SessionStore();
  final api = ApiClient(session);
  final authRepo = AuthRepository(api, session);

  // Local warehouse store — opened before runApp so a scan is never the thing that has to wait on
  // disk I/O. Failing to open must not brick the whole app, so warehouse features degrade instead.
  final warehouseDb = await WarehouseDb.open();
  final scanQueue = ScanQueue(api, warehouseDb, session);

  final authBloc = AuthBloc(authRepo, session)..add(const AuthStarted());
  final localeCubit = LocaleCubit(session)..load();
  final storesCubit = StoresCubit(api);

  // Any 401 anywhere logs the user out cleanly. Cached warehouse data must not outlive the
  // session that fetched it — the next person to sign in may be a different tenant.
  api.onUnauthorized = () {
    warehouseDb.wipeAll();
    authBloc.add(const AuthLoggedOut());
  };

  runApp(
    MultiRepositoryProvider(
      providers: [
        RepositoryProvider.value(value: session),
        RepositoryProvider.value(value: api),
        RepositoryProvider.value(value: authRepo),
        RepositoryProvider.value(value: warehouseDb),
        ChangeNotifierProvider.value(value: scanQueue),
      ],
      child: MultiBlocProvider(
        providers: [
          BlocProvider.value(value: authBloc),
          BlocProvider.value(value: localeCubit),
          BlocProvider.value(value: storesCubit),
        ],
        child: const HubbyApp(),
      ),
    ),
  );
}
