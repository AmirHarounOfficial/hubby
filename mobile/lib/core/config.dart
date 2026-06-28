/// App-wide configuration.
class AppConfig {
  /// API base URL. Defaults to production; override for local dev with:
  ///   flutter run --dart-define=API_URL=http://10.0.2.2:8000/api
  /// (10.0.2.2 reaches the host machine from the Android emulator.)
  static const String apiBaseUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'https://hubbynetwork.com/api',
  );

  static const String appName = 'Hubby';
}
