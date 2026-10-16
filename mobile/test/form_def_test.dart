import 'package:field_ops_mobile/models/form_def.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> q(String id, String type, {bool required = false, List<Map<String, dynamic>> options = const [], List<Map<String, dynamic>> conditions = const [], Map<String, dynamic>? validation, Map<String, dynamic>? settings}) => {
      'id': id,
      'type': type,
      'label': id,
      'required': required,
      'evidence_required': false,
      'options': options,
      'conditions': conditions,
      'validation': validation ?? {},
      'settings': settings ?? {},
    };

FormDef form(List<Map<String, dynamic>> questions) => FormDef.fromJson({
      'id': 'f1',
      'name': 'Test',
      'sections': [
        {'id': 's1', 'title': 'S', 'questions': questions}
      ],
    });

void main() {
  group('visibility', () {
    final f = form([
      q('stock', 'yes_no'),
      q('why', 'short_text', conditions: [
        {'depends_on': 'stock', 'operator': 'equals', 'value': 'no'}
      ]),
    ]);

    test('conditional question is hidden until its condition holds', () {
      expect(f.visibility({})['why'], isFalse);
      expect(f.visibility({'stock': true})['why'], isFalse);
      expect(f.visibility({'stock': false})['why'], isTrue);
    });

    test('yes/no compares loosely, like the server', () {
      expect(f.visibility({'stock': 'no'})['why'], isTrue);
    });

    test('hidden required questions never block submission', () {
      final g = form([
        q('stock', 'yes_no', required: true),
        q('why', 'short_text', required: true, conditions: [
          {'depends_on': 'stock', 'operator': 'equals', 'value': 'no'}
        ]),
      ]);
      expect(g.problems({'stock': true}, {}), isEmpty);
      expect(g.problems({'stock': false}, {}).keys, ['why']);
    });

    test('circular rules fail open instead of looping', () {
      final g = form([
        q('a', 'short_text', conditions: [
          {'depends_on': 'b', 'operator': 'answered', 'value': null}
        ]),
        q('b', 'short_text', conditions: [
          {'depends_on': 'a', 'operator': 'answered', 'value': null}
        ]),
      ]);
      expect(g.visibility({}).values, everyElement(isA<bool>()));
    });
  });

  group('validation', () {
    test('required answers', () {
      final f = form([q('name', 'short_text', required: true)]);
      expect(f.problems({}, {}).keys, ['name']);
      expect(f.problems({'name': 'x'}, {}), isEmpty);
    });

    test('number ranges', () {
      final f = form([q('n', 'integer', validation: {'min': 1, 'max': 10})]);
      expect(f.problems({'n': 5}, {}), isEmpty);
      expect(f.problems({'n': 11}, {}), isNotEmpty);
      expect(f.problems({'n': 2.5}, {}), isNotEmpty);
    });

    test('percentage and rating bounds', () {
      final f = form([q('p', 'percentage'), q('r', 'rating', settings: {'max': 5})]);
      expect(f.problems({'p': 101}, {}).keys, ['p']);
      expect(f.problems({'r': 6}, {}).keys, ['r']);
      expect(f.problems({'p': 50, 'r': 3}, {}), isEmpty);
    });

    test('select options', () {
      final f = form([
        q('c', 'single_select', options: [
          {'label': 'A', 'value': 'a'},
          {'label': 'B', 'value': 'b'}
        ])
      ]);
      expect(f.problems({'c': 'a'}, {}), isEmpty);
      expect(f.problems({'c': 'z'}, {}).keys, ['c']);
    });

    test('text length and pattern', () {
      final f = form([q('t', 'short_text', validation: {'min_length': 3, 'pattern': r'^[A-Z]+$'})]);
      expect(f.problems({'t': 'AB'}, {}).keys, ['t']);
      expect(f.problems({'t': 'abc'}, {}).keys, ['t']);
      expect(f.problems({'t': 'ABC'}, {}), isEmpty);
    });

    test('photo questions need a photo, counted per question', () {
      final f = form([q('shelf', 'photo', required: true)]);
      expect(f.problems({}, {}).keys, ['shelf']);
      expect(f.problems({}, {'shelf': 1}), isEmpty);
    });
  });
}
