part of 'auth_bloc.dart';

abstract class AuthEvent extends Equatable {
  const AuthEvent();
  @override
  List<Object?> get props => [];
}

class AuthStarted extends AuthEvent {
  const AuthStarted();
}

class AuthLoginRequested extends AuthEvent {
  const AuthLoginRequested(this.email, this.password);
  final String email;
  final String password;
  @override
  List<Object?> get props => [email, password];
}

class AuthRegisterRequested extends AuthEvent {
  const AuthRegisterRequested({
    required this.name,
    required this.email,
    required this.password,
    required this.organizationName,
  });
  final String name;
  final String email;
  final String password;
  final String organizationName;
  @override
  List<Object?> get props => [name, email, password, organizationName];
}

class AuthLoggedOut extends AuthEvent {
  const AuthLoggedOut();
}

class AuthOrgSwitched extends AuthEvent {
  const AuthOrgSwitched(this.organizationId);
  final int organizationId;
  @override
  List<Object?> get props => [organizationId];
}
