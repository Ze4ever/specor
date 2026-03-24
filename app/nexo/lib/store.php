<?php
function nexo_default_apy_for_token(string $token): float
{
    $tk = strtoupper(trim($token));
    if (in_array($tk, ['EUR', 'EURX', 'EURS', 'EURC'], true)) {
        return 7.25;
    }
    return 0.0;
}

function nexo_default_coingecko_id_for_token(string $token): string
{
    $map = [
        'NEXO' => 'nexo',
        'EURX' => 'stasis-eurs',
        'EURS' => 'stasis-eurs',
        'EURC' => 'euro-coin',
        'USDC' => 'usd-coin',
        'USDT' => 'tether',
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
    ];
    $tk = strtoupper(trim($token));
    return $map[$tk] ?? '';
}

function nexo_fetch_coingecko_price_usd(string $coingeckoId): ?float
{
    $id = trim($coingeckoId);
    if ($id === '') {
        return null;
    }
    $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode($id) . '&vs_currencies=usd';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => "User-Agent: Goal-Specor-NEXO/1.0\r\nAccept: application/json\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $usd = $decoded[$id]['usd'] ?? null;
    if (!is_numeric($usd)) {
        return null;
    }
    $price = (float) $usd;
    return $price > 0.0 ? $price : null;
}

function nexo_record_price_history(int $userId, string $token, string $priceDate, float $priceUsd): void
{
    $tk = strtoupper(trim($token));
    $date = substr(trim($priceDate), 0, 10);
    if ($tk === '' || $priceUsd <= 0.0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return;
    }
    $stmt = app_pdo()->prepare(
        'INSERT INTO nexo_price_history (user_id, token, price_date, price_usd, created_at)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(user_id, token, price_date) DO UPDATE SET
            price_usd=excluded.price_usd'
    );
    $stmt->execute([$userId, $tk, $date, $priceUsd, db_now()]);
}

function nexo_is_eur_like_token(string $token): bool
{
    $tk = strtoupper(trim($token));
    return in_array($tk, ['EUR', 'EURX', 'EURS', 'EURC'], true);
}

function nexo_calc_flexible_daily_rewards(
    float $principal,
    float $apy,
    string $token,
    float $tokenPriceUsd,
    float $eurUsdRate,
    float $nexoPriceUsd
): array {
    $principal = max(0.0, $principal);
    $apy = max(0.0, $apy);
    $nexoPriceUsd = max(0.000001, $nexoPriceUsd);

    if (nexo_is_eur_like_token($token)) {
        $eurRate = max(0.0, $tokenPriceUsd);
        if ($eurRate <= 0.0) {
            $eurRate = max(0.0, $eurUsdRate);
        }
        if ($eurRate <= 0.0) {
            return ['usd' => 0.0, 'nexo' => 0.0];
        }
        $dailyEur = ($principal * ($apy / 100.0)) / 365.0;
        $dailyUsd = $dailyEur * $eurRate;
        $dailyNexo = $dailyUsd / $nexoPriceUsd;
        return ['usd' => $dailyUsd, 'nexo' => $dailyNexo];
    }

    $principalUsd = $principal * max(0.0, $tokenPriceUsd);
    $dailyUsd = ($principalUsd * ($apy / 100.0)) / 365.0;
    $dailyNexo = $dailyUsd / $nexoPriceUsd;
    return ['usd' => $dailyUsd, 'nexo' => $dailyNexo];
}

function nexo_get_eur_usd_rate_for_day(int $userId, string $date, float $fallbackTodayRate = 0.0): float
{
    $d = substr(trim($date), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
        return max(0.0, $fallbackTodayRate);
    }
    $today = gmdate('Y-m-d');
    if ($d === $today && $fallbackTodayRate > 0.0) {
        return $fallbackTodayRate;
    }

    $rate = nexo_get_price_history_for_day($userId, 'EURC', $d);
    if (is_numeric($rate) && (float) $rate > 0.0) {
        return (float) $rate;
    }
    $rate = nexo_get_price_history_for_day($userId, 'EURS', $d);
    if (is_numeric($rate) && (float) $rate > 0.0) {
        return (float) $rate;
    }
    $rate = nexo_get_price_history_for_day($userId, 'EURX', $d);
    if (is_numeric($rate) && (float) $rate > 0.0) {
        return (float) $rate;
    }
    $rate = nexo_get_price_history_for_day($userId, 'EUR', $d);
    if (is_numeric($rate) && (float) $rate > 0.0) {
        return (float) $rate;
    }
    return 0.0;
}

function nexo_set_logs_paused(int $userId, bool $paused): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO nexo_log_state (user_id, pause_logs, updated_at)
         VALUES (?, ?, ?)
         ON CONFLICT(user_id) DO UPDATE SET pause_logs=excluded.pause_logs, updated_at=excluded.updated_at'
    );
    $stmt->execute([$userId, $paused ? 1 : 0, db_now()]);
}

function nexo_get_logs_paused(int $userId): bool
{
    $stmt = app_pdo()->prepare('SELECT pause_logs FROM nexo_log_state WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $val = $stmt->fetchColumn();
    return is_numeric($val) ? ((int) $val) === 1 : false;
}

function nexo_get_token_price_for_day(
    int $userId,
    string $token,
    string $priceDate,
    ?string $coingeckoId = null,
    float $fallbackEurUsd = 0.0,
    bool $allowFallback = false
): ?float {
    $tk = strtoupper(trim($token));
    $date = substr(trim($priceDate), 0, 10);
    if ($tk === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return null;
    }

    $today = gmdate('Y-m-d');
    if (nexo_is_eur_like_token($tk)) {
        if ($date === $today && $fallbackEurUsd > 0.0) {
            nexo_record_price_history($userId, $tk, $date, $fallbackEurUsd);
            return $fallbackEurUsd;
        }
        $hist = nexo_get_price_history_for_day($userId, $tk, $date);
        if ($hist !== null) {
            return $hist;
        }
        return $allowFallback ? nexo_get_latest_price_history_on_or_before($userId, $tk, $date) : null;
    }

    if ($date === $today) {
        $fresh = nexo_get_token_price_usd($userId, $tk, (string) ($coingeckoId ?? ''), 3600);
        if ($fresh !== null && $fresh > 0.0) {
            return $fresh;
        }
    }

    $hist = nexo_get_price_history_for_day($userId, $tk, $date);
    if ($hist !== null) {
        return $hist;
    }
    return $allowFallback ? nexo_get_latest_price_history_on_or_before($userId, $tk, $date) : null;
}

function nexo_delete_flexible_rewards_range(int $userId, string $startDate, string $endDate): int
{
    $start = substr(trim($startDate), 0, 10);
    $end = substr(trim($endDate), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
        return 0;
    }
    if ($end < $start) {
        [$start, $end] = [$end, $start];
    }
    $stmt = app_pdo()->prepare(
        'DELETE FROM nexo_flexible_daily_rewards
         WHERE user_id = ? AND reward_date >= ? AND reward_date <= ?'
    );
    $stmt->execute([$userId, $start, $end]);
    return $stmt->rowCount();
}

function nexo_delete_all_flexible_rewards(int $userId): int
{
    $stmt = app_pdo()->prepare('DELETE FROM nexo_flexible_daily_rewards WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

function nexo_generate_flexible_rewards_range(int $userId, string $startDate, string $endDate): int
{
    $start = substr(trim($startDate), 0, 10);
    $end = substr(trim($endDate), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
        return 0;
    }
    if ($end < $start) {
        [$start, $end] = [$end, $start];
    }

    $terms = nexo_get_flexible_terms($userId);
    if (count($terms) === 0) {
        return 0;
    }

    $ins = app_pdo()->prepare(
        'INSERT OR IGNORE INTO nexo_flexible_daily_rewards (user_id, flexible_term_id, reward_date, reward_usd, reward_nexo, nexo_price_usd, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $inserted = 0;

    foreach ($terms as $term) {
        $termId = (int) ($term['id'] ?? 0);
        if ($termId <= 0) {
            continue;
        }
        $startTs = strtotime((string) ($term['started_at'] ?? ''));
        if ($startTs === false) {
            continue;
        }
        $termStart = gmdate('Y-m-d', $startTs);
        $rangeStart = $termStart > $start ? $termStart : $start;
        if ($rangeStart > $end) {
            continue;
        }

        $principal = (float) ($term['principal'] ?? 0.0);
        $apy = max(0.0, (float) ($term['apy'] ?? 0.0));
        $termToken = (string) ($term['token'] ?? '');
        $termCgId = (string) ($term['coingecko_id'] ?? '');

        $currentTs = strtotime($rangeStart . ' 00:00:00 UTC');
        $endTs = strtotime($end . ' 00:00:00 UTC');
        if ($currentTs === false || $endTs === false) {
            continue;
        }
        while ($currentTs <= $endTs) {
            $day = gmdate('Y-m-d', $currentTs);
            $nexoPriceDay = nexo_get_price_history_for_day($userId, 'NEXO', $day);
            if ($nexoPriceDay === null || $nexoPriceDay <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            $tokenPriceDay = nexo_get_token_price_for_day($userId, $termToken, $day, $termCgId, 0.0);
            if ($tokenPriceDay === null || $tokenPriceDay <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            $eurRateDay = nexo_get_eur_usd_rate_for_day($userId, $day, 0.0);
            $calc = nexo_calc_flexible_daily_rewards($principal, $apy, $termToken, $tokenPriceDay, $eurRateDay, $nexoPriceDay);
            $dailyUsd = (float) ($calc['usd'] ?? 0.0);
            $dailyNexo = (float) ($calc['nexo'] ?? 0.0);
            if ($dailyUsd <= 0.0 || $dailyNexo <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            $ins->execute([
                $userId,
                $termId,
                $day,
                $dailyUsd,
                $dailyNexo,
                $nexoPriceDay,
                gmdate('Y-m-d H:i:s'),
            ]);
            $inserted += $ins->rowCount() > 0 ? 1 : 0;
            $currentTs += 86400;
        }
    }
    return $inserted;
}

function nexo_get_price_history_for_day(int $userId, string $token, string $priceDate): ?float
{
    $tk = strtoupper(trim($token));
    $date = substr(trim($priceDate), 0, 10);
    if ($tk === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return null;
    }
    $stmt = app_pdo()->prepare(
        'SELECT price_usd FROM nexo_price_history WHERE user_id = ? AND token = ? AND price_date = ? LIMIT 1'
    );
    $stmt->execute([$userId, $tk, $date]);
    $val = $stmt->fetchColumn();
    return is_numeric($val) ? (float) $val : null;
}

function nexo_get_price_history_map_for_dates(int $userId, string $token, array $dates): array
{
    $tk = strtoupper(trim($token));
    $cleanDates = [];
    foreach ($dates as $date) {
        $d = substr(trim((string) $date), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
            continue;
        }
        $cleanDates[$d] = true;
    }
    $dateList = array_keys($cleanDates);
    if ($tk === '' || count($dateList) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($dateList), '?'));
    $params = array_merge([$userId, $tk], $dateList);
    $stmt = app_pdo()->prepare(
        'SELECT price_date, price_usd
         FROM nexo_price_history
         WHERE user_id = ? AND token = ? AND price_date IN (' . $placeholders . ')'
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $d = (string) ($row['price_date'] ?? '');
        if ($d !== '') {
            $out[$d] = (float) ($row['price_usd'] ?? 0.0);
        }
    }
    return $out;
}

function nexo_get_latest_price_history_on_or_before(int $userId, string $token, string $priceDate): ?float
{
    $tk = strtoupper(trim($token));
    $date = substr(trim($priceDate), 0, 10);
    if ($tk === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return null;
    }
    $stmt = app_pdo()->prepare(
        'SELECT price_usd FROM nexo_price_history
         WHERE user_id = ? AND token = ? AND price_date <= ?
         ORDER BY price_date DESC
         LIMIT 1'
    );
    $stmt->execute([$userId, $tk, $date]);
    $val = $stmt->fetchColumn();
    return is_numeric($val) ? (float) $val : null;
}

function nexo_get_last_flexible_reward_date(int $userId, int $termId): ?string
{
    $stmt = app_pdo()->prepare(
        'SELECT MAX(reward_date) AS max_date
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ? AND flexible_term_id = ?'
    );
    $stmt->execute([$userId, $termId]);
    $val = $stmt->fetchColumn();
    $date = is_string($val) ? (string) $val : '';
    return $date !== '' ? $date : null;
}

function nexo_get_cached_price_usd(int $userId, string $token): ?float
{
    $stmt = app_pdo()->prepare('SELECT price_usd FROM nexo_price_cache WHERE user_id = ? AND token = ? LIMIT 1');
    $stmt->execute([$userId, strtoupper(trim($token))]);
    $val = $stmt->fetchColumn();
    return is_numeric($val) ? (float) $val : null;
}

function nexo_get_token_price_usd(int $userId, string $token, string $coingeckoId = '', int $cacheTtlSeconds = 21600, bool $ignoreManual = false): ?float
{
    $tk = strtoupper(trim($token));
    if ($tk === '') {
        return null;
    }
    $market = nexo_get_market_token($userId, $tk);
    if (!$ignoreManual && is_array($market) && (int) ($market['use_manual'] ?? 0) === 1) {
        $manual = (float) ($market['manual_price_usd'] ?? 0.0);
        if ($manual > 0.0) {
            nexo_record_price_history($userId, $tk, gmdate('Y-m-d'), $manual);
            return $manual;
        }
    }
    $id = trim($coingeckoId);
    if ($id === '' && is_array($market)) {
        $id = (string) ($market['coingecko_id'] ?? '');
    }
    if ($id === '') {
        $id = nexo_default_coingecko_id_for_token($tk);
    }
    if ($id === '') {
        return nexo_get_cached_price_usd($userId, $tk);
    }

    $stmt = app_pdo()->prepare('SELECT price_usd, fetched_at, coingecko_id FROM nexo_price_cache WHERE user_id = ? AND token = ? LIMIT 1');
    $stmt->execute([$userId, $tk]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $cachedId = trim((string) ($row['coingecko_id'] ?? ''));
        if ($id !== '' && $cachedId !== '' && $cachedId !== $id) {
            $row = null;
        }
    }
    if (is_array($row)) {
        $fetchedAtTs = strtotime((string) ($row['fetched_at'] ?? ''));
        if ($fetchedAtTs !== false && (time() - $fetchedAtTs) <= $cacheTtlSeconds) {
            return (float) ($row['price_usd'] ?? 0.0);
        }
    }

    $fresh = nexo_fetch_coingecko_price_usd($id);
    if ($fresh === null) {
        if (is_array($row) && is_numeric($row['price_usd'] ?? null)) {
            return (float) $row['price_usd'];
        }
        return null;
    }

    $upsert = app_pdo()->prepare(
        'INSERT INTO nexo_price_cache (user_id, token, coingecko_id, price_usd, fetched_at)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(user_id, token) DO UPDATE SET
            coingecko_id=excluded.coingecko_id,
            price_usd=excluded.price_usd,
            fetched_at=excluded.fetched_at'
    );
    $upsert->execute([$userId, $tk, $id, $fresh, gmdate('Y-m-d H:i:s')]);
    nexo_record_price_history($userId, $tk, gmdate('Y-m-d'), $fresh);
    return $fresh;
}

function nexo_upsert_price_cache(int $userId, string $token, string $coingeckoId, float $priceUsd, string $fetchedAt): void
{
    $tk = strtoupper(trim($token));
    if ($tk === '' || $priceUsd <= 0.0) {
        return;
    }
    $stmt = app_pdo()->prepare(
        'INSERT INTO nexo_price_cache (user_id, token, coingecko_id, price_usd, fetched_at)
         VALUES (?, ?, ?, ?, ?)
         ON CONFLICT(user_id, token) DO UPDATE SET
            coingecko_id=excluded.coingecko_id,
            price_usd=excluded.price_usd,
            fetched_at=excluded.fetched_at'
    );
    $stmt->execute([$userId, $tk, trim($coingeckoId), $priceUsd, $fetchedAt]);
    nexo_record_price_history($userId, $tk, substr($fetchedAt, 0, 10), $priceUsd);
}

function nexo_save_market_token(int $userId, string $token, string $coingeckoId, float $manualPriceUsd, bool $useManual): void
{
    $tk = strtoupper(trim($token));
    $id = trim($coingeckoId);
    $stmt = app_pdo()->prepare(
        'INSERT INTO nexo_market_tokens (user_id, token, coingecko_id, manual_price_usd, use_manual, active, updated_at)
         VALUES (?, ?, ?, ?, ?, 1, ?)
         ON CONFLICT(user_id, token) DO UPDATE SET
            coingecko_id=excluded.coingecko_id,
            manual_price_usd=excluded.manual_price_usd,
            use_manual=excluded.use_manual,
            active=1,
            updated_at=excluded.updated_at'
    );
    $stmt->execute([$userId, $tk, $id, max(0.0, $manualPriceUsd), $useManual ? 1 : 0, db_now()]);
}

function nexo_get_market_tokens(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT token, coingecko_id, manual_price_usd, use_manual, active, updated_at
         FROM nexo_market_tokens
         WHERE user_id = ? AND active = 1
         ORDER BY token ASC'
    );
    $stmt->execute([$userId]);
    return array_map(static function ($row): array {
        return [
            'token' => (string) ($row['token'] ?? ''),
            'coingecko_id' => (string) ($row['coingecko_id'] ?? ''),
            'manual_price_usd' => (float) ($row['manual_price_usd'] ?? 0.0),
            'use_manual' => (int) ($row['use_manual'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

function nexo_get_market_token(int $userId, string $token): ?array
{
    $stmt = app_pdo()->prepare(
        'SELECT token, coingecko_id, manual_price_usd, use_manual, active, updated_at
         FROM nexo_market_tokens
         WHERE user_id = ? AND token = ? AND active = 1
         LIMIT 1'
    );
    $stmt->execute([$userId, strtoupper(trim($token))]);
    $row = $stmt->fetch();
    return is_array($row) ? [
        'token' => (string) ($row['token'] ?? ''),
        'coingecko_id' => (string) ($row['coingecko_id'] ?? ''),
        'manual_price_usd' => (float) ($row['manual_price_usd'] ?? 0.0),
        'use_manual' => (int) ($row['use_manual'] ?? 0),
        'active' => (int) ($row['active'] ?? 0),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ] : null;
}

function nexo_deactivate_market_token(int $userId, string $token): bool
{
    $stmt = app_pdo()->prepare('UPDATE nexo_market_tokens SET active = 0, updated_at = ? WHERE user_id = ? AND token = ? AND active = 1');
    $stmt->execute([db_now(), $userId, strtoupper(trim($token))]);
    return $stmt->rowCount() > 0;
}

function nexo_delete_market_token(int $userId, string $token): bool
{
    $tk = strtoupper(trim($token));
    if ($tk === '') {
        return false;
    }
    $stmt = app_pdo()->prepare('DELETE FROM nexo_market_tokens WHERE user_id = ? AND token = ?');
    $stmt->execute([$userId, $tk]);
    $deleted = $stmt->rowCount() > 0;

    $stmt2 = app_pdo()->prepare('DELETE FROM nexo_price_cache WHERE user_id = ? AND token = ?');
    $stmt2->execute([$userId, $tk]);
    return $deleted;
}

function nexo_refresh_market_prices(int $userId): int
{
    $rows = nexo_get_market_tokens($userId);
    $updated = 0;
    foreach ($rows as $row) {
        if ((int) ($row['use_manual'] ?? 0) === 1) {
            continue;
        }
        $token = (string) ($row['token'] ?? '');
        $id = (string) ($row['coingecko_id'] ?? '');
        if ($token === '' || $id === '') {
            continue;
        }
        $price = nexo_get_token_price_usd($userId, $token, $id, 1);
        if ($price !== null && $price > 0.0) {
            $updated++;
        }
    }
    return $updated;
}

function nexo_seed_default_terms_if_empty(int $userId): void
{
    $marketCountStmt = app_pdo()->prepare('SELECT COUNT(*) FROM nexo_market_tokens WHERE user_id = ?');
    $marketCountStmt->execute([$userId]);
    $marketCount = (int) $marketCountStmt->fetchColumn();
    if ($marketCount === 0) {
        nexo_save_market_token($userId, 'EURX', 'stasis-eurs', 0.0, false);
        nexo_save_market_token($userId, 'NEXO', 'nexo', 0.0, false);
    }

    $flexCountStmt = app_pdo()->prepare('SELECT COUNT(*) FROM nexo_flexible_terms WHERE user_id = ?');
    $flexCountStmt->execute([$userId]);
    $flexCount = (int) $flexCountStmt->fetchColumn();
    if ($flexCount === 0) {
        $started = db_now();
        nexo_add_flexible_term($userId, 'EURX', 3848.2, 7.25, $started, 'stasis-eurs');
        nexo_add_transaction($userId, $started, 'flexible', 'add', 'EURX', 3848.2, 'TOKEN', 7.25, 0, 'seed_default');
    }

    $fixedCountStmt = app_pdo()->prepare('SELECT COUNT(*) FROM nexo_fixed_terms WHERE user_id = ?');
    $fixedCountStmt->execute([$userId]);
    $fixedCount = (int) $fixedCountStmt->fetchColumn();
    if ($fixedCount === 0) {
        $started = db_now();
        nexo_add_fixed_term($userId, 'NEXO', 270.0, 11.0, 12, $started);
        nexo_add_transaction($userId, $started, 'fixed', 'add', 'NEXO', 270.0, 'NEXO', 11.0, 12, 'seed_default');
        nexo_add_fixed_term($userId, 'NEXO', 236.0, 11.0, 12, $started);
        nexo_add_transaction($userId, $started, 'fixed', 'add', 'NEXO', 236.0, 'NEXO', 11.0, 12, 'seed_default');
    }
}

function nexo_get_wallet_state(int $userId): array
{
    $stmt = app_pdo()->prepare('SELECT eurx_eur, nexo_tokens, eur_usd_rate, nexo_usd_price, updated_at FROM nexo_wallet_state WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        return [
            'eurx_eur' => (float) ($row['eurx_eur'] ?? 0.0),
            'nexo_tokens' => (float) ($row['nexo_tokens'] ?? 0.0),
            'eur_usd_rate' => (float) ($row['eur_usd_rate'] ?? 1.0),
            'nexo_usd_price' => (float) ($row['nexo_usd_price'] ?? 1.0),
            'updated_at' => (string) ($row['updated_at'] ?? db_now()),
        ];
    }

    $defaults = [
        'eurx_eur' => 3848.2,
        'nexo_tokens' => 645.39,
        'eur_usd_rate' => 1.09,
        'nexo_usd_price' => 1.0,
        'updated_at' => db_now(),
    ];
    nexo_save_wallet_state(
        $userId,
        $defaults['eurx_eur'],
        $defaults['nexo_tokens'],
        $defaults['eur_usd_rate'],
        $defaults['nexo_usd_price']
    );
    return $defaults;
}

function nexo_save_wallet_state(int $userId, float $eurxEur, float $nexoTokens, float $eurUsdRate, float $nexoUsdPrice): void
{
    $stmt = app_pdo()->prepare(
        'INSERT INTO nexo_wallet_state (user_id, eurx_eur, nexo_tokens, eur_usd_rate, nexo_usd_price, updated_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT(user_id) DO UPDATE SET
            eurx_eur=excluded.eurx_eur,
            nexo_tokens=excluded.nexo_tokens,
            eur_usd_rate=excluded.eur_usd_rate,
            nexo_usd_price=excluded.nexo_usd_price,
            updated_at=excluded.updated_at'
    );
    $stmt->execute([
        $userId,
        max(0.0, $eurxEur),
        max(0.0, $nexoTokens),
        max(0.000001, $eurUsdRate),
        max(0.0, $nexoUsdPrice),
        db_now(),
    ]);
}

function nexo_get_flexible_terms(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT id, token, coingecko_id, principal, principal_usd, currency, apy, started_at, active
         FROM nexo_flexible_terms
         WHERE user_id = ? AND active = 1
         ORDER BY id DESC'
    );
    $stmt->execute([$userId]);
    return array_map(static function ($row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'token' => (string) ($row['token'] ?? ''),
            'coingecko_id' => (string) ($row['coingecko_id'] ?? ''),
            'principal' => (float) ($row['principal'] ?? 0.0),
            'principal_usd' => (float) ($row['principal_usd'] ?? 0.0),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'apy' => (float) ($row['apy'] ?? 0.0),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'active' => (int) ($row['active'] ?? 0),
        ];
    }, $stmt->fetchAll());
}

function nexo_get_inactive_flexible_terms(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT id, token, coingecko_id, principal, principal_usd, currency, apy, started_at, active, updated_at
         FROM nexo_flexible_terms
         WHERE user_id = ? AND active = 0
         ORDER BY updated_at DESC, id DESC'
    );
    $stmt->execute([$userId]);
    return array_map(static function ($row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'token' => (string) ($row['token'] ?? ''),
            'coingecko_id' => (string) ($row['coingecko_id'] ?? ''),
            'principal' => (float) ($row['principal'] ?? 0.0),
            'principal_usd' => (float) ($row['principal_usd'] ?? 0.0),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'apy' => (float) ($row['apy'] ?? 0.0),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'active' => (int) ($row['active'] ?? 0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

function nexo_get_finalized_terms(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT id, term_id, token, principal, apy, started_at, finalized_at, days_count, total_usd, total_nexo
         FROM nexo_flexible_finalized
         WHERE user_id = ?
         ORDER BY finalized_at DESC, id DESC'
    );
    $stmt->execute([$userId]);
    return array_map(static function ($row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'term_id' => (int) ($row['term_id'] ?? 0),
            'token' => (string) ($row['token'] ?? ''),
            'principal' => (float) ($row['principal'] ?? 0.0),
            'apy' => (float) ($row['apy'] ?? 0.0),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'finalized_at' => (string) ($row['finalized_at'] ?? ''),
            'days_count' => (int) ($row['days_count'] ?? 0),
            'total_usd' => (float) ($row['total_usd'] ?? 0.0),
            'total_nexo' => (float) ($row['total_nexo'] ?? 0.0),
        ];
    }, $stmt->fetchAll());
}

function nexo_get_finalized_logs_by_term(int $userId, array $termIds): array
{
    $clean = array_values(array_unique(array_filter(array_map(static fn($v) => (int) $v, $termIds), static fn($v) => $v > 0)));
    if (count($clean) === 0) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $params = array_merge([$userId], $clean);
    $stmt = app_pdo()->prepare(
        'SELECT term_id, reward_date, reward_usd, reward_nexo, nexo_price_usd
         FROM nexo_flexible_finalized_logs
         WHERE user_id = ? AND term_id IN (' . $placeholders . ')
         ORDER BY reward_date DESC, id DESC'
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $tid = (int) ($row['term_id'] ?? 0);
        if ($tid <= 0) {
            continue;
        }
        $out[$tid][] = [
            'reward_date' => (string) ($row['reward_date'] ?? ''),
            'reward_usd' => (float) ($row['reward_usd'] ?? 0.0),
            'reward_nexo' => (float) ($row['reward_nexo'] ?? 0.0),
            'nexo_price_usd' => (float) ($row['nexo_price_usd'] ?? 0.0),
        ];
    }
    return $out;
}

function nexo_delete_finalized_term(int $userId, int $termId): bool
{
    $stmt = app_pdo()->prepare('DELETE FROM nexo_flexible_finalized_logs WHERE user_id = ? AND term_id = ?');
    $stmt->execute([$userId, $termId]);
    $stmt2 = app_pdo()->prepare('DELETE FROM nexo_flexible_finalized WHERE user_id = ? AND term_id = ?');
    $stmt2->execute([$userId, $termId]);
    return $stmt2->rowCount() > 0;
}

function nexo_finalize_flexible_term_full(int $userId, int $termId): bool
{
    if ($termId <= 0) {
        return false;
    }
    $stmt = app_pdo()->prepare(
        'SELECT id, token, principal, apy, started_at
         FROM nexo_flexible_terms
         WHERE user_id = ? AND id = ?'
    );
    $stmt->execute([$userId, $termId]);
    $term = $stmt->fetch();
    if (!is_array($term)) {
        return false;
    }

    $aggMap = nexo_get_flexible_rewards_by_term_ids($userId, [$termId]);
    $agg = (array) ($aggMap[$termId] ?? []);
    $days = (int) ($agg['days_count'] ?? 0);
    $totalUsd = (float) ($agg['total_usd'] ?? 0.0);
    $totalNexo = (float) ($agg['total_nexo'] ?? 0.0);

    $finalizedAt = db_now();
    $ins = app_pdo()->prepare(
        'INSERT INTO nexo_flexible_finalized (user_id, term_id, token, principal, apy, started_at, finalized_at, days_count, total_usd, total_nexo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $userId,
        $termId,
        (string) ($term['token'] ?? ''),
        (float) ($term['principal'] ?? 0.0),
        (float) ($term['apy'] ?? 0.0),
        (string) ($term['started_at'] ?? ''),
        $finalizedAt,
        $days,
        $totalUsd,
        $totalNexo,
    ]);

    $logsStmt = app_pdo()->prepare(
        'SELECT reward_date, reward_usd, reward_nexo, nexo_price_usd
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ? AND flexible_term_id = ?
         ORDER BY reward_date ASC, id ASC'
    );
    $logsStmt->execute([$userId, $termId]);
    $logs = $logsStmt->fetchAll();

    $logIns = app_pdo()->prepare(
        'INSERT INTO nexo_flexible_finalized_logs (user_id, term_id, reward_date, reward_usd, reward_nexo, nexo_price_usd)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    foreach ($logs as $row) {
        $logIns->execute([
            $userId,
            $termId,
            (string) ($row['reward_date'] ?? ''),
            (float) ($row['reward_usd'] ?? 0.0),
            (float) ($row['reward_nexo'] ?? 0.0),
            (float) ($row['nexo_price_usd'] ?? 0.0),
        ]);
    }

    $del = app_pdo()->prepare('DELETE FROM nexo_flexible_daily_rewards WHERE user_id = ? AND flexible_term_id = ?');
    $del->execute([$userId, $termId]);

    nexo_deactivate_flexible_term($userId, $termId);
    return true;
}

function nexo_get_flexible_rewards_by_term_ids(int $userId, array $termIds): array
{
    $clean = array_values(array_unique(array_filter(array_map(static fn($v) => (int) $v, $termIds), static fn($v) => $v > 0)));
    if (count($clean) === 0) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $params = array_merge([$userId], $clean);
    $stmt = app_pdo()->prepare(
        'SELECT flexible_term_id, COUNT(*) AS days_count, SUM(reward_usd) AS total_usd, SUM(reward_nexo) AS total_nexo, MAX(reward_date) AS last_day
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ? AND flexible_term_id IN (' . $placeholders . ')
         GROUP BY flexible_term_id'
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['flexible_term_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[$id] = [
            'days_count' => (int) ($row['days_count'] ?? 0),
            'total_usd' => (float) ($row['total_usd'] ?? 0.0),
            'total_nexo' => (float) ($row['total_nexo'] ?? 0.0),
            'last_day' => (string) ($row['last_day'] ?? ''),
        ];
    }
    return $out;
}

function nexo_get_flexible_rewards_rows_by_term_ids(int $userId, array $termIds): array
{
    $clean = array_values(array_unique(array_filter(array_map(static fn($v) => (int) $v, $termIds), static fn($v) => $v > 0)));
    if (count($clean) === 0) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($clean), '?'));
    $params = array_merge([$userId], $clean);
    $stmt = app_pdo()->prepare(
        'SELECT id, flexible_term_id, reward_date, reward_usd, reward_nexo, nexo_price_usd
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ? AND flexible_term_id IN (' . $placeholders . ')
         ORDER BY reward_date DESC, id DESC'
    );
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $termId = (int) ($row['flexible_term_id'] ?? 0);
        if ($termId <= 0) {
            continue;
        }
        $out[$termId][] = [
            'id' => (int) ($row['id'] ?? 0),
            'reward_date' => (string) ($row['reward_date'] ?? ''),
            'reward_usd' => (float) ($row['reward_usd'] ?? 0.0),
            'reward_nexo' => (float) ($row['reward_nexo'] ?? 0.0),
            'nexo_price_usd' => (float) ($row['nexo_price_usd'] ?? 0.0),
        ];
    }
    return $out;
}
function nexo_get_flexible_min_start_date(int $userId): ?string
{
    $stmt = app_pdo()->prepare(
        'SELECT MIN(started_at) AS min_start
         FROM nexo_flexible_terms
         WHERE user_id = ? AND active = 1'
    );
    $stmt->execute([$userId]);
    $val = $stmt->fetchColumn();
    $date = substr((string) $val, 0, 10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null;
}

function nexo_add_flexible_term(
    int $userId,
    string $token,
    float $principal,
    float $apy,
    string $startedAt,
    string $coingeckoId = ''
): int {
    $now = db_now();
    $tk = strtoupper(trim($token));
    $normalizedApy = $apy > 0.0 ? $apy : nexo_default_apy_for_token($tk);
    $cgId = trim($coingeckoId);
    if ($cgId === '') {
        $cgId = nexo_default_coingecko_id_for_token($tk);
    }
    $priceUsd = nexo_get_token_price_usd($userId, $tk, $cgId);
    if ($priceUsd === null || $priceUsd <= 0.0) {
        if (in_array($tk, ['EUR', 'EURX', 'EURS', 'EURC'], true)) {
            $wallet = nexo_get_wallet_state($userId);
            $priceUsd = max(0.000001, (float) ($wallet['eur_usd_rate'] ?? 1.0));
        } else {
            $priceUsd = 0.0;
        }
    }
    $principalUsd = max(0.0, $principal) * max(0.0, (float) $priceUsd);
    $stmt = app_pdo()->prepare(
        'INSERT INTO nexo_flexible_terms (user_id, token, coingecko_id, principal, principal_usd, currency, apy, started_at, active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $tk,
        $cgId,
        max(0.0, $principal),
        $principalUsd,
        'TOKEN',
        max(0.0, $normalizedApy),
        $startedAt,
        $now,
        $now,
    ]);
    return (int) app_pdo()->lastInsertId();
}

function nexo_deactivate_flexible_term(int $userId, int $id): bool
{
    $stmt = app_pdo()->prepare('UPDATE nexo_flexible_terms SET active = 0, updated_at = ? WHERE user_id = ? AND id = ? AND active = 1');
    $stmt->execute([db_now(), $userId, $id]);
    return $stmt->rowCount() > 0;
}

function nexo_generate_flexible_daily_rewards(int $userId, float $nexoUsdPrice): int
{
    $terms = nexo_get_flexible_terms($userId);
    if (count($terms) === 0) {
        return 0;
    }
    $nexoPrice = $nexoUsdPrice > 0.0 ? $nexoUsdPrice : (nexo_get_token_price_usd($userId, 'NEXO', 'nexo') ?? 1.0);
    if ($nexoPrice <= 0.0) {
        $nexoPrice = 1.0;
    }

    $wallet = nexo_get_wallet_state($userId);
    $eurUsdRate = max(0.000001, (float) ($wallet['eur_usd_rate'] ?? 1.0));
    $todayUtc = gmdate('Y-m-d');
    if ($nexoPrice > 0.0) {
        nexo_record_price_history($userId, 'NEXO', $todayUtc, $nexoPrice);
    }

    $inserted = 0;
    $ins = app_pdo()->prepare(
        'INSERT OR IGNORE INTO nexo_flexible_daily_rewards (user_id, flexible_term_id, reward_date, reward_usd, reward_nexo, nexo_price_usd, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($terms as $term) {
        $termId = (int) ($term['id'] ?? 0);
        if ($termId <= 0) {
            continue;
        }
        $startTs = strtotime((string) ($term['started_at'] ?? ''));
        if ($startTs === false) {
            continue;
        }
        $startDateUtc = gmdate('Y-m-d', $startTs);

        $principal = (float) ($term['principal'] ?? 0.0);
        $apy = max(0.0, (float) ($term['apy'] ?? 0.0));
        $termToken = (string) ($term['token'] ?? '');
        $termCgId = (string) ($term['coingecko_id'] ?? '');
        $lastRewardDate = nexo_get_last_flexible_reward_date($userId, $termId);
        if ($lastRewardDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastRewardDate) === 1) {
            $lastTs = strtotime($lastRewardDate . ' 00:00:00 UTC');
            if ($lastTs !== false) {
                $startDateUtc = gmdate('Y-m-d', $lastTs + 86400);
            }
        }

        $currentTs = strtotime($startDateUtc . ' 00:00:00 UTC');
        $endTs = strtotime($todayUtc . ' 00:00:00 UTC');
        if ($currentTs === false || $endTs === false) {
            continue;
        }
        while ($currentTs <= $endTs) {
            $rewardDate = gmdate('Y-m-d', $currentTs);
            $priceForDay = null;
            if ($rewardDate === $todayUtc) {
                $priceForDay = $nexoPrice;
            } else {
                $priceForDay = nexo_get_price_history_for_day($userId, 'NEXO', $rewardDate);
            }
            if ($priceForDay === null || $priceForDay <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            nexo_record_price_history($userId, 'NEXO', $rewardDate, $priceForDay);

            $tokenPriceDay = nexo_get_token_price_for_day($userId, $termToken, $rewardDate, $termCgId, $eurUsdRate);
            if ($tokenPriceDay === null || $tokenPriceDay <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            $eurRateDay = nexo_get_eur_usd_rate_for_day($userId, $rewardDate, $eurUsdRate);
            $calc = nexo_calc_flexible_daily_rewards($principal, $apy, $termToken, $tokenPriceDay, $eurRateDay, $priceForDay);
            $dailyUsd = (float) ($calc['usd'] ?? 0.0);
            $dailyNexo = (float) ($calc['nexo'] ?? 0.0);
            if ($dailyUsd <= 0.0 || $dailyNexo <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            $ins->execute([
                $userId,
                $termId,
                $rewardDate,
                $dailyUsd,
                $dailyNexo,
                $priceForDay,
                gmdate('Y-m-d H:i:s'),
            ]);
            $inserted += $ins->rowCount() > 0 ? 1 : 0;
            $currentTs += 86400;
        }
    }
    return $inserted;
}

function nexo_get_flexible_rewards_by_term(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT flexible_term_id, COUNT(*) AS days_count, SUM(reward_usd) AS total_usd, SUM(reward_nexo) AS total_nexo, MAX(reward_date) AS last_day
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ?
         GROUP BY flexible_term_id'
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['flexible_term_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[$id] = [
            'days_count' => (int) ($row['days_count'] ?? 0),
            'total_usd' => (float) ($row['total_usd'] ?? 0.0),
            'total_nexo' => (float) ($row['total_nexo'] ?? 0.0),
            'last_day' => (string) ($row['last_day'] ?? ''),
        ];
    }
    return $out;
}

function nexo_get_recent_flexible_rewards(int $userId, int $limit = 60): array
{
    $safeLimit = max(1, min(365, $limit));
    $stmt = app_pdo()->prepare(
        'SELECT r.id, r.reward_date, r.reward_usd, r.reward_nexo, r.nexo_price_usd, t.token, t.id AS term_id
         FROM nexo_flexible_daily_rewards r
         JOIN nexo_flexible_terms t ON t.id = r.flexible_term_id
         WHERE r.user_id = ?
         ORDER BY r.reward_date DESC, r.id DESC
         LIMIT ' . $safeLimit
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    $dates = [];
    foreach ($rows as $row) {
        $dates[] = (string) ($row['reward_date'] ?? '');
    }
    $eurMap = nexo_get_price_history_map_for_dates($userId, 'EUR', $dates);
    $eurxMap = nexo_get_price_history_map_for_dates($userId, 'EURX', $dates);
    $eurcMap = nexo_get_price_history_map_for_dates($userId, 'EURC', $dates);
    $eursMap = nexo_get_price_history_map_for_dates($userId, 'EURS', $dates);

    return array_map(static function ($row) use ($eurMap, $eurxMap, $eurcMap, $eursMap): array {
        $date = (string) ($row['reward_date'] ?? '');
        $token = (string) ($row['token'] ?? '');
        $eurRate = (float) ($eurMap[$date] ?? 0.0);
        if ($eurRate <= 0.0) {
            $eurRate = (float) ($eurxMap[$date] ?? $eurcMap[$date] ?? $eursMap[$date] ?? 0.0);
        }
        $usd = (float) ($row['reward_usd'] ?? 0.0);
        $dailyEur = (nexo_is_eur_like_token($token) && $eurRate > 0.0) ? ($usd / $eurRate) : 0.0;
        return [
            'id' => (int) ($row['id'] ?? 0),
            'reward_date' => $date,
            'reward_usd' => $usd,
            'reward_nexo' => (float) ($row['reward_nexo'] ?? 0.0),
            'nexo_price_usd' => (float) ($row['nexo_price_usd'] ?? 0.0),
            'eur_usd_rate' => $eurRate,
            'daily_eur' => $dailyEur,
            'token' => $token,
            'term_id' => (int) ($row['term_id'] ?? 0),
        ];
    }, $rows);
}

function nexo_delete_flexible_reward(int $userId, int $rewardId): bool
{
    if ($rewardId <= 0) {
        return false;
    }
    $stmt = app_pdo()->prepare(
        'DELETE FROM nexo_flexible_daily_rewards WHERE user_id = ? AND id = ?'
    );
    $stmt->execute([$userId, $rewardId]);
    return $stmt->rowCount() > 0;
}

function nexo_delete_fixed_reward(int $userId, int $rewardId): bool
{
    if ($rewardId <= 0) {
        return false;
    }
    $stmt = app_pdo()->prepare(
        'DELETE FROM nexo_fixed_daily_rewards WHERE user_id = ? AND id = ?'
    );
    $stmt->execute([$userId, $rewardId]);
    return $stmt->rowCount() > 0;
}

function nexo_get_flexible_reward_stats(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT
            COUNT(*) AS total_rows,
            SUM(reward_usd) AS total_usd,
            SUM(reward_nexo) AS total_nexo,
            MAX(reward_date) AS last_day
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];

    $stmt30 = app_pdo()->prepare(
        "SELECT AVG(nexo_price_usd) AS avg_price_30d
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ? AND reward_date >= date('now','-30 day')"
    );
    $stmt30->execute([$userId]);
    $row30 = $stmt30->fetch() ?: [];

    return [
        'total_rows' => (int) ($row['total_rows'] ?? 0),
        'total_usd' => (float) ($row['total_usd'] ?? 0.0),
        'total_nexo' => (float) ($row['total_nexo'] ?? 0.0),
        'last_day' => (string) ($row['last_day'] ?? ''),
        'avg_price_30d' => (float) ($row30['avg_price_30d'] ?? 0.0),
    ];
}

function nexo_get_last_fixed_reward_date(int $userId, int $termId): ?string
{
    $stmt = app_pdo()->prepare(
        'SELECT MAX(reward_date) AS max_date
         FROM nexo_fixed_daily_rewards
         WHERE user_id = ? AND fixed_term_id = ?'
    );
    $stmt->execute([$userId, $termId]);
    $val = $stmt->fetchColumn();
    $date = is_string($val) ? (string) $val : '';
    return $date !== '' ? $date : null;
}

function nexo_generate_fixed_daily_rewards(int $userId, float $nexoUsdPrice): int
{
    $terms = nexo_get_fixed_terms($userId);
    if (count($terms) === 0) {
        return 0;
    }

    $nexoPrice = $nexoUsdPrice > 0.0 ? $nexoUsdPrice : (nexo_get_token_price_usd($userId, 'NEXO', 'nexo') ?? 1.0);
    if ($nexoPrice <= 0.0) {
        $nexoPrice = 1.0;
    }

    $wallet = nexo_get_wallet_state($userId);
    $eurUsdRate = max(0.000001, (float) ($wallet['eur_usd_rate'] ?? 1.0));
    $todayUtc = gmdate('Y-m-d');
    if ($nexoPrice > 0.0) {
        nexo_record_price_history($userId, 'NEXO', $todayUtc, $nexoPrice);
    }

    $inserted = 0;
    $ins = app_pdo()->prepare(
        'INSERT OR IGNORE INTO nexo_fixed_daily_rewards (user_id, fixed_term_id, reward_date, reward_usd, reward_nexo, nexo_price_usd, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($terms as $term) {
        $termId = (int) ($term['id'] ?? 0);
        if ($termId <= 0) {
            continue;
        }
        $startTs = strtotime((string) ($term['started_at'] ?? ''));
        if ($startTs === false) {
            continue;
        }
        $startDateUtc = gmdate('Y-m-d', $startTs);
        $termMonths = max(1, (int) ($term['term_months'] ?? 12));
        $termEndDate = $startDateUtc;
        try {
            $termEndDate = (new DateTimeImmutable($startDateUtc))->modify('+' . $termMonths . ' months')->format('Y-m-d');
        } catch (Throwable $e) {
            $termEndDate = $startDateUtc;
        }
        $lastRewardDate = nexo_get_last_fixed_reward_date($userId, $termId);
        if ($lastRewardDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastRewardDate) === 1) {
            $lastTs = strtotime($lastRewardDate . ' 00:00:00 UTC');
            if ($lastTs !== false) {
                $startDateUtc = gmdate('Y-m-d', $lastTs + 86400);
            }
        }

        $principal = (float) ($term['principal_tokens'] ?? 0.0);
        $apy = max(0.0, (float) ($term['apy'] ?? 0.0));
        $termToken = (string) ($term['token'] ?? '');
        $termCgId = nexo_default_coingecko_id_for_token($termToken);

        $currentTs = strtotime($startDateUtc . ' 00:00:00 UTC');
        $endBound = $todayUtc;
        if ($termEndDate !== '' && $termEndDate < $endBound) {
            $endBound = $termEndDate;
        }
        $endTs = strtotime($endBound . ' 00:00:00 UTC');
        if ($currentTs === false || $endTs === false) {
            continue;
        }
        while ($currentTs <= $endTs) {
            $rewardDate = gmdate('Y-m-d', $currentTs);
            $priceForDay = null;
            if ($rewardDate === $todayUtc) {
                $priceForDay = $nexoPrice;
            } else {
                $priceForDay = nexo_get_price_history_for_day($userId, 'NEXO', $rewardDate);
            }
            if ($priceForDay === null || $priceForDay <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            nexo_record_price_history($userId, 'NEXO', $rewardDate, $priceForDay);

            $tokenPriceDay = nexo_get_token_price_for_day($userId, $termToken, $rewardDate, $termCgId, $eurUsdRate);
            if ($tokenPriceDay === null || $tokenPriceDay <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            $eurRateDay = nexo_get_eur_usd_rate_for_day($userId, $rewardDate, $eurUsdRate);
            $calc = nexo_calc_flexible_daily_rewards($principal, $apy, $termToken, $tokenPriceDay, $eurRateDay, $priceForDay);
            $dailyUsd = (float) ($calc['usd'] ?? 0.0);
            $dailyNexo = (float) ($calc['nexo'] ?? 0.0);
            if ($dailyUsd <= 0.0 || $dailyNexo <= 0.0) {
                $currentTs += 86400;
                continue;
            }
            $ins->execute([
                $userId,
                $termId,
                $rewardDate,
                $dailyUsd,
                $dailyNexo,
                $priceForDay,
                gmdate('Y-m-d H:i:s'),
            ]);
            $inserted += $ins->rowCount() > 0 ? 1 : 0;
            $currentTs += 86400;
        }
    }
    return $inserted;
}

function nexo_get_fixed_rewards_by_term(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT fixed_term_id, COUNT(*) AS days_count, SUM(reward_usd) AS total_usd, SUM(reward_nexo) AS total_nexo, MAX(reward_date) AS last_day
         FROM nexo_fixed_daily_rewards
         WHERE user_id = ?
         GROUP BY fixed_term_id'
    );
    $stmt->execute([$userId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['fixed_term_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[$id] = [
            'days_count' => (int) ($row['days_count'] ?? 0),
            'total_usd' => (float) ($row['total_usd'] ?? 0.0),
            'total_nexo' => (float) ($row['total_nexo'] ?? 0.0),
            'last_day' => (string) ($row['last_day'] ?? ''),
        ];
    }
    return $out;
}

function nexo_get_recent_fixed_rewards(int $userId, int $limit = 60): array
{
    $safeLimit = max(1, min(365, $limit));
    $stmt = app_pdo()->prepare(
        'SELECT r.id, r.reward_date, r.reward_usd, r.reward_nexo, r.nexo_price_usd, t.token, t.id AS term_id
         FROM nexo_fixed_daily_rewards r
         JOIN nexo_fixed_terms t ON t.id = r.fixed_term_id
         WHERE r.user_id = ?
         ORDER BY r.reward_date DESC, r.id DESC
         LIMIT ' . $safeLimit
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();
    return array_map(static function ($row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'reward_date' => (string) ($row['reward_date'] ?? ''),
            'reward_usd' => (float) ($row['reward_usd'] ?? 0.0),
            'reward_nexo' => (float) ($row['reward_nexo'] ?? 0.0),
            'nexo_price_usd' => (float) ($row['nexo_price_usd'] ?? 0.0),
            'token' => (string) ($row['token'] ?? ''),
            'term_id' => (int) ($row['term_id'] ?? 0),
        ];
    }, $rows);
}

function nexo_get_fixed_reward_stats(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT
            COUNT(*) AS total_rows,
            SUM(reward_usd) AS total_usd,
            SUM(reward_nexo) AS total_nexo,
            MAX(reward_date) AS last_day
         FROM nexo_fixed_daily_rewards
         WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];

    return [
        'total_rows' => (int) ($row['total_rows'] ?? 0),
        'total_usd' => (float) ($row['total_usd'] ?? 0.0),
        'total_nexo' => (float) ($row['total_nexo'] ?? 0.0),
        'last_day' => (string) ($row['last_day'] ?? ''),
    ];
}

function nexo_get_fixed_terms(int $userId): array
{
    $stmt = app_pdo()->prepare(
        'SELECT id, token, principal_tokens, apy, term_months, started_at, active
         FROM nexo_fixed_terms
         WHERE user_id = ? AND active = 1
         ORDER BY id DESC'
    );
    $stmt->execute([$userId]);
    return array_map(static function ($row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'token' => (string) ($row['token'] ?? ''),
            'principal_tokens' => (float) ($row['principal_tokens'] ?? 0.0),
            'apy' => (float) ($row['apy'] ?? 0.0),
            'term_months' => (int) ($row['term_months'] ?? 12),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'active' => (int) ($row['active'] ?? 0),
        ];
    }, $stmt->fetchAll());
}

function nexo_add_fixed_term(int $userId, string $token, float $principalTokens, float $apy, int $termMonths, string $startedAt): int
{
    $now = db_now();
    $stmt = app_pdo()->prepare(
        'INSERT INTO nexo_fixed_terms (user_id, token, principal_tokens, apy, term_months, started_at, active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
    );
    $stmt->execute([
        $userId,
        strtoupper(trim($token)),
        max(0.0, $principalTokens),
        max(0.0, $apy),
        max(1, $termMonths),
        $startedAt,
        $now,
        $now,
    ]);
    return (int) app_pdo()->lastInsertId();
}

function nexo_deactivate_fixed_term(int $userId, int $id): bool
{
    $stmt = app_pdo()->prepare('UPDATE nexo_fixed_terms SET active = 0, updated_at = ? WHERE user_id = ? AND id = ? AND active = 1');
    $stmt->execute([db_now(), $userId, $id]);
    return $stmt->rowCount() > 0;
}

function nexo_add_transaction(
    int $userId,
    string $txDate,
    string $bucket,
    string $actionName,
    string $token,
    float $amount,
    string $currency,
    float $apy = 0.0,
    int $termMonths = 0,
    string $notes = ''
): void {
    $stmt = app_pdo()->prepare(
        'INSERT INTO nexo_transactions (user_id, tx_date, bucket, action_name, token, amount, currency, apy, term_months, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $txDate,
        strtolower(trim($bucket)),
        strtolower(trim($actionName)),
        strtoupper(trim($token)),
        $amount,
        strtoupper(trim($currency)),
        max(0.0, $apy),
        max(0, $termMonths),
        trim($notes),
        db_now(),
    ]);
}

function nexo_get_transactions(int $userId, int $limit = 60): array
{
    $safeLimit = max(1, min(500, $limit));
    $stmt = app_pdo()->prepare(
        'SELECT id, tx_date, bucket, action_name, token, amount, currency, apy, term_months, notes
         FROM nexo_transactions
         WHERE user_id = ?
         ORDER BY tx_date DESC, id DESC
         LIMIT ' . $safeLimit
    );
    $stmt->execute([$userId]);
    return array_map(static function ($row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'tx_date' => (string) ($row['tx_date'] ?? ''),
            'bucket' => (string) ($row['bucket'] ?? ''),
            'action' => (string) ($row['action_name'] ?? ''),
            'token' => (string) ($row['token'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0.0),
            'currency' => (string) ($row['currency'] ?? ''),
            'apy' => (float) ($row['apy'] ?? 0.0),
            'term_months' => (int) ($row['term_months'] ?? 0),
            'notes' => (string) ($row['notes'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

function nexo_delete_transaction(int $userId, int $id): bool
{
    $stmt = app_pdo()->prepare('DELETE FROM nexo_transactions WHERE user_id = ? AND id = ?');
    $stmt->execute([$userId, $id]);
    return $stmt->rowCount() > 0;
}
