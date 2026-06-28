import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import '../storage/session_store.dart';

/// Holds the active locale and persists it. `ar` flips the app to RTL via
/// MaterialApp's built-in Directionality handling.
class LocaleCubit extends Cubit<Locale> {
  LocaleCubit(this._session) : super(const Locale('en'));

  final SessionStore _session;

  Future<void> load() async {
    final code = await _session.localeCode;
    emit(Locale(code));
  }

  Future<void> toggle() async {
    final next = state.languageCode == 'ar' ? 'en' : 'ar';
    await _session.setLocale(next);
    emit(Locale(next));
  }

  Future<void> set(String code) async {
    await _session.setLocale(code);
    emit(Locale(code));
  }
}
