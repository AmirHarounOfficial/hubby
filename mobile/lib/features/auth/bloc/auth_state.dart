part of 'auth_bloc.dart';

enum AuthStatus { unknown, authenticated, unauthenticated, submitting }

class AuthState extends Equatable {
  const AuthState({
    required this.status,
    this.user,
    this.organizations = const [],
    this.activeOrgId,
    this.error,
  });

  const AuthState.unknown() : this(status: AuthStatus.unknown);
  const AuthState.unauthenticated() : this(status: AuthStatus.unauthenticated);

  AuthState.authenticated(Map<String, dynamic> user, List<dynamic> orgs)
      : this(
          status: AuthStatus.authenticated,
          user: user,
          organizations: orgs,
          activeOrgId: orgs.isNotEmpty ? orgs.first['id'] as int? : null,
        );

  final AuthStatus status;
  final Map<String, dynamic>? user;
  final List<dynamic> organizations;
  final int? activeOrgId;
  final String? error;

  bool get isAuthenticated => status == AuthStatus.authenticated;
  String get userName => user?['name'] as String? ?? '';
  String get userEmail => user?['email'] as String? ?? '';

  AuthState copyWith({
    AuthStatus? status,
    Map<String, dynamic>? user,
    List<dynamic>? organizations,
    int? activeOrgId,
    String? error,
  }) {
    return AuthState(
      status: status ?? this.status,
      user: user ?? this.user,
      organizations: organizations ?? this.organizations,
      activeOrgId: activeOrgId ?? this.activeOrgId,
      error: error,
    );
  }

  @override
  List<Object?> get props => [status, user, organizations, activeOrgId, error];
}
