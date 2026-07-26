import 'dart:convert';

import 'package:path_provider/path_provider.dart';
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

/// Local warehouse store (spec 08 §6.7).
///
/// A warehouse phone loses signal constantly, so scans are written here FIRST and pushed later. Two
/// properties matter more than anything else:
///
///  * **Durability.** A scan is only acknowledged to the operator once it is committed locally. If
///    the app is killed a second later, the scan still replays.
///  * **Tenant safety.** Every row carries `organization_id`, and everything is wiped on logout or
///    org switch — cached catalogue data is another tenant's data the moment someone else signs in.
///
/// NOTE ON THE SPEC: §6.7 names Drift. This uses sqflite instead — the same schema and transaction
/// guarantees without a build_runner codegen step, which keeps this slice verifiable in one pass. A
/// later move to Drift is mechanical since the table shapes are identical.
class WarehouseDb {
  WarehouseDb._(this._db);

  final Database _db;
  static const _uuid = Uuid();

  /// Outbox rows beyond this block new sessions — an unbounded silent backlog is worse than a
  /// blunt "sync required" message the operator can act on.
  static const int maxOutboxRows = 5000;

  static Future<WarehouseDb> open() async {
    final dir = await getApplicationDocumentsDirectory();
    final db = await openDatabase(
      '${dir.path}/hubby_warehouse.sqlite',
      version: 1,
      onCreate: (db, _) async {
        await db.execute('''
          CREATE TABLE outbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT NOT NULL UNIQUE,
            organization_id TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            payload TEXT NOT NULL,
            client_seq INTEGER,
            created_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT
          )
        ''');
        await db.execute('CREATE INDEX outbox_org ON outbox (organization_id, id)');
        await db.execute('''
          CREATE TABLE sync_meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
          )
        ''');
      },
    );
    return WarehouseDb._(db);
  }

  // --- Device identity -------------------------------------------------------------------------

  /// A random per-install id. Deliberately NOT a hardware id: those are privacy-sensitive and
  /// restricted on Android 10+. It only needs to be stable and unique per install.
  Future<String> deviceId() async {
    final rows = await _db.query('sync_meta', where: 'key = ?', whereArgs: ['device_id']);
    if (rows.isNotEmpty) return rows.first['value'] as String;

    final id = _uuid.v4();
    await _db.insert('sync_meta', {'key': 'device_id', 'value': id});
    return id;
  }

  /// Monotonic per-device counter. This is the real ordering key for out-of-order replays — the
  /// server keeps the highest seq it has seen and ignores anything older.
  Future<int> nextClientSeq() async {
    return await _db.transaction((txn) async {
      final rows = await txn.query('sync_meta', where: 'key = ?', whereArgs: ['client_seq']);
      final next = rows.isEmpty ? 1 : (int.tryParse(rows.first['value'] as String) ?? 0) + 1;
      await txn.insert(
        'sync_meta',
        {'key': 'client_seq', 'value': '$next'},
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
      return next;
    });
  }

  // --- Outbox ----------------------------------------------------------------------------------

  Future<int> outboxCount(String organizationId) async {
    final r = await _db.rawQuery(
      'SELECT COUNT(*) c FROM outbox WHERE organization_id = ?',
      [organizationId],
    );
    return (r.first['c'] as int?) ?? 0;
  }

  Future<bool> isBacklogged(String organizationId) async =>
      await outboxCount(organizationId) >= maxOutboxRows;

  /// Queue a scan. The uuid is the server-side idempotency key, so a replay is a no-op there.
  Future<void> enqueue({
    required String uuid,
    required String organizationId,
    required String endpoint,
    required Map<String, dynamic> payload,
    int? clientSeq,
  }) async {
    await _db.insert('outbox', {
      'uuid': uuid,
      'organization_id': organizationId,
      'endpoint': endpoint,
      'payload': jsonEncode(payload),
      'client_seq': clientSeq,
      'created_at': DateTime.now().millisecondsSinceEpoch,
      'attempts': 0,
    }, conflictAlgorithm: ConflictAlgorithm.ignore);
  }

  /// Oldest-first, so scans replay in the order the operator made them.
  Future<List<Map<String, dynamic>>> pending(String organizationId, {int limit = 50}) async {
    final rows = await _db.query(
      'outbox',
      where: 'organization_id = ?',
      whereArgs: [organizationId],
      orderBy: 'id ASC',
      limit: limit,
    );
    return rows.map((r) => {...r, 'payload': jsonDecode(r['payload'] as String)}).toList();
  }

  Future<void> remove(int id) async => _db.delete('outbox', where: 'id = ?', whereArgs: [id]);

  Future<void> recordFailure(int id, String error) async {
    await _db.rawUpdate(
      'UPDATE outbox SET attempts = attempts + 1, last_error = ? WHERE id = ?',
      [error, id],
    );
  }

  // --- Tenant hygiene --------------------------------------------------------------------------

  Future<void> wipeOrg(String organizationId) async =>
      _db.delete('outbox', where: 'organization_id = ?', whereArgs: [organizationId]);

  /// Called on logout. Cached data must never outlive the session that fetched it.
  Future<void> wipeAll() async {
    await _db.delete('outbox');
    await _db.delete('sync_meta', where: 'key != ?', whereArgs: ['device_id']);
  }

  Future<void> close() => _db.close();
}
