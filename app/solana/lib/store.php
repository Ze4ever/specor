<?php
function store_get_sol_asset_prices(int $userId): array
{
    $stmt = app_pdo()->prepare('SELECT token, price FROM sol_asset_prices WHERE user_id = ? ORDER BY token');
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string) $row['token']] = (float) $row['price'];
    }
    return $out;
}

function store_replace_sol_asset_prices(int $userId, array $prices): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM sol_asset_prices WHERE user_id = ?');
        $del->execute([$userId]);

        $ins = $pdo->prepare('INSERT INTO sol_asset_prices (user_id, token, price, updated_at) VALUES (?, ?, ?, ?)');
        foreach ($prices as $token => $price) {
            $ins->execute([$userId, (string) $token, (float) $price, db_now()]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_upsert_sol_asset_price(int $userId, string $token, float $price): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO sol_asset_prices (user_id, token, price, updated_at) VALUES (?, ?, ?, ?)
         ON CONFLICT(user_id, token) DO UPDATE SET price=excluded.price, updated_at=excluded.updated_at'
    );
    $stmt->execute([$userId, $token, $price, db_now()]);
}

function store_remove_sol_asset_price(int $userId, string $token): void
{
    $stmt = app_pdo()->prepare('DELETE FROM sol_asset_prices WHERE user_id = ? AND token = ?');
    $stmt->execute([$userId, $token]);
}

function store_get_sol_transactions(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT
            id, wallet, chain_name, transaction_url, uniswap_url, pool_id, action_name, tx_date,
            asset_1, asset_2, deposit_1, deposit_2, deposit_1_usd, deposit_2_usd, total, fees
         FROM sol_transactions
         WHERE user_id = ?
         ORDER BY tx_date ASC, id ASC'
    );
    $stmt->execute([$userId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'wallet' => (string) $row['wallet'],
            'chain' => (string) $row['chain_name'],
            'transaction' => (string) $row['transaction_url'],
            'uniswap' => (string) $row['uniswap_url'],
            'pool_id' => normalize_pool_id((string) $row['pool_id']),
            'action' => (string) $row['action_name'],
            'date' => (string) $row['tx_date'],
            'asset_1' => (string) $row['asset_1'],
            'asset_2' => (string) $row['asset_2'],
            'deposit_1' => (float) $row['deposit_1'],
            'deposit_2' => (float) $row['deposit_2'],
            'deposit_1_usd' => (float) $row['deposit_1_usd'],
            'deposit_2_usd' => (float) $row['deposit_2_usd'],
            'total' => (float) $row['total'],
            'fees' => (float) $row['fees'],
        ];
    }
    return $rows;
}

function store_insert_sol_transaction(int $userId, array $tx): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO sol_transactions (
            user_id, wallet, chain_name, transaction_url, uniswap_url, pool_id, action_name, tx_date,
            asset_1, asset_2, deposit_1, deposit_2, deposit_1_usd, deposit_2_usd, total, fees, created_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        normalize_wallet_label((string) ($tx['wallet'] ?? '')),
        normalize_chain_label((string) ($tx['chain'] ?? '')),
        (string) ($tx['transaction'] ?? ''),
        (string) ($tx['uniswap'] ?? ''),
        normalize_pool_id((string) ($tx['pool_id'] ?? '')),
        (string) ($tx['action'] ?? 'create'),
        (string) ($tx['date'] ?? db_now()),
        (string) ($tx['asset_1'] ?? ''),
        (string) ($tx['asset_2'] ?? ''),
        (float) ($tx['deposit_1'] ?? 0),
        (float) ($tx['deposit_2'] ?? 0),
        (float) ($tx['deposit_1_usd'] ?? 0),
        (float) ($tx['deposit_2_usd'] ?? 0),
        (float) ($tx['total'] ?? 0),
        (float) ($tx['fees'] ?? 0),
        db_now(),
    ]);
}

function store_delete_sol_transaction(int $userId, int $txId): void
{
    $stmt = app_pdo()->prepare('DELETE FROM sol_transactions WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $txId]);
}

function store_update_sol_transaction(int $userId, int $txId, array $tx): bool
{
    $stmt = app_pdo()->prepare(
        'UPDATE sol_transactions
         SET wallet = ?, chain_name = ?, transaction_url = ?, uniswap_url = ?, pool_id = ?, action_name = ?, tx_date = ?,
             asset_1 = ?, asset_2 = ?, deposit_1 = ?, deposit_2 = ?, deposit_1_usd = ?, deposit_2_usd = ?, total = ?, fees = ?
         WHERE user_id = ? AND id = ?'
    );
    $stmt->execute([
        normalize_wallet_label((string) ($tx['wallet'] ?? '')),
        normalize_chain_label((string) ($tx['chain'] ?? '')),
        (string) ($tx['transaction'] ?? ''),
        (string) ($tx['uniswap'] ?? ''),
        normalize_pool_id((string) ($tx['pool_id'] ?? '')),
        (string) ($tx['action'] ?? 'create'),
        (string) ($tx['date'] ?? db_now()),
        (string) ($tx['asset_1'] ?? ''),
        (string) ($tx['asset_2'] ?? ''),
        (float) ($tx['deposit_1'] ?? 0),
        (float) ($tx['deposit_2'] ?? 0),
        (float) ($tx['deposit_1_usd'] ?? 0),
        (float) ($tx['deposit_2_usd'] ?? 0),
        (float) ($tx['total'] ?? 0),
        (float) ($tx['fees'] ?? 0),
        $userId,
        $txId,
    ]);
    return $stmt->rowCount() > 0;
}

function store_get_sol_transaction_by_id(int $userId, int $txId): ?array
{
    $stmt = app_pdo()->prepare(
        'SELECT
            id, wallet, chain_name, transaction_url, uniswap_url, pool_id, action_name, tx_date,
            asset_1, asset_2, deposit_1, deposit_2, deposit_1_usd, deposit_2_usd, total, fees
         FROM sol_transactions
         WHERE user_id = ? AND id = ?'
    );
    $stmt->execute([$userId, $txId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'wallet' => (string) $row['wallet'],
        'chain' => (string) $row['chain_name'],
        'transaction' => (string) $row['transaction_url'],
        'uniswap' => (string) $row['uniswap_url'],
        'pool_id' => normalize_pool_id((string) $row['pool_id']),
        'action' => (string) $row['action_name'],
        'date' => (string) $row['tx_date'],
        'asset_1' => (string) $row['asset_1'],
        'asset_2' => (string) $row['asset_2'],
        'deposit_1' => (float) $row['deposit_1'],
        'deposit_2' => (float) $row['deposit_2'],
        'deposit_1_usd' => (float) $row['deposit_1_usd'],
        'deposit_2_usd' => (float) $row['deposit_2_usd'],
        'total' => (float) $row['total'],
        'fees' => (float) $row['fees'],
    ];
}

function store_replace_sol_transactions(int $userId, array $rows): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sol_transactions WHERE user_id = ?')->execute([$userId]);
        foreach ($rows as $row) {
            store_insert_sol_transaction($userId, $row);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_get_sol_pool_overrides(int $userId): array
{
    $stmt = app_pdo()->prepare('SELECT pool_id, current_1, current_2, unclaimed, total_usd_override, last_sync_at FROM sol_pool_overrides WHERE user_id = ?');
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $poolId = normalize_pool_id((string) $row['pool_id']);
        $out[$poolId] = [
            'current_1' => (float) $row['current_1'],
            'current_2' => (float) $row['current_2'],
            'unclaimed' => (float) $row['unclaimed'],
            'total_usd_override' => (float) ($row['total_usd_override'] ?? 0.0),
            'last_sync_at' => (string) ($row['last_sync_at'] ?? ''),
        ];
    }
    return $out;
}

function store_upsert_sol_pool_override(int $userId, string $poolId, float $current1, float $current2, float $unclaimed): void
{
    $poolId = normalize_pool_id($poolId);
    $stmt = app_pdo()->prepare(
        'INSERT INTO sol_pool_overrides (user_id, pool_id, current_1, current_2, unclaimed, total_usd_override) VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(user_id, pool_id) DO UPDATE SET
            current_1=excluded.current_1,
            current_2=excluded.current_2,
            unclaimed=excluded.unclaimed'
    );
    $stmt->execute([$userId, $poolId, $current1, $current2, $unclaimed, 0.0]);
}

function store_upsert_sol_pool_override_with_total(
    int $userId,
    string $poolId,
    float $current1,
    float $current2,
    float $unclaimed,
    float $totalUsdOverride,
    string $lastSyncAt = ''
): void
{
    $poolId = normalize_pool_id($poolId);
    $stmt = app_pdo()->prepare(
        'INSERT INTO sol_pool_overrides (user_id, pool_id, current_1, current_2, unclaimed, total_usd_override, last_sync_at) VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(user_id, pool_id) DO UPDATE SET
            current_1=excluded.current_1,
            current_2=excluded.current_2,
            unclaimed=excluded.unclaimed,
            total_usd_override=excluded.total_usd_override,
            last_sync_at=excluded.last_sync_at'
    );
    $stmt->execute([$userId, $poolId, $current1, $current2, $unclaimed, $totalUsdOverride, $lastSyncAt]);
}

function store_clear_sol_pool_overrides(int $userId): void
{
    app_pdo()->prepare('DELETE FROM sol_pool_overrides WHERE user_id = ?')->execute([$userId]);
}

function store_get_sol_pool_order(int $userId): array
{
    $stmt = app_pdo()->prepare('SELECT pool_id FROM sol_pool_order WHERE user_id = ? ORDER BY ord ASC');
    $stmt->execute([$userId]);
    return array_map(static fn($r) => normalize_pool_id((string) $r['pool_id']), $stmt->fetchAll());
}

function store_replace_sol_pool_order(int $userId, array $order): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sol_pool_order WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare('INSERT INTO sol_pool_order (user_id, pool_id, ord) VALUES (?, ?, ?)');
        foreach (array_values($order) as $idx => $poolId) {
            $ins->execute([$userId, normalize_pool_id((string) $poolId), $idx + 1]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_get_sol_token_targets(int $userId): array
{
    $stmt = app_pdo()->prepare('SELECT token, target_pct FROM sol_token_targets WHERE user_id = ? ORDER BY token ASC');
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $token = strtoupper(trim((string) ($row['token'] ?? '')));
        if ($token === '') {
            continue;
        }
        $out[$token] = (float) ($row['target_pct'] ?? 0.0);
    }
    return $out;
}

function store_upsert_sol_token_target(int $userId, string $token, float $targetPct): void
{
    $cleanToken = strtoupper(trim($token));
    $stmt = app_pdo()->prepare(
        'INSERT INTO sol_token_targets (user_id, token, target_pct, updated_at) VALUES (?, ?, ?, ?)
         ON CONFLICT(user_id, token) DO UPDATE SET target_pct=excluded.target_pct, updated_at=excluded.updated_at'
    );
    $stmt->execute([$userId, $cleanToken, $targetPct, db_now()]);
}

function store_get_sol_fee_snapshots(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT pool_id, snapshot_date, unclaimed_usd
         FROM sol_fee_snapshots
         WHERE user_id = ?
         ORDER BY snapshot_date ASC, pool_id ASC'
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'pool_id' => normalize_pool_id((string) $row['pool_id']),
            'snapshot_date' => (string) $row['snapshot_date'],
            'unclaimed_usd' => (float) $row['unclaimed_usd'],
        ];
    }
    return $out;
}

function store_upsert_sol_fee_snapshot(int $userId, string $poolId, string $snapshotDate, float $unclaimedUsd): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO sol_fee_snapshots (user_id, pool_id, snapshot_date, unclaimed_usd, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(user_id, pool_id, snapshot_date) DO UPDATE SET
            unclaimed_usd=excluded.unclaimed_usd,
            updated_at=excluded.updated_at'
    );
    $now = db_now();
    $stmt->execute([$userId, normalize_pool_id($poolId), $snapshotDate, $unclaimedUsd, $now, $now]);
}

function store_delete_sol_fee_snapshot(int $userId, string $poolId, string $snapshotDate): bool
{
    $stmt = app_pdo()->prepare(
        'DELETE FROM sol_fee_snapshots
         WHERE user_id = ? AND pool_id = ? AND snapshot_date = ?'
    );
    $stmt->execute([$userId, normalize_pool_id($poolId), $snapshotDate]);
    return $stmt->rowCount() > 0;
}

function store_get_sol_latest_unclaimed_by_pool(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT fs.pool_id, fs.unclaimed_usd
         FROM sol_fee_snapshots fs
         JOIN (
            SELECT pool_id, MAX(snapshot_date) AS max_date
            FROM sol_fee_snapshots
            WHERE user_id = ?
            GROUP BY pool_id
         ) t ON t.pool_id = fs.pool_id AND t.max_date = fs.snapshot_date
         WHERE fs.user_id = ?'
    );
    $stmt->execute([$userId, $userId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[normalize_pool_id((string) $row['pool_id'])] = (float) $row['unclaimed_usd'];
    }
    return $out;
}

function store_get_sol_latest_fee_snapshot_date(int $userId, string $poolId): ?string
{
    $stmt = app_pdo()->prepare(
        'SELECT MAX(snapshot_date) AS max_date
         FROM sol_fee_snapshots
         WHERE user_id = ? AND pool_id = ?'
    );
    $stmt->execute([$userId, normalize_pool_id($poolId)]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    $date = (string) ($row['max_date'] ?? '');
    return $date !== '' ? $date : null;
}

function store_get_sol_latest_unreverted_claim_fee_tx(int $userId, string $poolId): ?array
{
    $stmt = app_pdo()->prepare(
        "SELECT id, total, transaction_url
         FROM sol_transactions
         WHERE user_id = ? AND pool_id = ? AND action_name = 'fees'
           AND transaction_url IN ('internal:claim', 'internal:remove-fees')
         ORDER BY tx_date DESC, id DESC"
    );
    $stmt->execute([$userId, normalize_pool_id($poolId)]);
    $rows = $stmt->fetchAll();
    if (!is_array($rows) || count($rows) === 0) {
        return null;
    }

    $revertedAmount = 0.0;
    foreach ($rows as $row) {
        $kind = (string) ($row['transaction_url'] ?? '');
        $total = (float) ($row['total'] ?? 0.0);
        if ($kind === 'internal:remove-fees') {
            $revertedAmount += abs($total);
            continue;
        }
        if ($kind === 'internal:claim' && $total > 0.0) {
            if ($revertedAmount + 0.0000001 >= $total) {
                $revertedAmount -= $total;
                if ($revertedAmount < 0.0) {
                    $revertedAmount = 0.0;
                }
                continue;
            }
            return [
                'id' => (int) ($row['id'] ?? 0),
                'total' => $total,
            ];
        }
    }
    return null;
}

function store_upsert_sol_closed_pool(int $userId, array $row): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO sol_closed_pools (
            user_id, pool_id, asset_1, asset_2, chain_name, wallet,
            initial_total, total_now, unclaimed, claimed, roi, apr, days_open, closed_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(user_id, pool_id) DO UPDATE SET
            asset_1=excluded.asset_1,
            asset_2=excluded.asset_2,
            chain_name=excluded.chain_name,
            wallet=excluded.wallet,
            initial_total=excluded.initial_total,
            total_now=excluded.total_now,
            unclaimed=excluded.unclaimed,
            claimed=excluded.claimed,
            roi=excluded.roi,
            apr=excluded.apr,
            days_open=excluded.days_open,
            closed_at=excluded.closed_at'
    );

    $stmt->execute([
        $userId,
        normalize_pool_id((string) ($row['pool_id'] ?? '')),
        (string) ($row['asset_1'] ?? ''),
        (string) ($row['asset_2'] ?? ''),
        normalize_chain_label((string) ($row['chain'] ?? '')),
        normalize_wallet_label((string) ($row['wallet'] ?? '')),
        (float) ($row['initial_total'] ?? 0),
        (float) ($row['total_now'] ?? 0),
        (float) ($row['unclaimed'] ?? 0),
        (float) ($row['claimed'] ?? 0),
        (float) ($row['roi'] ?? 0),
        (float) ($row['apr'] ?? 0),
        (float) ($row['days_open'] ?? 0),
        (string) ($row['closed_at'] ?? db_now()),
    ]);
}

function store_get_sol_closed_pools(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT pool_id, asset_1, asset_2, chain_name, wallet, initial_total, total_now, unclaimed, claimed, roi, apr, days_open, closed_at
         FROM sol_closed_pools
         WHERE user_id = ?
         ORDER BY closed_at DESC'
    );
    $stmt->execute([$userId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[] = [
            'pool_id' => normalize_pool_id((string) $row['pool_id']),
            'asset_1' => (string) $row['asset_1'],
            'asset_2' => (string) $row['asset_2'],
            'chain' => (string) $row['chain_name'],
            'wallet' => (string) $row['wallet'],
            'initial_total' => (float) $row['initial_total'],
            'total_now' => (float) $row['total_now'],
            'unclaimed' => (float) $row['unclaimed'],
            'claimed' => (float) $row['claimed'],
            'roi' => (float) $row['roi'],
            'apr' => (float) $row['apr'],
            'days_open' => (float) $row['days_open'],
            'closed_at' => (string) $row['closed_at'],
        ];
    }
    return $out;
}

function store_get_sol_closed_pool_ids(int $userId): array
{
    $stmt = app_pdo()->prepare('SELECT pool_id FROM sol_closed_pools WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map(static fn($r) => normalize_pool_id((string) $r['pool_id']), $stmt->fetchAll());
}

function store_delete_sol_closed_pool(int $userId, string $poolId): bool
{
    $stmt = app_pdo()->prepare('DELETE FROM sol_closed_pools WHERE user_id = ? AND pool_id = ?');
    $stmt->execute([$userId, normalize_pool_id($poolId)]);
    return $stmt->rowCount() > 0;
}

function store_clear_sol_all(int $userId): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sol_transactions WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM sol_pool_overrides WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM sol_pool_order WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM sol_fee_snapshots WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM sol_closed_pools WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM sol_token_targets WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM sol_stakes WHERE user_id = ?')->execute([$userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_replace_sol_pool_overrides(int $userId, array $rows): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sol_pool_overrides WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare(
            'INSERT INTO sol_pool_overrides (user_id, pool_id, current_1, current_2, unclaimed, total_usd_override, last_sync_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
            if ($poolId === '') {
                continue;
            }
            $ins->execute([
                $userId,
                $poolId,
                max(0.0, (float) ($row['current_1'] ?? 0.0)),
                max(0.0, (float) ($row['current_2'] ?? 0.0)),
                max(0.0, (float) ($row['unclaimed'] ?? 0.0)),
                max(0.0, (float) ($row['total_usd_override'] ?? 0.0)),
                (string) ($row['last_sync_at'] ?? ''),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_replace_sol_fee_snapshots(int $userId, array $rows): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sol_fee_snapshots WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare(
            'INSERT INTO sol_fee_snapshots (user_id, pool_id, snapshot_date, unclaimed_usd, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $now = db_now();
        foreach ($rows as $row) {
            $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
            $snapshotDate = trim((string) ($row['snapshot_date'] ?? ''));
            if ($poolId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) {
                continue;
            }
            $ins->execute([
                $userId,
                $poolId,
                $snapshotDate,
                max(0.0, (float) ($row['unclaimed_usd'] ?? 0.0)),
                $now,
                $now,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_replace_sol_token_targets(int $userId, array $tokenTargets): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sol_token_targets WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare(
            'INSERT INTO sol_token_targets (user_id, token, target_pct, updated_at) VALUES (?, ?, ?, ?)'
        );
        $now = db_now();
        foreach ($tokenTargets as $token => $targetPct) {
            $cleanToken = strtoupper(trim((string) $token));
            if ($cleanToken === '') {
                continue;
            }
            $ins->execute([$userId, $cleanToken, (float) $targetPct, $now]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_replace_sol_closed_pools(int $userId, array $rows): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sol_closed_pools WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare(
            'INSERT INTO sol_closed_pools (
                user_id, pool_id, asset_1, asset_2, chain_name, wallet,
                initial_total, total_now, unclaimed, claimed, roi, apr, days_open, closed_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
            $asset1 = strtoupper(trim((string) ($row['asset_1'] ?? '')));
            $asset2 = strtoupper(trim((string) ($row['asset_2'] ?? '')));
            if ($poolId === '' || !is_valid_symbol($asset1) || !is_valid_symbol($asset2)) {
                continue;
            }
            $ins->execute([
                $userId,
                $poolId,
                $asset1,
                $asset2,
                normalize_chain_label((string) ($row['chain'] ?? '')),
                normalize_wallet_label((string) ($row['wallet'] ?? '')),
                (float) ($row['initial_total'] ?? 0.0),
                (float) ($row['total_now'] ?? 0.0),
                max(0.0, (float) ($row['unclaimed'] ?? 0.0)),
                max(0.0, (float) ($row['claimed'] ?? 0.0)),
                (float) ($row['roi'] ?? 0.0),
                (float) ($row['apr'] ?? 0.0),
                max(0.0, (float) ($row['days_open'] ?? 0.0)),
                normalize_datetime_input((string) ($row['closed_at'] ?? db_now())),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function store_get_sol_stakes(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT
            id, wallet, validator, token, amount_tokens, amount_usd, apy, rewards_usd,
            start_date, status, notes, created_at, updated_at
         FROM sol_stakes
         WHERE user_id = ?
         ORDER BY status ASC, start_date DESC, id DESC'
    );
    $stmt->execute([$userId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'wallet' => (string) $row['wallet'],
            'validator' => (string) $row['validator'],
            'token' => (string) $row['token'],
            'amount_tokens' => (float) $row['amount_tokens'],
            'amount_usd' => (float) $row['amount_usd'],
            'apy' => (float) $row['apy'],
            'rewards_usd' => (float) $row['rewards_usd'],
            'start_date' => (string) $row['start_date'],
            'status' => (string) $row['status'],
            'notes' => (string) $row['notes'],
        ];
    }
    return $rows;
}

function store_get_sol_stake_by_id(int $userId, int $stakeId): ?array
{
    $stmt = app_pdo()->prepare(
        'SELECT
            id, wallet, validator, token, amount_tokens, amount_usd, apy, rewards_usd,
            start_date, status, notes
         FROM sol_stakes
         WHERE user_id = ? AND id = ?'
    );
    $stmt->execute([$userId, $stakeId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'wallet' => (string) $row['wallet'],
        'validator' => (string) $row['validator'],
        'token' => (string) $row['token'],
        'amount_tokens' => (float) $row['amount_tokens'],
        'amount_usd' => (float) $row['amount_usd'],
        'apy' => (float) $row['apy'],
        'rewards_usd' => (float) $row['rewards_usd'],
        'start_date' => (string) $row['start_date'],
        'status' => (string) $row['status'],
        'notes' => (string) $row['notes'],
    ];
}

function store_insert_sol_stake(int $userId, array $row): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO sol_stakes (
            user_id, wallet, validator, token, amount_tokens, amount_usd, apy, rewards_usd,
            start_date, status, notes, created_at, updated_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $now = db_now();
    $stmt->execute([
        $userId,
        normalize_wallet_label((string) ($row['wallet'] ?? '')),
        (string) ($row['validator'] ?? ''),
        strtoupper((string) ($row['token'] ?? '')),
        (float) ($row['amount_tokens'] ?? 0.0),
        (float) ($row['amount_usd'] ?? 0.0),
        (float) ($row['apy'] ?? 0.0),
        (float) ($row['rewards_usd'] ?? 0.0),
        (string) ($row['start_date'] ?? db_now()),
        (string) ($row['status'] ?? 'active'),
        (string) ($row['notes'] ?? ''),
        $now,
        $now,
    ]);
}

function store_update_sol_stake(int $userId, int $stakeId, array $row): bool
{
    $stmt = app_pdo()->prepare(
        'UPDATE sol_stakes
         SET wallet = ?, validator = ?, token = ?, amount_tokens = ?, amount_usd = ?, apy = ?,
             rewards_usd = ?, start_date = ?, status = ?, notes = ?, updated_at = ?
         WHERE user_id = ? AND id = ?'
    );
    $stmt->execute([
        normalize_wallet_label((string) ($row['wallet'] ?? '')),
        (string) ($row['validator'] ?? ''),
        strtoupper((string) ($row['token'] ?? '')),
        (float) ($row['amount_tokens'] ?? 0.0),
        (float) ($row['amount_usd'] ?? 0.0),
        (float) ($row['apy'] ?? 0.0),
        (float) ($row['rewards_usd'] ?? 0.0),
        (string) ($row['start_date'] ?? db_now()),
        (string) ($row['status'] ?? 'active'),
        (string) ($row['notes'] ?? ''),
        db_now(),
        $userId,
        $stakeId,
    ]);
    return $stmt->rowCount() > 0;
}

function store_delete_sol_stake(int $userId, int $stakeId): bool
{
    $stmt = app_pdo()->prepare('DELETE FROM sol_stakes WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $stakeId]);
    return $stmt->rowCount() > 0;
}

function store_replace_sol_stakes(int $userId, array $rows): void
{
    $pdo = app_pdo();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sol_stakes WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare(
            'INSERT INTO sol_stakes (
                user_id, wallet, validator, token, amount_tokens, amount_usd, apy, rewards_usd,
                start_date, status, notes, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $ins->execute([
                $userId,
                normalize_wallet_label((string) ($row['wallet'] ?? '')),
                (string) ($row['validator'] ?? ''),
                strtoupper((string) ($row['token'] ?? '')),
                (float) ($row['amount_tokens'] ?? 0.0),
                (float) ($row['amount_usd'] ?? 0.0),
                (float) ($row['apy'] ?? 0.0),
                (float) ($row['rewards_usd'] ?? 0.0),
                (string) ($row['start_date'] ?? db_now()),
                (string) ($row['status'] ?? 'active'),
                (string) ($row['notes'] ?? ''),
                db_now(),
                db_now(),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}



