import 'package:bloc/bloc.dart';
import 'package:equatable/equatable.dart';
import '../../../core/network/api_client.dart';

class StoresState extends Equatable {
  const StoresState({
    this.stores = const [],
    this.loading = true,
    this.oauthEnabled = const {},
  });

  final List<dynamic> stores;
  final bool loading;
  final Map<String, dynamic> oauthEnabled;

  bool get hasConnectedStore => stores.isNotEmpty;

  List<String> get connectedPlatforms {
    final set = <String>{};
    for (final s in stores) {
      final p = s['platform'];
      if (p is String && p.isNotEmpty) set.add(p);
    }
    return set.toList();
  }

  StoresState copyWith({List<dynamic>? stores, bool? loading, Map<String, dynamic>? oauthEnabled}) =>
      StoresState(
        stores: stores ?? this.stores,
        loading: loading ?? this.loading,
        oauthEnabled: oauthEnabled ?? this.oauthEnabled,
      );

  @override
  List<Object?> get props => [stores, loading, oauthEnabled];
}

/// Shared store list — connect/disconnect anywhere refreshes filters + prompts
/// across the app (mirrors the web StoresProvider).
class StoresCubit extends Cubit<StoresState> {
  StoresCubit(this._api) : super(const StoresState());

  final ApiClient _api;

  Future<void> refresh() async {
    try {
      final res = await _api.dio.get('/stores');
      emit(state.copyWith(stores: (res.data as List?) ?? [], loading: false));
    } catch (_) {
      emit(state.copyWith(stores: const [], loading: false));
    }
  }

  Future<void> loadOauthOptions() async {
    try {
      final res = await _api.dio.get('/stores/connect-options');
      emit(state.copyWith(oauthEnabled: (res.data?['oauth_enabled'] as Map?)?.cast<String, dynamic>() ?? {}));
    } catch (_) {/* token-only fallback */}
  }
}
