import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'app.dart';
import 'core/locale/locale_cubit.dart';
import 'core/network/api_client.dart';
import 'core/storage/session_store.dart';
import 'data/repositories/auth_repository.dart';
import 'features/auth/bloc/auth_bloc.dart';
import 'features/stores/cubit/stores_cubit.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();

  final session = SessionStore();
  final api = ApiClient(session);
  final authRepo = AuthRepository(api, session);

  final authBloc = AuthBloc(authRepo, session)..add(const AuthStarted());
  final localeCubit = LocaleCubit(session)..load();
  final storesCubit = StoresCubit(api);

  // Any 401 anywhere logs the user out cleanly.
  api.onUnauthorized = () => authBloc.add(const AuthLoggedOut());

  runApp(
    MultiRepositoryProvider(
      providers: [
        RepositoryProvider.value(value: session),
        RepositoryProvider.value(value: api),
        RepositoryProvider.value(value: authRepo),
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
