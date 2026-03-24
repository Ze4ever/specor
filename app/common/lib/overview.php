<?php
function overview_build_dashboard(array $uniswapState, string $nexoDbPath, string $username): array
{
    $uniswap = overview_get_uniswap_summary($uniswapState);
    $nexo = overview_get_nexo_summary($nexoDbPath, $username);
    $combinedTotal = (float) ($uniswap['total_usd'] ?? 0.0) + (float) ($nexo['total_usd'] ?? 0.0);

    return [
        'uniswap' => $uniswap,
        'nexo' => $nexo,
        'combined' => [
            'total_usd' => $combinedTotal,
        ],
    ];
}

function overview_build_nexo_dashboard_full(string $dbPath, string $username): array
{
    $username = strtolower(trim($username));
    $base = [
        'as_of' => '',
        'eur_usd_rate' => 0.0,
        'nexo_usd_price' => 0.0,
        'eurx_eur' => 0.0,
        'eurx_usd' => 0.0,
        'nexo_tokens' => 0.0,
        'nexo_usd' => 0.0,
        'total_usd' => 0.0,
        'flexible_rows' => [],
        'fixed_rows' => [],
        'annual_flexible_usd' => 0.0,
        'fixed_principal_tokens' => 0.0,
        'annual_fixed_tokens' => 0.0,
        'term_projected_tokens' => 0.0,
        'transactions' => [],
        'recent_flexible_rewards' => [],
        'market_rows' => [],
        'flex_reward_stats' => [
            'total_rows' => 0,
            'total_usd' => 0.0,
            'total_nexo' => 0.0,
            'last_day' => '',
            'avg_price_30d' => 0.0,
        ],
        'flexible_count' => 0,
        'fixed_count' => 0,
        'finalized_flexible' => [],
        'finalized_rewards_by_term' => [],
        'finalized_reward_rows' => [],
    ];
    if ($username === '') {
        return $base;
    }

    try {
        $pdo = overview_open_pdo($dbPath);
        db_ensure_schema($pdo);
        db_ensure_nexo_schema($pdo);
    } catch (Throwable $e) {
        return $base;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $userId = $stmt->fetchColumn();
    if (!is_numeric($userId)) {
        return $base;
    }
    $userId = (int) $userId;

    $walletStmt = $pdo->prepare(
        'SELECT eurx_eur, nexo_tokens, eur_usd_rate, nexo_usd_price, updated_at
         FROM nexo_wallet_state
         WHERE user_id = ? LIMIT 1'
    );
    $walletStmt->execute([$userId]);
    $wallet = $walletStmt->fetch() ?: [];

    $eurxEur = (float) ($wallet['eurx_eur'] ?? 0.0);
    $nexoTokens = (float) ($wallet['nexo_tokens'] ?? 0.0);
    $eurUsd = (float) ($wallet['eur_usd_rate'] ?? 0.0);
    $nexoUsdPrice = (float) ($wallet['nexo_usd_price'] ?? 0.0);
    $eurxUsd = $eurxEur * $eurUsd;
    $nexoUsd = $nexoTokens * $nexoUsdPrice;

    $flexRowsStmt = $pdo->prepare(
        'SELECT id, token, coingecko_id, principal, principal_usd, currency, apy, started_at
         FROM nexo_flexible_terms
         WHERE user_id = ? AND active = 1
         ORDER BY id DESC'
    );
    $flexRowsStmt->execute([$userId]);
    $flexRows = $flexRowsStmt->fetchAll();

    $flexAggStmt = $pdo->prepare(
        'SELECT flexible_term_id, COUNT(*) AS days_count, SUM(reward_usd) AS total_usd, SUM(reward_nexo) AS total_nexo, MAX(reward_date) AS last_day
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ?
         GROUP BY flexible_term_id'
    );
    $flexAggStmt->execute([$userId]);
    $aggMap = [];
    foreach ($flexAggStmt->fetchAll() as $row) {
        $termId = (int) ($row['flexible_term_id'] ?? 0);
        if ($termId <= 0) {
            continue;
        }
        $aggMap[$termId] = [
            'days_count' => (int) ($row['days_count'] ?? 0),
            'total_usd' => (float) ($row['total_usd'] ?? 0.0),
            'total_nexo' => (float) ($row['total_nexo'] ?? 0.0),
            'last_day' => (string) ($row['last_day'] ?? ''),
        ];
    }

    $flexibleRows = [];
    $annualFlexibleUsd = 0.0;
    foreach ($flexRows as $row) {
        $termId = (int) ($row['id'] ?? 0);
        $principalUsd = (float) ($row['principal_usd'] ?? 0.0);
        $apy = (float) ($row['apy'] ?? 0.0);
        $annualUsd = $principalUsd * ($apy / 100.0);
        $dailyUsd = $annualUsd / 365.0;
        $agg = $aggMap[$termId] ?? ['days_count' => 0, 'total_usd' => 0.0, 'total_nexo' => 0.0, 'last_day' => ''];
        $annualFlexibleUsd += $annualUsd;
        $flexibleRows[] = [
            'id' => $termId,
            'token' => (string) ($row['token'] ?? ''),
            'coingecko_id' => (string) ($row['coingecko_id'] ?? ''),
            'principal' => (float) ($row['principal'] ?? 0.0),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'apy' => $apy,
            'started_at' => (string) ($row['started_at'] ?? ''),
            'principal_usd' => $principalUsd,
            'annual_usd' => $annualUsd,
            'daily_usd' => $dailyUsd,
            'days_generated' => (int) ($agg['days_count'] ?? 0),
            'generated_usd' => (float) ($agg['total_usd'] ?? 0.0),
            'generated_nexo' => (float) ($agg['total_nexo'] ?? 0.0),
            'last_generated_day' => (string) ($agg['last_day'] ?? ''),
        ];
    }

    $fixedRowsStmt = $pdo->prepare(
        'SELECT id, token, principal_tokens, apy, term_months, started_at
         FROM nexo_fixed_terms
         WHERE user_id = ? AND active = 1
         ORDER BY id DESC'
    );
    $fixedRowsStmt->execute([$userId]);
    $fixedRows = [];
    $fixedPrincipalTokens = 0.0;
    $annualFixedTokens = 0.0;
    $termProjectedTokens = 0.0;
    foreach ($fixedRowsStmt->fetchAll() as $row) {
        $principal = (float) ($row['principal_tokens'] ?? 0.0);
        $apy = (float) ($row['apy'] ?? 0.0);
        $months = max(1, (int) ($row['term_months'] ?? 12));
        $annual = $principal * ($apy / 100.0);
        $termYield = $annual * ($months / 12.0);
        $fixedPrincipalTokens += $principal;
        $annualFixedTokens += $annual;
        $termProjectedTokens += $termYield;
        $fixedRows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'token' => (string) ($row['token'] ?? ''),
            'principal_tokens' => $principal,
            'apy' => $apy,
            'term_months' => $months,
            'started_at' => (string) ($row['started_at'] ?? ''),
            'annual_tokens' => $annual,
            'term_tokens' => $termYield,
        ];
    }

    $txStmt = $pdo->prepare(
        'SELECT id, tx_date, bucket, action_name, token, amount, currency, apy, term_months, notes
         FROM nexo_transactions
         WHERE user_id = ?
         ORDER BY tx_date DESC, id DESC
         LIMIT 500'
    );
    $txStmt->execute([$userId]);
    $transactions = array_map(static function ($row): array {
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
    }, $txStmt->fetchAll());

    $marketStmt = $pdo->prepare(
        'SELECT token, coingecko_id, manual_price_usd, use_manual, active, updated_at
         FROM nexo_market_tokens
         WHERE user_id = ? AND active = 1
         ORDER BY token ASC'
    );
    $marketStmt->execute([$userId]);
    $marketRows = $marketStmt->fetchAll();
    $priceCacheStmt = $pdo->prepare('SELECT price_usd FROM nexo_price_cache WHERE user_id = ? AND token = ? LIMIT 1');
    $marketRows = array_map(static function ($row) use ($priceCacheStmt, $userId): array {
        $token = (string) ($row['token'] ?? '');
        $price = 0.0;
        if ((int) ($row['use_manual'] ?? 0) === 1) {
            $price = (float) ($row['manual_price_usd'] ?? 0.0);
        } else {
            $priceCacheStmt->execute([$userId, $token]);
            $cached = $priceCacheStmt->fetchColumn();
            if (is_numeric($cached)) {
                $price = (float) $cached;
            }
        }
        return [
            'token' => $token,
            'coingecko_id' => (string) ($row['coingecko_id'] ?? ''),
            'use_manual' => (int) ($row['use_manual'] ?? 0),
            'manual_price_usd' => (float) ($row['manual_price_usd'] ?? 0.0),
            'price_usd' => $price,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $marketRows);

    $recentStmt = $pdo->prepare(
        'SELECT r.id, r.reward_date, r.reward_usd, r.reward_nexo, r.nexo_price_usd, t.token, t.id AS term_id
         FROM nexo_flexible_daily_rewards r
         JOIN nexo_flexible_terms t ON t.id = r.flexible_term_id
         WHERE r.user_id = ?
         ORDER BY r.reward_date DESC, r.id DESC
         LIMIT 90'
    );
    $recentStmt->execute([$userId]);
    $recentLogs = array_map(static function ($row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'reward_date' => (string) ($row['reward_date'] ?? ''),
            'reward_usd' => (float) ($row['reward_usd'] ?? 0.0),
            'reward_nexo' => (float) ($row['reward_nexo'] ?? 0.0),
            'nexo_price_usd' => (float) ($row['nexo_price_usd'] ?? 0.0),
            'token' => (string) ($row['token'] ?? ''),
            'term_id' => (int) ($row['term_id'] ?? 0),
        ];
    }, $recentStmt->fetchAll());

    $flexStatsStmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_rows,
            SUM(reward_usd) AS total_usd,
            SUM(reward_nexo) AS total_nexo,
            MAX(reward_date) AS last_day
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ?'
    );
    $flexStatsStmt->execute([$userId]);
    $statsRow = $flexStatsStmt->fetch() ?: [];

    $avgStmt = $pdo->prepare(
        "SELECT AVG(nexo_price_usd) AS avg_price_30d
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ? AND reward_date >= date('now','-30 day')"
    );
    $avgStmt->execute([$userId]);
    $avgRow = $avgStmt->fetch() ?: [];

    $finalizedStmt = $pdo->prepare(
        'SELECT term_id, token, principal, apy, started_at, finalized_at, days_count, total_usd, total_nexo
         FROM nexo_flexible_finalized
         WHERE user_id = ?
         ORDER BY finalized_at DESC'
    );
    $finalizedStmt->execute([$userId]);
    $finalized = $finalizedStmt->fetchAll();

    $finalIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int) ($r['term_id'] ?? 0), $finalized))));
    $finalLogs = [];
    if (count($finalIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($finalIds), '?'));
        $params = array_merge([$userId], $finalIds);
        $logsStmt = $pdo->prepare(
            'SELECT term_id, reward_date, reward_usd, reward_nexo, nexo_price_usd
             FROM nexo_flexible_finalized_logs
             WHERE user_id = ? AND term_id IN (' . $placeholders . ')
             ORDER BY reward_date ASC'
        );
        $logsStmt->execute($params);
        foreach ($logsStmt->fetchAll() as $row) {
            $termId = (int) ($row['term_id'] ?? 0);
            if ($termId <= 0) {
                continue;
            }
            $finalLogs[$termId][] = [
                'reward_date' => (string) ($row['reward_date'] ?? ''),
                'reward_usd' => (float) ($row['reward_usd'] ?? 0.0),
                'reward_nexo' => (float) ($row['reward_nexo'] ?? 0.0),
                'nexo_price_usd' => (float) ($row['nexo_price_usd'] ?? 0.0),
            ];
        }
    }

    return [
        'as_of' => (string) ($wallet['updated_at'] ?? ''),
        'eur_usd_rate' => $eurUsd,
        'nexo_usd_price' => $nexoUsdPrice,
        'eurx_eur' => $eurxEur,
        'eurx_usd' => $eurxUsd,
        'nexo_tokens' => $nexoTokens,
        'nexo_usd' => $nexoUsd,
        'total_usd' => $eurxUsd + $nexoUsd,
        'flexible_rows' => $flexibleRows,
        'fixed_rows' => $fixedRows,
        'annual_flexible_usd' => $annualFlexibleUsd,
        'fixed_principal_tokens' => $fixedPrincipalTokens,
        'annual_fixed_tokens' => $annualFixedTokens,
        'term_projected_tokens' => $termProjectedTokens,
        'transactions' => $transactions,
        'recent_flexible_rewards' => $recentLogs,
        'market_rows' => $marketRows,
        'flex_reward_stats' => [
            'total_rows' => (int) ($statsRow['total_rows'] ?? 0),
            'total_usd' => (float) ($statsRow['total_usd'] ?? 0.0),
            'total_nexo' => (float) ($statsRow['total_nexo'] ?? 0.0),
            'last_day' => (string) ($statsRow['last_day'] ?? ''),
            'avg_price_30d' => (float) ($avgRow['avg_price_30d'] ?? 0.0),
        ],
        'flexible_count' => count($flexibleRows),
        'fixed_count' => count($fixedRows),
        'finalized_flexible' => array_map(static function ($row): array {
            return [
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
        }, $finalized),
        'finalized_rewards_by_term' => [],
        'finalized_reward_rows' => $finalLogs,
    ];
}

function overview_get_uniswap_summary(array $state): array
{
    $dash = (array) ($state['dash'] ?? []);
    $analytics = (array) ($state['dashboardAnalytics'] ?? []);

    return [
        'total_usd' => (float) ($dash['total_usd'] ?? 0.0),
        'roi' => (float) ($dash['roi'] ?? 0.0),
        'pday' => (float) ($dash['pday'] ?? 0.0),
        'unclaimed' => (float) ($dash['unclaimed'] ?? 0.0),
        'pool_count' => (int) ($dash['pool_count'] ?? 0),
        'fees_7d' => (float) ($dash['fees_7d'] ?? 0.0),
        'fees_today' => (float) ($dash['fees_today'] ?? 0.0),
        'weighted_apr' => (float) ($analytics['weighted_apr'] ?? 0.0),
    ];
}

function overview_get_nexo_summary(string $dbPath, string $username): array
{
    $summary = [
        'status' => 'empty',
        'total_usd' => 0.0,
        'eurx_eur' => 0.0,
        'eurx_usd' => 0.0,
        'nexo_tokens' => 0.0,
        'nexo_usd' => 0.0,
        'eur_usd_rate' => 0.0,
        'nexo_usd_price' => 0.0,
        'as_of' => '',
        'flexible_count' => 0,
        'fixed_count' => 0,
        'annual_flexible_usd' => 0.0,
        'fixed_principal_tokens' => 0.0,
        'fixed_annual_tokens' => 0.0,
        'transactions_count' => 0,
        'rewards_total_usd' => 0.0,
        'rewards_30d_usd' => 0.0,
        'rewards_7d_usd' => 0.0,
        'rewards_last_day' => '',
        'avg_nexo_price_30d' => 0.0,
    ];

    $username = strtolower(trim($username));
    if ($username === '') {
        return $summary;
    }

    try {
        $pdo = overview_open_pdo($dbPath);
        db_ensure_schema($pdo);
        db_ensure_nexo_schema($pdo);
    } catch (Throwable $e) {
        $summary['status'] = 'error';
        return $summary;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $userId = $stmt->fetchColumn();
    if (!is_numeric($userId)) {
        $summary['status'] = 'missing_user';
        return $summary;
    }

    $userId = (int) $userId;
    $walletStmt = $pdo->prepare(
        'SELECT eurx_eur, nexo_tokens, eur_usd_rate, nexo_usd_price, updated_at
         FROM nexo_wallet_state
         WHERE user_id = ? LIMIT 1'
    );
    $walletStmt->execute([$userId]);
    $wallet = $walletStmt->fetch() ?: [];

    $eurxEur = (float) ($wallet['eurx_eur'] ?? 0.0);
    $nexoTokens = (float) ($wallet['nexo_tokens'] ?? 0.0);
    $eurUsd = (float) ($wallet['eur_usd_rate'] ?? 0.0);
    $nexoUsdPrice = (float) ($wallet['nexo_usd_price'] ?? 0.0);
    $eurxUsd = $eurxEur * $eurUsd;
    $nexoUsd = $nexoTokens * $nexoUsdPrice;
    $total = $eurxUsd + $nexoUsd;

    $flexStmt = $pdo->prepare('SELECT COUNT(*) FROM nexo_flexible_terms WHERE user_id = ? AND active = 1');
    $flexStmt->execute([$userId]);
    $flexCount = (int) $flexStmt->fetchColumn();

    $fixedStmt = $pdo->prepare('SELECT COUNT(*) FROM nexo_fixed_terms WHERE user_id = ? AND active = 1');
    $fixedStmt->execute([$userId]);
    $fixedCount = (int) $fixedStmt->fetchColumn();

    $annualStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(principal_usd * (apy / 100.0)), 0.0) AS annual_usd
         FROM nexo_flexible_terms
         WHERE user_id = ? AND active = 1'
    );
    $annualStmt->execute([$userId]);
    $annualFlexible = (float) $annualStmt->fetchColumn();

    $fixedPrincipalStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(principal_tokens), 0.0) AS total_tokens
         FROM nexo_fixed_terms
         WHERE user_id = ? AND active = 1'
    );
    $fixedPrincipalStmt->execute([$userId]);
    $fixedPrincipal = (float) $fixedPrincipalStmt->fetchColumn();

    $fixedAnnualStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(principal_tokens * (apy / 100.0)), 0.0) AS annual_tokens
         FROM nexo_fixed_terms
         WHERE user_id = ? AND active = 1'
    );
    $fixedAnnualStmt->execute([$userId]);
    $fixedAnnualTokens = (float) $fixedAnnualStmt->fetchColumn();

    $txStmt = $pdo->prepare('SELECT COUNT(*) FROM nexo_transactions WHERE user_id = ?');
    $txStmt->execute([$userId]);
    $txCount = (int) $txStmt->fetchColumn();

    $rewardsStmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(reward_usd), 0.0) AS total_usd,
            COALESCE(SUM(CASE WHEN reward_date >= date(\'now\',\'-29 day\') THEN reward_usd ELSE 0 END), 0.0) AS usd_30d,
            COALESCE(SUM(CASE WHEN reward_date >= date(\'now\',\'-6 day\') THEN reward_usd ELSE 0 END), 0.0) AS usd_7d,
            MAX(reward_date) AS last_day,
            COALESCE(AVG(CASE WHEN reward_date >= date(\'now\',\'-29 day\') THEN nexo_price_usd END), 0.0) AS avg_nexo_30d
         FROM nexo_flexible_daily_rewards
         WHERE user_id = ?'
    );
    $rewardsStmt->execute([$userId]);
    $rewardsRow = $rewardsStmt->fetch() ?: [];

    return [
        'status' => 'ok',
        'total_usd' => $total,
        'eurx_eur' => $eurxEur,
        'eurx_usd' => $eurxUsd,
        'nexo_tokens' => $nexoTokens,
        'nexo_usd' => $nexoUsd,
        'eur_usd_rate' => $eurUsd,
        'nexo_usd_price' => $nexoUsdPrice,
        'as_of' => (string) ($wallet['updated_at'] ?? ''),
        'flexible_count' => $flexCount,
        'fixed_count' => $fixedCount,
        'annual_flexible_usd' => $annualFlexible,
        'fixed_principal_tokens' => $fixedPrincipal,
        'fixed_annual_tokens' => $fixedAnnualTokens,
        'transactions_count' => $txCount,
        'rewards_total_usd' => (float) ($rewardsRow['total_usd'] ?? 0.0),
        'rewards_30d_usd' => (float) ($rewardsRow['usd_30d'] ?? 0.0),
        'rewards_7d_usd' => (float) ($rewardsRow['usd_7d'] ?? 0.0),
        'rewards_last_day' => (string) ($rewardsRow['last_day'] ?? ''),
        'avg_nexo_price_30d' => (float) ($rewardsRow['avg_nexo_30d'] ?? 0.0),
    ];
}

function overview_open_pdo(string $dbPath): PDO
{
    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('The pdo_sqlite extension is not enabled.');
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}
