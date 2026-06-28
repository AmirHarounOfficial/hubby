# Enabling the official new Saudi Riyal sign (﷼ → U+20C0)

The app currently shows **`SAR`** before amounts because the brand-new riyal
glyph (Unicode `U+20C0`, adopted 2025) isn't present in any bundled font yet, so
it would otherwise render as a blank box.

To switch to the **official symbol**, do this once:

### 1. Add the font file
Download the official **"Saudi Riyal"** font from the Saudi Central Bank (SAMA)
and save it here as:

```
mobile/fonts/SaudiRiyal.ttf
```

### 2. Register it in `pubspec.yaml`
Under the existing `flutter:` section, add:

```yaml
flutter:
  uses-material-design: true
  fonts:
    - family: SaudiRiyal
      fonts:
        - asset: fonts/SaudiRiyal.ttf
```

### 3. Make the symbol use it
In `lib/core/format.dart`, change:

```dart
const String kSar = 'SAR';
```
to:
```dart
const String kSar = kSarGlyph; // official U+20C0 sign
```

### 4. Tell the text to fall back to that font for the glyph
In `lib/core/theme/app_theme.dart`, add `fontFamilyFallback: const ['SaudiRiyal']`
to the body text styles (or apply it to `AppText.number`). Inter will render the
digits and fall back to `SaudiRiyal` only for the riyal glyph.

Then `flutter pub get` + hot-restart — every amount across the app shows the
official sign automatically (English and Arabic).
