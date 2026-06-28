import 'package:bloc/bloc.dart';
import 'package:equatable/equatable.dart';
import '../../../core/network/api_client.dart';
import '../../../core/storage/session_store.dart';
import '../../../data/repositories/auth_repository.dart';

part 'auth_event.dart';
part 'auth_state.dart';

class AuthBloc extends Bloc<AuthEvent, AuthState> {
  AuthBloc(this._repo, this._session) : super(const AuthState.unknown()) {
    on<AuthStarted>(_onStarted);
    on<AuthLoginRequested>(_onLogin);
    on<AuthRegisterRequested>(_onRegister);
    on<AuthLoggedOut>(_onLogout);
    on<AuthOrgSwitched>(_onOrgSwitched);
  }

  final AuthRepository _repo;
  final SessionStore _session;

  Future<void> _onStarted(AuthStarted e, Emitter<AuthState> emit) async {
    try {
      final result = await _repo.currentUser();
      if (result == null) {
        emit(const AuthState.unauthenticated());
      } else {
        emit(AuthState.authenticated(result.user, result.organizations));
      }
    } catch (_) {
      emit(const AuthState.unauthenticated());
    }
  }

  Future<void> _onLogin(AuthLoginRequested e, Emitter<AuthState> emit) async {
    emit(state.copyWith(status: AuthStatus.submitting, error: null));
    try {
      final r = await _repo.login(e.email, e.password);
      emit(AuthState.authenticated(r.user, r.organizations));
    } catch (err) {
      emit(state.copyWith(
        status: AuthStatus.unauthenticated,
        error: ApiClient.messageFrom(err, 'Invalid credentials'),
      ));
    }
  }

  Future<void> _onRegister(AuthRegisterRequested e, Emitter<AuthState> emit) async {
    emit(state.copyWith(status: AuthStatus.submitting, error: null));
    try {
      final r = await _repo.register(
        name: e.name,
        email: e.email,
        password: e.password,
        organizationName: e.organizationName,
      );
      emit(AuthState.authenticated(r.user, r.organizations));
    } catch (err) {
      emit(state.copyWith(
        status: AuthStatus.unauthenticated,
        error: ApiClient.messageFrom(err, 'Could not create your account.'),
      ));
    }
  }

  Future<void> _onLogout(AuthLoggedOut e, Emitter<AuthState> emit) async {
    await _repo.logout();
    emit(const AuthState.unauthenticated());
  }

  Future<void> _onOrgSwitched(AuthOrgSwitched e, Emitter<AuthState> emit) async {
    await _session.setActiveOrgId(e.organizationId);
    emit(state.copyWith(activeOrgId: e.organizationId));
  }
}
