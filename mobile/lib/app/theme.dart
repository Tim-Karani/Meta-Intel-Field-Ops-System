import 'package:flutter/material.dart';

/// Ink navy + saffron, matching the web app.
class Brand {
  static const ink = Color(0xFF0F1B33);
  static const inkSoft = Color(0xFF1B2B4B);
  static const saffron = Color(0xFFE8920C);
  static const saffronSoft = Color(0xFFFDF0D9);
  static const surface = Color(0xFFF5F6FA);
  static const good = Color(0xFF138A5B);
  static const bad = Color(0xFFC62F3F);
  static const warn = Color(0xFFB86E00);
  static const muted = Color(0xFF66708A);
}

ThemeData buildTheme() {
  final scheme = ColorScheme.fromSeed(seedColor: Brand.ink, brightness: Brightness.light).copyWith(
    primary: Brand.ink,
    onPrimary: Colors.white,
    secondary: Brand.saffron,
    onSecondary: Brand.ink,
    secondaryContainer: Brand.saffronSoft,
    onSecondaryContainer: Brand.ink,
    surface: Colors.white,
    error: Brand.bad,
  );
  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: Brand.surface,
    appBarTheme: const AppBarTheme(
      backgroundColor: Brand.ink,
      foregroundColor: Colors.white,
      elevation: 0,
      centerTitle: false,
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: Brand.saffron,
        foregroundColor: Brand.ink,
        minimumSize: const Size.fromHeight(48),
        textStyle: const TextStyle(fontWeight: FontWeight.w700),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: Brand.ink,
        minimumSize: const Size.fromHeight(48),
        side: const BorderSide(color: Color(0xFFCBD1E0)),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFCBD1E0))),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Color(0xFFCBD1E0))),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: Brand.saffron, width: 2)),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: Colors.white,
      indicatorColor: Brand.saffronSoft,
      labelTextStyle: WidgetStateProperty.all(const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
    ),
    chipTheme: ChipThemeData(shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20))),
  );
}
