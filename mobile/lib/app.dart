import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:go_router/go_router.dart';
import 'core/locale/locale_cubit.dart';
import 'core/router/app_router.dart';
import 'core/theme/app_theme.dart';
import 'features/auth/bloc/auth_bloc.dart';
import 'shared/widgets/brand_mark.dart';

class HubbyApp extends StatefulWidget {
  const HubbyApp({super.key});
  @override
  State<HubbyApp> createState() => _HubbyAppState();
}

class _HubbyAppState extends State<HubbyApp> {
  late final GoRouter _router = buildRouter(context.read<AuthBloc>());

  @override
  Widget build(BuildContext context) {
    return BlocBuilder<LocaleCubit, Locale>(
      builder: (context, locale) {
        final theme = AppTheme.light(locale.languageCode);
        const delegates = [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ];
        const locales = [Locale('en'), Locale('ar')];

        // Splash while we resolve the saved session, before the router mounts
        // any auth-gated screen (avoids a flash of API errors).
        return BlocBuilder<AuthBloc, AuthState>(
          buildWhen: (p, c) =>
              (p.status == AuthStatus.unknown) != (c.status == AuthStatus.unknown),
          builder: (context, auth) {
            if (auth.status == AuthStatus.unknown) {
              return MaterialApp(
                debugShowCheckedModeBanner: false,
                theme: theme,
                locale: locale,
                supportedLocales: locales,
                localizationsDelegates: delegates,
                home: const Scaffold(
                  body: Center(child: BrandMark(size: 56)),
                ),
              );
            }
            return MaterialApp.router(
              title: 'Hubby',
              debugShowCheckedModeBanner: false,
              theme: theme,
              locale: locale,
              supportedLocales: locales,
              localizationsDelegates: delegates,
              routerConfig: _router,
            );
          },
        );
      },
    );
  }
}
