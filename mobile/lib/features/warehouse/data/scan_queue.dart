import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import '../../../core/network/api_client.dart';
import '../../../core/storage/session_store.dart';
import 'warehouse_db.dart';

/// The result of submitting a scan: either a live server answer, or an acknowledgement that it was
/// queued for replay. The UI shows these differently — an operator must always know whether the
/// system has actually confirmed the item or merely accepted it for later.
class ScanOutcome {
  const ScanOutcome({required this.queued, this.data, this.error});

  final bool queued;
  final Map<String, dynamic>? data;
  final String? error;

  bool get ok => error == null;

  /// The workflow verdict from the server ('accepted', 'wrong_item', 'over_pick', …).
  String get result => (data?['result'] as String?) ?? (queued ? 'queued' : 'accepted');
}

/// Submits scans, queueing them when offline and draining the queue when connectivity returns
/// (spec 08 §4.6).
///
/// Every scan carries a client-generated uuid so the server can treat a replay as a no-op and
/// return its original answer. That is what makes "send it again" always safe.
class ScanQueue extends ChangeNotifier {
  ScanQueue(this._api, this._db, this._session) {
    _sub = Connectivity().onConnectivityChanged.listen((r) {
      final online = !r.contains(ConnectivityResult.none);
      if (online && !_online) {
        _online = true;
        unawaited(drain());
      } else {
        _online = online;
      }
      notifyListeners();
    });
    unawaited(_refreshPending());
  }

  final ApiClient _api;
  final WarehouseDb _db;
  final SessionStore _session;
  static const _uuid = Uuid();

  /// Resolved per call rather than captured once: an org switch must not push one tenant's queued
  /// scans under another tenant's header.
  Future<String> get _organizationId async => '${await _session.activeOrgId ?? 0}';

  StreamSubscription? _sub;
  bool _online = true;
  bool _draining = false;
  int _pending = 0;

  bool get online => _online;
  int get pending => _pending;
  bool get hasBacklog => _pending > 0;

  Future<void> _refreshPending() async {
    _pending = await _db.outboxCount(await _organizationId);
    notifyListeners();
  }

  /// Send a scan, or queue it. Returns as soon as the outcome is known.
  ///
  /// The scan is written to the outbox BEFORE the network call, so a crash mid-request still
  /// replays it; a successful call removes it again. The cost is one extra local write per scan,
  /// which is far cheaper than a lost pick.
  Future<ScanOutcome> submit({
    required String endpoint,
    required Map<String, dynamic> payload,
    bool needsClientSeq = false,
  }) async {
    final uuid = _uuid.v4();
    final deviceId = await _db.deviceId();
    final clientSeq = needsClientSeq ? await _db.nextClientSeq() : null;

    final body = {
      ...payload,
      'uuid': uuid,
      'device_id': deviceId,
      if (clientSeq != null) 'client_seq': clientSeq,
    };

    await _db.enqueue(
      uuid: uuid,
      organizationId: await _organizationId,
      endpoint: endpoint,
      payload: body,
      clientSeq: clientSeq,
    );
    await _refreshPending();

    if (!_online) {
      return const ScanOutcome(queued: true);
    }

    try {
      final res = await _api.dio.post(endpoint, data: {...body, 'was_offline': false});
      await _removeByUuid(uuid);
      await _refreshPending();
      return ScanOutcome(queued: false, data: Map<String, dynamic>.from(res.data as Map));
    } on DioException catch (e) {
      // A 4xx is a real verdict (wrong item, over-pick) — the server saw it, so drop the queued
      // copy and show the answer. Only transport failures are worth replaying.
      final status = e.response?.statusCode ?? 0;
      if (status >= 400 && status < 500 && e.response?.data is Map) {
        await _removeByUuid(uuid);
        await _refreshPending();
        return ScanOutcome(
          queued: false,
          data: Map<String, dynamic>.from(e.response!.data as Map),
          error: status == 422 || status == 404 ? null : ApiClient.messageFrom(e),
        );
      }

      _online = false;
      notifyListeners();
      return const ScanOutcome(queued: true);
    }
  }

  /// Push everything queued, oldest first. Stops at the first transport failure so ordering holds.
  Future<void> drain() async {
    if (_draining) return;
    _draining = true;

    try {
      while (true) {
        final batch = await _db.pending(await _organizationId, limit: 25);
        if (batch.isEmpty) break;

        for (final row in batch) {
          final id = row['id'] as int;
          final payload = Map<String, dynamic>.from(row['payload'] as Map);

          try {
            await _api.dio.post(
              row['endpoint'] as String,
              data: {...payload, 'was_offline': true},
            );
            await _db.remove(id);
          } on DioException catch (e) {
            final status = e.response?.statusCode ?? 0;
            if (status >= 400 && status < 500) {
              // The server rejected it on its merits; replaying will never help.
              await _db.remove(id);
              continue;
            }
            await _db.recordFailure(id, ApiClient.messageFrom(e));
            _online = false;
            _draining = false;
            await _refreshPending();
            notifyListeners();
            return;
          }
        }
      }
    } finally {
      _draining = false;
      await _refreshPending();
    }
  }

  Future<void> _removeByUuid(String uuid) async {
    final rows = await _db.pending(await _organizationId, limit: WarehouseDb.maxOutboxRows);
    for (final r in rows) {
      if (r['uuid'] == uuid) {
        await _db.remove(r['id'] as int);
        return;
      }
    }
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }
}
