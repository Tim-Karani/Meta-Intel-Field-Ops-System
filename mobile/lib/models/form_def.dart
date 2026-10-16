import 'dart:convert';

/// A published form as downloaded for offline use, with the same visibility and validation rules the server applies.
/// Pure Dart (no Flutter imports) so it can be unit-tested directly.
class FormDef {
  FormDef({required this.id, required this.name, required this.requiresLiveCapture, required this.sections});

  final String id;
  final String name;
  final bool requiresLiveCapture;
  final List<FormSection> sections;

  factory FormDef.fromJson(Map<String, dynamic> j) => FormDef(
        id: j['id'].toString(),
        name: (j['name'] ?? 'Form').toString(),
        requiresLiveCapture: j['requires_live_capture'] == true,
        sections: ((j['sections'] as List?) ?? const [])
            .map((s) => FormSection.fromJson((s as Map).cast<String, dynamic>()))
            .toList(),
      );

  factory FormDef.decode(String json) => FormDef.fromJson((jsonDecode(json) as Map).cast<String, dynamic>());

  List<FormQuestion> get questions => [for (final s in sections) ...s.questions];

  /// Visibility of every question given the current [answers] (conditional questions).
  Map<String, bool> visibility(Map<String, dynamic> answers) {
    final byId = {for (final q in questions) q.id: q};
    final result = <String, bool>{};
    final resolving = <String>{};

    bool resolve(String id) {
      final known = result[id];
      if (known != null) return known;
      if (resolving.contains(id)) return result[id] = true; // circular rule: fail open, like the server
      resolving.add(id);
      var show = true;
      final q = byId[id];
      if (q != null) {
        for (final c in q.conditions) {
          final depVisible = byId.containsKey(c.dependsOn) && resolve(c.dependsOn);
          final actual = depVisible ? answers[c.dependsOn] : null;
          if (!c.holds(actual)) {
            show = false;
            break;
          }
        }
      }
      resolving.remove(id);
      return result[id] = show;
    }

    for (final q in questions) {
      resolve(q.id);
    }
    return result;
  }

  /// Problems that would block submission: question id -> message. [photoCounts] maps question id -> photos taken.
  Map<String, String> problems(Map<String, dynamic> answers, Map<String, int> photoCounts) {
    final vis = visibility(answers);
    final out = <String, String>{};
    for (final q in questions) {
      if (vis[q.id] != true) continue;
      if (q.isEvidence) {
        final n = photoCounts[q.id] ?? 0;
        if ((q.required || q.evidenceRequired) && n == 0) out[q.id] = 'A photo is required.';
        continue;
      }
      final v = answers[q.id];
      if (FormQuestion.isEmpty(v)) {
        if (q.required) out[q.id] = 'This question is required.';
        continue;
      }
      final msg = q.check(v);
      if (msg != null) out[q.id] = msg;
    }
    return out;
  }
}

class FormSection {
  FormSection({required this.id, required this.title, required this.description, required this.questions});

  final String id;
  final String title;
  final String? description;
  final List<FormQuestion> questions;

  factory FormSection.fromJson(Map<String, dynamic> j) => FormSection(
        id: j['id'].toString(),
        title: (j['title'] ?? '').toString(),
        description: j['description']?.toString(),
        questions: ((j['questions'] as List?) ?? const [])
            .map((q) => FormQuestion.fromJson((q as Map).cast<String, dynamic>()))
            .toList(),
      );
}

class FormOption {
  FormOption(this.label, this.value);
  final String label;
  final String value;
}

class FormCondition {
  FormCondition(this.dependsOn, this.operator, this.value);
  final String dependsOn;
  final String operator;
  final dynamic value;

  static String _loose(dynamic v) {
    if (v is bool) return v ? 'yes' : 'no';
    return v == null ? '' : v.toString().toLowerCase();
  }

  bool holds(dynamic actual) {
    switch (operator) {
      case 'answered':
        return !FormQuestion.isEmpty(actual);
      case 'equals':
        return actual is List ? actual.any((a) => _loose(a) == _loose(value)) : _loose(actual) == _loose(value);
      case 'not_equals':
        return actual is List ? !actual.any((a) => _loose(a) == _loose(value)) : _loose(actual) != _loose(value);
      case 'contains':
        if (actual is List) return actual.any((a) => _loose(a) == _loose(value));
        return actual is String && value is String && actual.contains(value as String);
      case 'gt':
        final a = num.tryParse('$actual');
        final b = num.tryParse('$value');
        return a != null && b != null && a > b;
      case 'lt':
        final a = num.tryParse('$actual');
        final b = num.tryParse('$value');
        return a != null && b != null && a < b;
      default:
        return true;
    }
  }
}

class FormQuestion {
  FormQuestion({
    required this.id,
    required this.type,
    required this.label,
    required this.helpText,
    required this.required,
    required this.evidenceRequired,
    required this.validation,
    required this.settings,
    required this.options,
    required this.conditions,
  });

  final String id;
  final String type;
  final String label;
  final String? helpText;
  final bool required;
  final bool evidenceRequired;
  final Map<String, dynamic> validation;
  final Map<String, dynamic> settings;
  final List<FormOption> options;
  final List<FormCondition> conditions;

  factory FormQuestion.fromJson(Map<String, dynamic> j) => FormQuestion(
        id: j['id'].toString(),
        type: (j['type'] ?? 'short_text').toString(),
        label: (j['label'] ?? '').toString(),
        helpText: j['help_text']?.toString(),
        required: j['required'] == true,
        evidenceRequired: j['evidence_required'] == true,
        validation: j['validation'] is Map ? (j['validation'] as Map).cast<String, dynamic>() : <String, dynamic>{},
        settings: j['settings'] is Map ? (j['settings'] as Map).cast<String, dynamic>() : <String, dynamic>{},
        options: ((j['options'] as List?) ?? const [])
            .map((o) => FormOption((o as Map)['label'].toString(), o['value'].toString()))
            .toList(),
        conditions: ((j['conditions'] as List?) ?? const [])
            .map((c) => FormCondition((c as Map)['depends_on'].toString(), (c['operator'] ?? 'equals').toString(), c['value']))
            .toList(),
      );

  bool get isEvidence => type == 'photo' || type == 'signature';

  static bool isEmpty(dynamic v) => v == null || v == '' || (v is List && v.isEmpty);

  /// Returns an error message, or null when [v] is acceptable for this question type.
  String? check(dynamic v) {
    num? asNum() => v is num ? v : num.tryParse('$v');
    switch (type) {
      case 'short_text':
      case 'long_text':
      case 'barcode': {
        if (v is! String) return 'Must be text.';
        final max = (validation['max_length'] as num?)?.toInt() ?? (type == 'long_text' ? 5000 : 255);
        if (v.length > max) return 'Must be at most $max characters.';
        final min = (validation['min_length'] as num?)?.toInt();
        if (min != null && v.length < min) return 'Must be at least $min characters.';
        final pattern = validation['pattern'];
        if (pattern is String && pattern.isNotEmpty) {
          try {
            if (!RegExp(pattern).hasMatch(v)) return 'Does not match the required format.';
          } catch (_) {/* an invalid pattern is the form designer's problem; the server decides */}
        }
        return null;
      }
      case 'integer': {
        final n = asNum();
        if (n == null || n != n.toInt()) return 'Must be a whole number.';
        return _range(n);
      }
      case 'decimal':
      case 'currency': {
        final n = asNum();
        return n == null ? 'Must be a number.' : _range(n);
      }
      case 'percentage': {
        final n = asNum();
        return (n == null || n < 0 || n > 100) ? 'Must be between 0 and 100.' : null;
      }
      case 'rating': {
        final max = (settings['max'] as num?)?.toInt() ?? 5;
        final n = asNum();
        return (n == null || n != n.toInt() || n < 1 || n > max) ? 'Must be a rating from 1 to $max.' : null;
      }
      case 'yes_no':
        return (v is bool || ['yes', 'no', 'true', 'false', '1', '0'].contains('$v'.toLowerCase())) ? null : 'Must be yes or no.';
      case 'single_select':
      case 'dropdown':
        return options.any((o) => o.value == v) ? null : 'Select one of the available options.';
      case 'multi_select': {
        if (v is! List) return 'Select only from the available options.';
        final allowed = options.map((o) => o.value).toSet();
        return v.every((x) => allowed.contains(x)) ? null : 'Select only from the available options.';
      }
      case 'gps':
        return (v is Map && v['lat'] is num && v['lng'] is num) ? null : 'Must be a valid coordinate pair.';
      default:
        return null;
    }
  }

  String? _range(num n) {
    final min = validation['min'];
    final max = validation['max'];
    if (min is num && n < min) return 'Must be at least $min.';
    if (max is num && n > max) return 'Must be at most $max.';
    return null;
  }
}
