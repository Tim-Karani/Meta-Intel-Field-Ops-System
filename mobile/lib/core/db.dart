import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

/// Local SQLite store. Everything the screens show comes from here; nothing is ever read straight from the network.
///
/// Server copies (assignments, forms, products, notifications) are replaced by each download.
/// Field work (visits, items, photos, issues, attendance) and the outbox are written by the user and only ever
/// removed after the server has confirmed them - never merely because a sync failed.
class AppDatabase {
  AppDatabase._(this.db);
  final Database db;

  static Future<AppDatabase> open({String? path}) async {
    final dir = await getDatabasesPath();
    final db = await openDatabase(
      path ?? p.join(dir, 'field_ops.db'),
      version: 1,
      onConfigure: (d) async => d.execute('PRAGMA foreign_keys = ON'),
      onCreate: (d, v) async {
        for (final s in _schema) {
          await d.execute(s);
        }
      },
    );
    return AppDatabase._(db);
  }

  /// Wipes everything (sign-out with the user's confirmation, or a different user signing in).
  Future<void> wipe() async {
    for (final t in ['outbox', 'attendance', 'issues', 'photos', 'visit_items', 'visits', 'notifications', 'products', 'forms', 'assignments']) {
      await db.delete(t);
    }
    await db.delete('meta', where: "k NOT IN ('device_id','base_url')");
  }

  static const _schema = <String>[
    'CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT)',
    '''CREATE TABLE assignments (
      id TEXT PRIMARY KEY, scheduled_date TEXT NOT NULL, status TEXT NOT NULL, removed INTEGER NOT NULL DEFAULT 0, json TEXT NOT NULL)''',
    'CREATE INDEX ix_assignments_date ON assignments(scheduled_date)',
    'CREATE TABLE forms (id TEXT PRIMARY KEY, json TEXT NOT NULL)',
    'CREATE TABLE products (id TEXT PRIMARY KEY, name TEXT NOT NULL, json TEXT NOT NULL)',
    'CREATE TABLE notifications (id TEXT PRIMARY KEY, created_at TEXT, read INTEGER NOT NULL DEFAULT 0, json TEXT NOT NULL)',
    '''CREATE TABLE visits (
      local_id TEXT PRIMARY KEY,
      server_id TEXT,
      assignment_id TEXT NOT NULL,
      form_id TEXT,
      campaign_id TEXT,
      location_id TEXT,
      status TEXT NOT NULL,
      visit_date TEXT,
      answers TEXT NOT NULL DEFAULT '{}',
      notes TEXT,
      checkin TEXT,
      checkout TEXT,
      review_note TEXT,
      server_json TEXT,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL)''',
    'CREATE INDEX ix_visits_assignment ON visits(assignment_id)',
    'CREATE INDEX ix_visits_server ON visits(server_id)',
    '''CREATE TABLE visit_items (
      id TEXT PRIMARY KEY, visit_local_id TEXT NOT NULL, kind TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL)''',
    'CREATE INDEX ix_items_visit ON visit_items(visit_local_id)',
    '''CREATE TABLE photos (
      id TEXT PRIMARY KEY,
      visit_local_id TEXT NOT NULL,
      path TEXT,
      question_id TEXT,
      kind TEXT NOT NULL DEFAULT 'evidence',
      captured_at TEXT NOT NULL,
      latitude REAL,
      longitude REAL,
      bytes INTEGER,
      server_id TEXT,
      uploaded INTEGER NOT NULL DEFAULT 0)''',
    'CREATE INDEX ix_photos_visit ON photos(visit_local_id)',
    '''CREATE TABLE issues (
      id TEXT PRIMARY KEY,
      visit_local_id TEXT,
      campaign_id TEXT,
      location_id TEXT,
      title TEXT NOT NULL,
      description TEXT,
      category TEXT,
      priority TEXT NOT NULL,
      server_id TEXT,
      synced INTEGER NOT NULL DEFAULT 0,
      created_at TEXT NOT NULL)''',
    '''CREATE TABLE attendance (
      day TEXT PRIMARY KEY, clock_in TEXT, clock_out TEXT, status TEXT, server_json TEXT)''',
    '''CREATE TABLE outbox (
      seq INTEGER PRIMARY KEY AUTOINCREMENT,
      id TEXT NOT NULL UNIQUE,
      type TEXT NOT NULL,
      entity_id TEXT,
      payload TEXT NOT NULL DEFAULT '{}',
      status TEXT NOT NULL DEFAULT 'PENDING',
      retry_count INTEGER NOT NULL DEFAULT 0,
      next_attempt_at TEXT,
      last_attempt_at TEXT,
      error TEXT,
      http_status INTEGER,
      created_at TEXT NOT NULL,
      updated_at TEXT NOT NULL)''',
    'CREATE INDEX ix_outbox_status ON outbox(status, seq)',
    'CREATE INDEX ix_outbox_entity ON outbox(entity_id)',
  ];
}
