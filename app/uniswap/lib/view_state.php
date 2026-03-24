<?php
function build_view_state(string $importJsonPath): array
{
    $userId = current_user_id();
    if ($userId === null) {
        return [];
    }

    $assetPrices = store_get_asset_prices($userId);
    $dados = store_get_transactions($userId);
    $overrides = store_get_pool_overrides($userId);
    $poolOrderer = store_get_pool_order($userId);
    $closedPools = store_get_closed_pools($userId);
    $closedPoolIds = array_flip(store_get_closed_pool_ids($userId));
    $tokenTargets = store_get_token_targets($userId);
    $feeSnapshots = store_get_fee_snapshots($userId);
    $feeStats = build_fee_stats($feeSnapshots, $dados);

    $latestUnclaimedByPool = [];
    foreach ($feeStats['by_pool'] as $poolId => $stats) {
        $latestUnclaimedByPool[$poolId] = (float) ($stats['latest_unclaimed'] ?? 0.0);
    }

    [$pools, $poolTxMap] = build_pool_snapshot($dados, $assetPrices, $overrides, $feeStats['by_pool'], $latestUnclaimedByPool);
    foreach (array_keys($pools) as $poolId) {
        if (isset($closedPoolIds[$poolId])) {
            unset($pools[$poolId], $poolTxMap[$poolId]);
        }
    }
    $poolRows = array_values($pools);

    $orderMap = array_flip($poolOrderer);
    usort($poolRows, static function ($a, $b) use ($orderMap): int {
        $aId = (string) $a['pool_id'];
        $bId = (string) $b['pool_id'];
        $aOrder = $orderMap[$aId] ?? 999999;
        $bOrder = $orderMap[$bId] ?? 999999;
        if ($aOrder === $bOrder) {
            return strcmp($aId, $bId);
        }
        return $aOrder <=> $bOrder;
    });

    $existingPoolIds = array_map(static fn($p) => (string) $p['pool_id'], $poolRows);
    $normalizedOrderer = array_values(array_unique(array_merge(array_values(array_intersect($poolOrderer, $existingPoolIds)), $existingPoolIds)));
    if ($normalizedOrderer !== $poolOrderer) {
        store_replace_pool_order($userId, $normalizedOrderer);
    }

    $poolTxHistory = build_pool_tx_history($poolRows, $poolTxMap, $dados);
    $closedPools = enrich_closed_pools($closedPools, $dados, $assetPrices);
    $feeDailyMap = [];
    foreach ($feeStats['daily_generated'] as $row) {
        $d = (string) ($row['date'] ?? '');
        if ($d === '') {
            continue;
        }
        $feeDailyMap[$d] = (float) ($feeDailyMap[$d] ?? 0.0) + (float) ($row['generated'] ?? 0.0);
    }
    ksort($feeDailyMap);
    $feeDaily = [];
    $anchorTs = null;
    if (count($feeDailyMap) > 0) {
        $lastKey = (string) array_key_last($feeDailyMap);
        $lastTs = strtotime($lastKey);
        if ($lastTs !== false) {
            $anchorTs = $lastTs;
        }
    }
    if ($anchorTs === null) {
        $anchorTs = strtotime(date('Y-m-d'));
    }
    for ($offset = 29; $offset >= 0; $offset--) {
        $d = date('Y-m-d', $anchorTs - (86400 * $offset));
        $feeDaily[] = [
            'date' => $d,
            'generated' => (float) ($feeDailyMap[$d] ?? 0.0),
        ];
    }

    $dash = [
        'pool_count' => count($poolRows),
        'closed_pool_count' => count($closedPools),
        'total_usd' => array_sum(array_map(static fn($p) => (float) $p['total_now'], $poolRows)),
        'pday' => array_sum(array_map(static fn($p) => (float) $p['pday'], $poolRows)),
        'median_apr' => calc_median(array_map(static fn($p) => (float) $p['apr'], $poolRows)),
        'unclaimed' => array_sum(array_map(static fn($p) => (float) $p['unclaimed'], $poolRows)),
        'roi' => array_sum(array_map(static fn($p) => (float) $p['roi'], $poolRows)),
        'fees_today' => count($feeDaily) > 0 ? (float) ($feeDaily[count($feeDaily) - 1]['generated'] ?? 0.0) : 0.0,
        'fees_7d' => array_sum(array_map(static fn($r) => (float) $r['generated'], array_slice($feeDaily, -7))),
    ];
    $monthlyPerformance = build_monthly_performance($dados, (array) ($feeStats['daily_generated'] ?? []));
    $trackingSources = build_tracking_sources($poolRows, $closedPools, $dados);
    $dashboardAnalytics = build_dashboard_analytics(
        $poolRows,
        $dados,
        $feeDaily,
        (array) ($feeStats['daily_generated'] ?? []),
        $monthlyPerformance,
        $tokenTargets
    );

    $importMeta = null;
    if (is_file($importJsonPath)) {
        $importDecoded = json_decode((string) file_get_contents($importJsonPath), true);
        if (is_array($importDecoded)) {
            $importMeta = [
                'generated_at' => (string) ($importDecoded['generated_at'] ?? ''),
                'rows' => is_array($importDecoded['dados_uniswap'] ?? null) ? count($importDecoded['dados_uniswap']) : 0,
                'tokens' => is_array($importDecoded['asset_prices'] ?? null) ? count($importDecoded['asset_prices']) : 0,
            ];
        }
    }

    $activeTab = strtolower((string) ($_GET['tab'] ?? 'dashboard'));
    if (!in_array($activeTab, ['dashboard', 'pools', 'create_pool', 'transactions', 'market', 'fees', 'closed', 'settings'], true)) {
        $activeTab = 'dashboard';
    }

    $knownCoingeckoMap = [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'USDC' => 'usd-coin',
        'BNB' => 'binancecoin',
        'SOL' => 'solana',
        'WLD' => 'worldcoin-wld',
        'PENDLE' => 'pendle',
        'ZRO' => 'layerzero',
        'RENDER' => 'render-token',
        'JITOSOL' => 'jito-staked-sol',
    ];

    $tokenUniverse = [];
    foreach ($assetPrices as $token => $_v) {
        $tokenUniverse[] = strtoupper((string) $token);
    }
    foreach ($poolRows as $pool) {
        $tokenUniverse[] = strtoupper((string) $pool['asset_1']);
        $tokenUniverse[] = strtoupper((string) $pool['asset_2']);
    }
    $tokenUniverse = array_values(array_unique(array_filter($tokenUniverse, static fn($v) => $v !== '')));

    $coingeckoMap = [];
    foreach ($tokenUniverse as $symbol) {
        if (isset($knownCoingeckoMap[$symbol])) {
            $coingeckoMap[$symbol] = $knownCoingeckoMap[$symbol];
        }
    }

    return [
        'assetPrices' => $assetPrices,
        'dados' => $dados,
        'poolRows' => $poolRows,
        'poolTxMap' => $poolTxMap,
        'poolTxHistory' => $poolTxHistory,
        'closedPools' => $closedPools,
        'dash' => $dash,
        'monthlyPerformance' => $monthlyPerformance,
        'trackingSources' => $trackingSources,
        'dashboardAnalytics' => $dashboardAnalytics,
        'feeDaily' => $feeDaily,
        'feeSnapshots' => $feeSnapshots,
        'importMeta' => $importMeta,
        'activeTab' => $activeTab,
        'coingeckoMap' => $coingeckoMap,
        'currentUsername' => current_username(),
    ];
}

function build_pool_tx_history(array $poolRows, array $poolTxMap, array $dados): array
{
    $history = [];
    foreach ($poolRows as $pool) {
        $poolId = (string) $pool['pool_id'];
        $idxs = $poolTxMap[$poolId] ?? [];
        $running = 0.0;
        $points = [];
        foreach ($idxs as $idx) {
            if (!isset($dados[$idx])) {
                continue;
            }
            $row = $dados[$idx];
            $action = strtolower((string) ($row['action'] ?? ''));
            $total = (float) ($row['total'] ?? 0.0);
            $depUsd = (float) ($row['deposit_1_usd'] ?? 0.0) + (float) ($row['deposit_2_usd'] ?? 0.0);
            $amount = abs($total) > 0.0000001 ? $total : $depUsd;
            if (in_array($action, ['create', 'compound'], true)) {
                $running += $amount;
            } elseif ($action === 'remove') {
                $running -= $amount;
            }
            $points[] = [
                'date' => (string) ($row['date'] ?? ''),
                'value' => $running,
                'action' => $action,
            ];
        }
        $history[$poolId] = array_slice($points, -12);
    }
    return $history;
}

function build_monthly_performance(array $dados, array $feeGeneratedDailyRows = []): array
{
    $monthly = [];
    foreach ($dados as $row) {
        $date = substr((string) ($row['date'] ?? ''), 0, 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $date)) {
            continue;
        }
        if (!isset($monthly[$date])) {
            $monthly[$date] = [
                'month' => $date,
                'fees' => 0.0,
                'inflow' => 0.0,
                'outflow' => 0.0,
                'net' => 0.0,
                'tx_count' => 0,
            ];
        }

        $action = strtolower((string) ($row['action'] ?? ''));
        $total = (float) ($row['total'] ?? 0.0);
        $depUsd = (float) ($row['deposit_1_usd'] ?? 0.0) + (float) ($row['deposit_2_usd'] ?? 0.0);
        $amount = abs($total) > 0.0000001 ? $total : $depUsd;

        if (in_array($action, ['create', 'compound'], true)) {
            $monthly[$date]['inflow'] += max(0.0, $amount);
        } elseif ($action === 'remove') {
            $monthly[$date]['outflow'] += max(0.0, $amount);
        }

        $monthly[$date]['tx_count']++;
    }

    foreach ($feeGeneratedDailyRows as $row) {
        $day = (string) ($row['date'] ?? '');
        $date = substr($day, 0, 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $date)) {
            continue;
        }
        if (!isset($monthly[$date])) {
            $monthly[$date] = [
                'month' => $date,
                'fees' => 0.0,
                'inflow' => 0.0,
                'outflow' => 0.0,
                'net' => 0.0,
                'tx_count' => 0,
            ];
        }
        $feeAmount = (float) ($row['generated'] ?? 0.0);
        $monthly[$date]['fees'] += $feeAmount;
    }

    foreach ($monthly as $monthKey => $vals) {
        $monthly[$monthKey]['net'] =
            (float) ($vals['inflow'] ?? 0.0) -
            (float) ($vals['outflow'] ?? 0.0);
    }

    ksort($monthly);
    return array_slice(array_values($monthly), -6);
}

function build_tracking_sources(array $poolRows, array $closedPools, array $dados): array
{
    return [
        [
            'id' => 'uniswap',
            'label' => 'Uniswap Pools',
            'status' => 'active',
            'summary' => 'Fonte principal ativa',
            'metrics' => [
                'pools_ativas' => count($poolRows),
                'pools_encerradas' => count($closedPools),
                'transactions' => count($dados),
            ],
        ],
        [
            'id' => 'orca',
            'label' => 'ORCA Pools',
            'status' => 'planned',
            'summary' => 'Ready to integrate ORCA parser',
            'metrics' => [
                'estado' => 'a configurar',
            ],
        ],
        [
            'id' => 'nexo',
            'label' => 'NEXO Wallet',
            'status' => 'planned',
            'summary' => 'Reserved space for wallet tracking',
            'metrics' => [
                'estado' => 'a configurar',
            ],
        ],
        [
            'id' => 'trading',
            'label' => 'Trading Mensal',
            'status' => 'planned',
            'summary' => 'Reserved space for monthly profits',
            'metrics' => [
                'estado' => 'a configurar',
            ],
        ],
        [
            'id' => 'cashback',
            'label' => 'Cashback Rewards',
            'status' => 'planned',
            'summary' => 'Reserved space for rewards',
            'metrics' => [
                'estado' => 'a configurar',
            ],
        ],
    ];
}

function build_dashboard_analytics(array $poolRows, array $dados, array $feeDaily, array $feeGeneratedDailyRows = [], array $monthlyPerformance = [], array $tokenTargetOverrides = []): array
{
    $capitalIn = 0.0;
    $capitalOut = 0.0;
    $capitalCompounded = 0.0;
    $feesNet = 0.0;
    $feesPositive = 0.0;
    $feesNegative = 0.0;
    $compoundsCount = 0;
    $claimsCount = 0;
    $firstTxTs = null;
    $lastTxTs = null;
    $actionMix = [];
    $monthlyNetMap = [];

    foreach ($dados as $row) {
        $action = strtolower((string) ($row['action'] ?? ''));
        $actionMix[$action] = (int) ($actionMix[$action] ?? 0) + 1;
        $monthKey = substr((string) ($row['date'] ?? ''), 0, 7);
        if (preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            if (!isset($monthlyNetMap[$monthKey])) {
                $monthlyNetMap[$monthKey] = ['inflow' => 0.0, 'outflow' => 0.0, 'fees' => 0.0, 'net' => 0.0];
            }
        }

        $dateRaw = (string) ($row['date'] ?? '');
        $ts = strtotime($dateRaw);
        if ($ts !== false) {
            $firstTxTs = $firstTxTs === null ? $ts : min($firstTxTs, $ts);
            $lastTxTs = $lastTxTs === null ? $ts : max($lastTxTs, $ts);
        }

        $total = (float) ($row['total'] ?? 0.0);
        $feesValue = (float) ($row['fees'] ?? 0.0);
        $depUsd = (float) ($row['deposit_1_usd'] ?? 0.0) + (float) ($row['deposit_2_usd'] ?? 0.0);
        $amount = $total;
        if ($action === 'fees' && abs($feesValue) > 0.0000001) {
            $amount = $feesValue;
        } elseif (in_array($action, ['create', 'compound', 'remove'], true) && abs($amount) <= 0.0000001 && $depUsd > 0.0) {
            $amount = $depUsd;
        }

        if ($action === 'create') {
            $capitalIn += max(0.0, $amount);
            if (isset($monthlyNetMap[$monthKey])) {
                $monthlyNetMap[$monthKey]['inflow'] += max(0.0, $amount);
            }
        } elseif ($action === 'compound') {
            $capitalCompounded += max(0.0, $amount);
            if (isset($monthlyNetMap[$monthKey])) {
                $monthlyNetMap[$monthKey]['inflow'] += max(0.0, $amount);
            }
        } elseif ($action === 'remove') {
            $capitalOut += max(0.0, $amount);
            if (isset($monthlyNetMap[$monthKey])) {
                $monthlyNetMap[$monthKey]['outflow'] += max(0.0, $amount);
            }
        } elseif ($action === 'fees') {
            $feesNet += $amount;
            if ($amount >= 0.0) {
                $feesPositive += $amount;
            } else {
                $feesNegative += abs($amount);
            }
            if ($amount > 0.0) {
                $claimsCount++;
            }
        }

        if ($action === 'compound') {
            $compoundsCount++;
        }
    }

    foreach ($feeGeneratedDailyRows as $row) {
        $day = (string) ($row['date'] ?? '');
        $monthKey = substr($day, 0, 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            continue;
        }
            if (!isset($monthlyNetMap[$monthKey])) {
                $monthlyNetMap[$monthKey] = ['inflow' => 0.0, 'outflow' => 0.0, 'fees' => 0.0, 'net' => 0.0];
            }
        $generated = (float) ($row['generated'] ?? 0.0);
        $monthlyNetMap[$monthKey]['fees'] += $generated;
    }

    $daysTracked = 0.0;
    if ($firstTxTs !== null && $lastTxTs !== null) {
        $daysTracked = max(1.0, (($lastTxTs - $firstTxTs) / 86400.0) + 1.0);
    }
    $txCount = count($dados);
    $avgTxPerDay = $daysTracked > 0.0 ? ((float) $txCount / $daysTracked) : 0.0;

    $dailyFeesValues = array_values(array_map(static fn($r) => (float) ($r['generated'] ?? 0.0), $feeDaily));
    $feesDailyAvg = count($dailyFeesValues) > 0 ? (array_sum($dailyFeesValues) / count($dailyFeesValues)) : 0.0;
    $feesDailyVol = 0.0;
    if (count($dailyFeesValues) > 1) {
        $sq = 0.0;
        foreach ($dailyFeesValues as $v) {
            $sq += ($v - $feesDailyAvg) ** 2;
        }
        $feesDailyVol = sqrt($sq / count($dailyFeesValues));
    }

    ksort($monthlyNetMap);
    $monthlyDrawdowns = [];
    $cum = 0.0;
    $peak = 0.0;
    $maxDdAbs = 0.0;
    $maxDdPct = 0.0;
    $currentDdAbs = 0.0;
    $currentDdPct = 0.0;
    $bestMonth = ['month' => '', 'net' => 0.0];
    $worstMonth = ['month' => '', 'net' => 0.0];
    $positiveMonths = 0;
    foreach ($monthlyNetMap as $month => $vals) {
        $inflow = (float) ($vals['inflow'] ?? 0.0);
        $outflow = (float) ($vals['outflow'] ?? 0.0);
        $net = $inflow - $outflow;
        $monthlyNetMap[$month]['net'] = $net;
        if ($net > 0.0) {
            $positiveMonths++;
        }
        if ($bestMonth['month'] === '' || $net > (float) $bestMonth['net']) {
            $bestMonth = ['month' => $month, 'net' => $net];
        }
        if ($worstMonth['month'] === '' || $net < (float) $worstMonth['net']) {
            $worstMonth = ['month' => $month, 'net' => $net];
        }

        $cum += $net;
        $peak = max($peak, $cum);
        $ddAbs = max(0.0, $peak - $cum);
        $ddPct = $peak > 0.0 ? ($ddAbs / $peak) * 100.0 : 0.0;
        $maxDdAbs = max($maxDdAbs, $ddAbs);
        $maxDdPct = max($maxDdPct, $ddPct);
        $currentDdAbs = $ddAbs;
        $currentDdPct = $ddPct;
        $monthlyDrawdowns[] = [
            'month' => $month,
            'net' => $net,
            'cum' => $cum,
            'drawdown_abs' => $ddAbs,
            'drawdown_pct' => $ddPct,
        ];
    }

    $totalNow = array_sum(array_map(static fn($p) => (float) ($p['total_now'] ?? 0.0), $poolRows));
    $totalUnclaimed = array_sum(array_map(static fn($p) => (float) ($p['unclaimed'] ?? 0.0), $poolRows));
    $totalClaimed = array_sum(array_map(static fn($p) => (float) ($p['claimed'] ?? 0.0), $poolRows));
    $topPoolsBySize = $poolRows;
    usort($topPoolsBySize, static fn($a, $b) => ((float) ($b['total_now'] ?? 0.0)) <=> ((float) ($a['total_now'] ?? 0.0)));
    $top3Now = array_sum(array_map(static fn($p) => (float) ($p['total_now'] ?? 0.0), array_slice($topPoolsBySize, 0, 3)));
    $top3ConcentrationPct = $totalNow > 0.0 ? ($top3Now / $totalNow) * 100.0 : 0.0;
    $weightedApr = $totalNow > 0.0
        ? array_sum(array_map(static fn($p) => ((float) ($p['apr'] ?? 0.0)) * ((float) ($p['total_now'] ?? 0.0)), $poolRows)) / $totalNow
        : 0.0;

    $byChain = [];
    $byWallet = [];
    $tokenQty = [];
    $tokenUsd = [];
    foreach ($poolRows as $pool) {
        $chain = normalize_chain_label((string) ($pool['chain'] ?? ''));
        $wallet = normalize_wallet_label((string) ($pool['wallet'] ?? ''));
        if ($chain === '') {
            $chain = 'N/A';
        }
        if ($wallet === '') {
            $wallet = 'N/A';
        }
        $total = (float) ($pool['total_now'] ?? 0.0);
        $byChain[$chain] = (float) ($byChain[$chain] ?? 0.0) + $total;
        $byWallet[$wallet] = (float) ($byWallet[$wallet] ?? 0.0) + $total;

        $asset1 = strtoupper(trim((string) ($pool['asset_1'] ?? '')));
        $asset2 = strtoupper(trim((string) ($pool['asset_2'] ?? '')));
        $qty1 = (float) ($pool['current_1'] ?? 0.0);
        $qty2 = (float) ($pool['current_2'] ?? 0.0);
        $usd1 = (float) ($pool['atual_1'] ?? 0.0);
        $usd2 = (float) ($pool['atual_2'] ?? 0.0);
        if ($asset1 !== '') {
            $tokenQty[$asset1] = (float) ($tokenQty[$asset1] ?? 0.0) + $qty1;
            $tokenUsd[$asset1] = (float) ($tokenUsd[$asset1] ?? 0.0) + $usd1;
        }
        if ($asset2 !== '') {
            $tokenQty[$asset2] = (float) ($tokenQty[$asset2] ?? 0.0) + $qty2;
            $tokenUsd[$asset2] = (float) ($tokenUsd[$asset2] ?? 0.0) + $usd2;
        }
    }
    arsort($byChain);
    arsort($byWallet);
    arsort($tokenUsd);

    $mapToRows = static function (array $map, float $den): array {
        $rows = [];
        foreach ($map as $label => $value) {
            $rows[] = [
                'label' => (string) $label,
                'value' => (float) $value,
                'pct' => $den > 0.0 ? (((float) $value / $den) * 100.0) : 0.0,
            ];
        }
        return $rows;
    };
    $chainRows = $mapToRows($byChain, $totalNow);
    $walletRows = $mapToRows($byWallet, $totalNow);

    $tokenRows = [];
    foreach ($tokenUsd as $token => $usd) {
        $tokenRows[] = [
            'token' => (string) $token,
            'qty' => (float) ($tokenQty[$token] ?? 0.0),
            'usd' => (float) $usd,
            'pct' => $totalNow > 0.0 ? (((float) $usd / $totalNow) * 100.0) : 0.0,
        ];
    }
    $tokenTargetMap = build_token_target_map(array_map(static fn($r) => (string) ($r['token'] ?? ''), $tokenRows), $tokenTargetOverrides);
    $rebalancePressure = 0.0;
    foreach ($tokenRows as &$tRow) {
        $actualPct = (float) ($tRow['pct'] ?? 0.0);
        $token = strtoupper((string) ($tRow['token'] ?? ''));
        $targetPct = (float) ($tokenTargetMap[$token] ?? 0.0);
        $dev = $actualPct - $targetPct;
        $score = min(100.0, abs($dev) * 2.5);
        $tRow['target_pct'] = $targetPct;
        $tRow['deviation_pp'] = $dev;
        $tRow['rebalance_score'] = $score;
        $rebalancePressure += $score * ($actualPct / 100.0);
    }
    unset($tRow);
    $tokenCount = count($tokenRows);
    $chainCount = count($byChain);
    $walletCount = count($byWallet);

    $topPoolsBySizeRows = [];
    foreach (array_slice($topPoolsBySize, 0, 5) as $pool) {
        $pTotal = (float) ($pool['total_now'] ?? 0.0);
        $topPoolsBySizeRows[] = [
            'pool_id' => (string) ($pool['pool_id'] ?? ''),
            'pair' => trim((string) ($pool['asset_1'] ?? '') . ' / ' . (string) ($pool['asset_2'] ?? '')),
            'total' => $pTotal,
            'pct' => $totalNow > 0.0 ? ($pTotal / $totalNow) * 100.0 : 0.0,
            'roi' => (float) ($pool['roi'] ?? 0.0),
            'yield_eff' => (float) ($pool['yield_eff'] ?? 0.0),
            'fees_total_lp' => (float) ($pool['fees_total_lp'] ?? 0.0),
        ];
    }

    $topRoiPool = null;
    $topYieldPool = null;
    $topFeesPool = null;
    $positiveRoiPools = 0;
    $alphaAboveOnePools = 0;
    $yieldAboveOnePools = 0;
    foreach ($poolRows as $pool) {
        if ((float) ($pool['roi'] ?? 0.0) > 0.0) {
            $positiveRoiPools++;
        }
        if ((float) ($pool['alpha_ratio'] ?? 0.0) > 1.0) {
            $alphaAboveOnePools++;
        }
        if ((float) ($pool['yield_eff'] ?? 0.0) > 1.0) {
            $yieldAboveOnePools++;
        }
    }

    $rebalanceFlags = [];
    foreach ($tokenRows as $row) {
        if ((float) ($row['rebalance_score'] ?? 0.0) >= 60.0) {
            $rebalanceFlags[] = (string) ($row['token'] ?? '');
        }
    }
    if (count($poolRows) > 0) {
        $roiSorted = $poolRows;
        usort($roiSorted, static fn($a, $b) => ((float) ($b['roi'] ?? 0.0)) <=> ((float) ($a['roi'] ?? 0.0)));
        $topRoiPool = $roiSorted[0];

        $yieldSorted = $poolRows;
        usort($yieldSorted, static fn($a, $b) => ((float) ($b['yield_eff'] ?? 0.0)) <=> ((float) ($a['yield_eff'] ?? 0.0)));
        $topYieldPool = $yieldSorted[0];

        $feesSorted = $poolRows;
        usort($feesSorted, static fn($a, $b) => ((float) ($b['fees_total_lp'] ?? 0.0)) <=> ((float) ($a['fees_total_lp'] ?? 0.0)));
        $topFeesPool = $feesSorted[0];
    }

    $monthlyNetSum = array_sum(array_map(static fn($r) => (float) ($r['net'] ?? 0.0), $monthlyPerformance));
    $monthlyFeesSum = array_sum(array_map(static fn($r) => (float) ($r['fees'] ?? 0.0), $monthlyPerformance));
    $recentFees14d = array_sum(array_map(static fn($r) => (float) ($r['generated'] ?? 0.0), $feeDaily));

    return [
        'capital_in' => $capitalIn,
        'capital_out' => $capitalOut,
        'capital_compounded' => $capitalCompounded,
        'capital_net' => $capitalIn - $capitalOut,
        'fees_net' => $feesNet,
        'fees_positive' => $feesPositive,
        'fees_negative' => $feesNegative,
        'tx_count' => $txCount,
        'days_tracked' => $daysTracked,
        'avg_tx_per_day' => $avgTxPerDay,
        'fees_daily_avg' => $feesDailyAvg,
        'fees_daily_volatility' => $feesDailyVol,
        'monthly_drawdowns' => array_slice($monthlyDrawdowns, -12),
        'drawdown_current_abs' => $currentDdAbs,
        'drawdown_current_pct' => $currentDdPct,
        'drawdown_max_abs' => $maxDdAbs,
        'drawdown_max_pct' => $maxDdPct,
        'best_month' => $bestMonth,
        'worst_month' => $worstMonth,
        'positive_months' => $positiveMonths,
        'months_count' => count($monthlyNetMap),
        'claims_count' => $claimsCount,
        'compounds_count' => $compoundsCount,
        'total_now' => $totalNow,
        'total_unclaimed' => $totalUnclaimed,
        'total_claimed' => $totalClaimed,
        'top3_concentration_pct' => $top3ConcentrationPct,
        'weighted_apr' => $weightedApr,
        'chain_count' => $chainCount,
        'wallet_count' => $walletCount,
        'token_count' => $tokenCount,
        'top_pools_by_size' => $topPoolsBySizeRows,
        'top_roi_pool' => $topRoiPool,
        'top_yield_pool' => $topYieldPool,
        'top_fees_pool' => $topFeesPool,
        'positive_roi_pools' => $positiveRoiPools,
        'alpha_above_one_pools' => $alphaAboveOnePools,
        'yield_above_one_pools' => $yieldAboveOnePools,
        'pool_count' => count($poolRows),
        'rebalance_flags' => $rebalanceFlags,
        'rebalance_pressure' => $rebalancePressure,
        'by_chain' => $chainRows,
        'by_wallet' => $walletRows,
        'by_token' => $tokenRows,
        'action_mix' => $actionMix,
        'monthly_net_sum' => $monthlyNetSum,
        'monthly_fees_sum' => $monthlyFeesSum,
        'fees_14d_sum' => $recentFees14d,
    ];
}

function build_token_target_map(array $tokens, array $tokenTargetOverrides = []): array
{
    $normalized = [];
    foreach ($tokens as $token) {
        $clean = strtoupper(trim((string) $token));
        if ($clean === '' || isset($normalized[$clean])) {
            continue;
        }
        $normalized[$clean] = true;
    }
    $tokenList = array_keys($normalized);
    if (count($tokenList) === 0) {
        return [];
    }

    $baseTargets = [
        'USDC' => 30.0,
        'USDT' => 15.0,
        'ETH' => 20.0,
        'WETH' => 20.0,
        'BTC' => 15.0,
        'WBTC' => 15.0,
        'SOL' => 10.0,
    ];

    $targetMap = [];
    $assignedSum = 0.0;
    $remainingTokens = [];
    foreach ($tokenList as $token) {
        if (isset($baseTargets[$token])) {
            $targetMap[$token] = (float) $baseTargets[$token];
            $assignedSum += (float) $baseTargets[$token];
        } else {
            $remainingTokens[] = $token;
        }
    }

    if ($assignedSum > 100.0) {
        $scale = 100.0 / $assignedSum;
        foreach ($targetMap as $token => $pct) {
            $targetMap[$token] = $pct * $scale;
        }
        $assignedSum = 100.0;
    }

    $remainingPct = max(0.0, 100.0 - $assignedSum);
    if (count($remainingTokens) > 0) {
        $share = $remainingPct / count($remainingTokens);
        foreach ($remainingTokens as $token) {
            $targetMap[$token] = $share;
        }
    } elseif (count($targetMap) > 0 && $remainingPct > 0.0) {
        $firstToken = array_key_first($targetMap);
        if ($firstToken !== null) {
            $targetMap[$firstToken] += $remainingPct;
        }
    }

    foreach ($tokenTargetOverrides as $token => $targetPct) {
        $cleanToken = strtoupper(trim((string) $token));
        if ($cleanToken === '' || !isset($targetMap[$cleanToken])) {
            continue;
        }
        $targetMap[$cleanToken] = max(0.0, min(100.0, (float) $targetPct));
    }

    return $targetMap;
}

function enrich_closed_pools(array $closedPools, array $dados, array $assetPrices): array
{
    if (count($closedPools) === 0) {
        return $closedPools;
    }

    $txByPool = [];
    foreach ($dados as $row) {
        $pid = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        if ($pid === '') {
            continue;
        }
        $txByPool[$pid][] = $row;
    }

    foreach ($closedPools as &$row) {
        $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        $asset1 = strtoupper(trim((string) ($row['asset_1'] ?? '')));
        $asset2 = strtoupper(trim((string) ($row['asset_2'] ?? '')));
        $price1 = (float) ($assetPrices[$asset1] ?? 0.0);
        $price2 = (float) ($assetPrices[$asset2] ?? 0.0);

        $initial1 = 0.0;
        $initial2 = 0.0;
        $running1 = 0.0;
        $running2 = 0.0;
        $final1 = null;
        $final2 = null;
        $poolUrl = '';
        $txUrl = '';
        $txDate = '';

        foreach ($txByPool[$poolId] ?? [] as $tx) {
            $action = strtolower((string) ($tx['action'] ?? ''));
            $dep1 = (float) ($tx['deposit_1'] ?? 0.0);
            $dep2 = (float) ($tx['deposit_2'] ?? 0.0);
            $txRef = strtolower((string) ($tx['transaction'] ?? ''));
            $txUrlRaw = trim((string) ($tx['transaction'] ?? ''));
            $poolUrlRaw = trim((string) ($tx['uniswap'] ?? ''));

            if ($poolUrl === '' && $poolUrlRaw !== '' && is_valid_http_url($poolUrlRaw)) {
                $poolUrl = $poolUrlRaw;
            }
            if ($txUrlRaw !== '' && is_valid_http_url($txUrlRaw)) {
                $txUrl = $txUrlRaw;
                $txDate = (string) ($tx['date'] ?? '');
            }

            if ($action === 'create') {
                $initial1 += $dep1;
                $initial2 += $dep2;
                $running1 += $dep1;
                $running2 += $dep2;
                continue;
            }

            if ($action === 'compound') {
                $running1 += $dep1;
                $running2 += $dep2;
                continue;
            }

            if ($action === 'remove') {
                if ($txRef === 'internal:close') {
                    $final1 = $dep1;
                    $final2 = $dep2;
                }
                $running1 -= $dep1;
                $running2 -= $dep2;
            }
        }

        if ($final1 === null) {
            $final1 = $running1;
        }
        if ($final2 === null) {
            $final2 = $running2;
        }

        $feesTotal = (float) ($row['unclaimed'] ?? 0.0) + (float) ($row['claimed'] ?? 0.0);
        $totalNow = (float) ($row['total_now'] ?? 0.0);
        $initialTotal = (float) ($row['initial_total'] ?? 0.0);

        $roiTotal = $totalNow + $feesTotal - $initialTotal;
        $roiPct = $initialTotal > 0.0 ? ($roiTotal / $initialTotal) * 100.0 : 0.0;

        $hodlAtClose = (float) ($row['hodl_at_close'] ?? 0.0);
        $hodlAtCloseNote = '';
        if ($hodlAtClose <= 0.0 && ($initial1 > 0.0 || $initial2 > 0.0) && ($price1 > 0.0 || $price2 > 0.0)) {
            $hodlAtClose = ($initial1 * $price1) + ($initial2 * $price2);
            $hodlAtCloseNote = 'est';
        }

        $row['initial_token1'] = $initial1;
        $row['initial_token2'] = $initial2;
        $row['final_token1'] = $final1;
        $row['final_token2'] = $final2;
        $row['delta_token1'] = $final1 - $initial1;
        $row['delta_token2'] = $final2 - $initial2;
        $row['roi_total'] = $roiTotal;
        $row['roi_pct'] = $roiPct;
        $row['hodl_at_close_view'] = $hodlAtClose;
        $row['hodl_at_close_note'] = $hodlAtCloseNote;
        $row['pool_url'] = $poolUrl;
        $row['tx_url'] = $txUrl;
        $row['tx_date'] = $txDate;
    }
    unset($row);

    return $closedPools;
}
