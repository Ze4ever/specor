<?php
function distribute_generated_amount(array &$dailyGenerated, string $startDay, string $endDay, float $amount, bool $includeStart): void
{
    if ($amount <= 0.0) {
        return;
    }
    $startTs = strtotime($startDay);
    $endTs = strtotime($endDay);
    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        $dailyGenerated[$endDay] = (float) (($dailyGenerated[$endDay] ?? 0.0) + $amount);
        return;
    }

    $startOffset = $includeStart ? 0 : 1;
    $rawDays = (int) floor(($endTs - $startTs) / 86400);
    $steps = $rawDays - $startOffset + 1;
    if ($steps <= 1) {
        // Daily snapshot/interval: keep measured value on the endpoint day.
        $dailyGenerated[$endDay] = (float) (($dailyGenerated[$endDay] ?? 0.0) + $amount);
        return;
    }

    $share = $amount / $steps;
    for ($i = 0; $i < $steps; $i++) {
        $day = date('Y-m-d', $startTs + (86400 * ($startOffset + $i)));
        $dailyGenerated[$day] = (float) (($dailyGenerated[$day] ?? 0.0) + $share);
    }
}

function build_pool_snapshot(array $dados, array $assetPrices, array $overrides, array $feeStatsByPool = [], array $latestUnclaimedByPool = []): array
{
    $pools = [];
    $poolTxMap = [];

    foreach ($dados as $idx => $row) {
        $pid = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        if ($pid === '') {
            continue;
        }

        if (!isset($pools[$pid])) {
            $pools[$pid] = [
                'pool_id' => $pid,
                'create' => (string) ($row['date'] ?? ''),
                'asset_1' => (string) ($row['asset_1'] ?? ''),
                'asset_2' => (string) ($row['asset_2'] ?? ''),
                'wallet' => (string) ($row['wallet'] ?? ''),
                'chain' => (string) ($row['chain'] ?? ''),
                'uniswap' => (string) ($row['uniswap'] ?? ''),
                'deposit_1' => 0.0,
                'deposit_2' => 0.0,
                'initial_usd_1' => 0.0,
                'initial_usd_2' => 0.0,
                'initial_token1_qty' => 0.0,
                'initial_token2_qty' => 0.0,
                'initial_total' => 0.0,
                'current_1' => 0.0,
                'current_2' => 0.0,
                'price_1' => 0.0,
                'price_2' => 0.0,
                'atual_1' => 0.0,
                'atual_2' => 0.0,
                'total_now' => 0.0,
                'unclaimed' => 0.0,
                'claimed' => 0.0,
                'fees_generated' => 0.0,
                'compound' => 0.0,
                'pday' => 0.0,
                'apr' => 0.0,
                'roi' => 0.0,
                'hodl_now' => 0.0,
                'perf_vs_hodl' => 0.0,
                'alpha_ratio' => 0.0,
                'fees_total_lp' => 0.0,
                'il_value' => 0.0,
                'il_abs' => 0.0,
                'yield_eff' => 0.0,
                'days_open' => 0.0,
                'token1_weight_pct' => 0.0,
                'token2_weight_pct' => 0.0,
                'initial_token1_weight_pct' => 0.0,
                'initial_token2_weight_pct' => 0.0,
            ];
        }

        $poolTxMap[$pid][] = $idx;
        $action = strtolower((string) ($row['action'] ?? ''));
        $dep1 = (float) ($row['deposit_1'] ?? 0);
        $dep2 = (float) ($row['deposit_2'] ?? 0);
        $dep1Usd = (float) ($row['deposit_1_usd'] ?? 0);
        $dep2Usd = (float) ($row['deposit_2_usd'] ?? 0);
        $total = (float) ($row['total'] ?? 0);
        $fees = (float) ($row['fees'] ?? 0);
        $depUsdSum = $dep1Usd + $dep2Usd;
        $actionAmount = $total;
        if ($action === 'fees' && abs($fees) > 0.0000001) {
            $actionAmount = $fees;
        } elseif (in_array($action, ['create', 'compound', 'remove'], true) && abs($actionAmount) <= 0.0000001 && $depUsdSum > 0.0) {
            $actionAmount = $depUsdSum;
        }

        if ($action === 'create') {
            $pools[$pid]['deposit_1'] += $dep1;
            $pools[$pid]['deposit_2'] += $dep2;
            $pools[$pid]['initial_usd_1'] += $dep1Usd;
            $pools[$pid]['initial_usd_2'] += $dep2Usd;
            $pools[$pid]['initial_token1_qty'] += $dep1;
            $pools[$pid]['initial_token2_qty'] += $dep2;
            $pools[$pid]['initial_total'] += $actionAmount;
        }
        if ($action === 'compound') {
            $pools[$pid]['deposit_1'] += $dep1;
            $pools[$pid]['deposit_2'] += $dep2;
        }
        if ($action === 'remove') {
            $pools[$pid]['deposit_1'] -= $dep1;
            $pools[$pid]['deposit_2'] -= $dep2;
            $pools[$pid]['initial_usd_1'] -= $dep1Usd;
            $pools[$pid]['initial_usd_2'] -= $dep2Usd;
            $pools[$pid]['initial_token1_qty'] -= $dep1;
            $pools[$pid]['initial_token2_qty'] -= $dep2;
            $pools[$pid]['initial_total'] -= $actionAmount;
        }
        if ($action === 'fees') {
            $pools[$pid]['claimed'] += $actionAmount;
        }
        if ($action === 'compound') {
            $pools[$pid]['compound'] += $actionAmount;
        }
    }

    foreach ($pools as $pid => &$pool) {
        $pool['price_1'] = (float) ($assetPrices[$pool['asset_1']] ?? 0);
        $pool['price_2'] = (float) ($assetPrices[$pool['asset_2']] ?? 0);
        $override = $overrides[$pid] ?? null;
        $pool['current_1'] = $override ? (float) ($override['current_1'] ?? 0) : $pool['deposit_1'];
        $pool['current_2'] = $override ? (float) ($override['current_2'] ?? 0) : $pool['deposit_2'];
        $pool['last_sync_at'] = $override ? (string) ($override['last_sync_at'] ?? '') : '';
        $pool['unclaimed'] = (float) ($latestUnclaimedByPool[$pid] ?? 0.0);

        $pool['fees_generated'] = (float) ($feeStatsByPool[$pid]['generated_total'] ?? 0.0);

        $pool['atual_1'] = $pool['current_1'] * $pool['price_1'];
        $pool['atual_2'] = $pool['current_2'] * $pool['price_2'];
        $pool['total_now'] = $pool['atual_1'] + $pool['atual_2'];
        $overrideTotal = $override ? (float) ($override['total_usd_override'] ?? 0.0) : 0.0;
        if ($overrideTotal > 0.0) {
            $pool['total_now'] = $overrideTotal;
        }

        $createTs = strtotime((string) $pool['create']);
        $days = $createTs ? max((time() - $createTs) / 86400, 0.000001) : 1.0;
        $pool['days_open'] = $days;

        $feesBase = $pool['fees_generated'] > 0 ? $pool['fees_generated'] : ($pool['unclaimed'] + $pool['claimed']);
        $pool['pday'] = $feesBase / $days;

        if ($pool['initial_total'] > 0.0) {
            $pool['apr'] = (($feesBase / $pool['initial_total']) / $days) * 365;
        }

        $pool['roi'] = ($pool['total_now'] + $pool['unclaimed'] + $pool['claimed']) - $pool['initial_total'];
        $pool['hodl_now'] = ($pool['initial_token1_qty'] * $pool['price_1']) + ($pool['initial_token2_qty'] * $pool['price_2']);
        $pool['fees_total_lp'] = $pool['unclaimed'] + $pool['claimed'];
        $pool['il_value'] = $pool['total_now'] - $pool['hodl_now']; // M - Y (can be negative/positive)
        $pool['il_abs'] = abs($pool['il_value']);

        // Alpha (Performance vs HODL): (M + O + P + Q) / Y
        $alphaNum = $pool['total_now'] + $pool['fees_total_lp'];
        $pool['alpha_ratio'] = $pool['hodl_now'] > 0.0 ? ($alphaNum / $pool['hodl_now']) : 0.0;
        $pool['perf_vs_hodl'] = $pool['alpha_ratio'];

        // Yield Efficiency: (O + P + Q) / ABS(M - Y)
        $pool['yield_eff'] = $pool['il_abs'] > 0.0 ? ($pool['fees_total_lp'] / $pool['il_abs']) : 0.0;

        if ($pool['total_now'] > 0) {
            $pool['token1_weight_pct'] = ($pool['atual_1'] / $pool['total_now']) * 100;
            $pool['token2_weight_pct'] = ($pool['atual_2'] / $pool['total_now']) * 100;
        }

        $initialUsdTotal = (float) $pool['initial_usd_1'] + (float) $pool['initial_usd_2'];
        if ($initialUsdTotal > 0.0) {
            $pool['initial_token1_weight_pct'] = ((float) $pool['initial_usd_1'] / $initialUsdTotal) * 100;
            $pool['initial_token2_weight_pct'] = ((float) $pool['initial_usd_2'] / $initialUsdTotal) * 100;
        }
    }
    unset($pool);

    return [$pools, $poolTxMap];
}

function build_fee_stats(array $snapshots, array $dados = []): array
{
    $byPoolRows = [];
    $dailyGenerated = [];
    $claimsByPoolDay = [];
    $poolStartByDay = [];

    foreach ($snapshots as $row) {
        $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        $date = (string) ($row['snapshot_date'] ?? '');
        if ($poolId === '' || $date === '') {
            continue;
        }
        $byPoolRows[$poolId][] = [
            'snapshot_date' => $date,
            'unclaimed_usd' => (float) ($row['unclaimed_usd'] ?? 0),
        ];
    }

    foreach ($dados as $row) {
        $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        if ($poolId === '') {
            continue;
        }
        $rowDay = substr((string) ($row['date'] ?? ''), 0, 10);
        if ($rowDay !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rowDay) === 1) {
            $startKnown = $poolStartByDay[$poolId] ?? null;
            if ($startKnown === null || $rowDay < $startKnown) {
                $poolStartByDay[$poolId] = $rowDay;
            }
        }

        $action = strtolower((string) ($row['action'] ?? ''));
        if ($action !== 'fees') {
            continue;
        }
        $day = $rowDay;
        if ($day === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            continue;
        }
        $feesValue = (float) ($row['fees'] ?? 0.0);
        $totalValue = (float) ($row['total'] ?? 0.0);
        $amount = abs($feesValue) > 0.0000001 ? $feesValue : $totalValue;
        if ($amount <= 0.0) {
            continue;
        }
        $claimsByPoolDay[$poolId][$day] = (float) (($claimsByPoolDay[$poolId][$day] ?? 0.0) + $amount);
    }

    $byPool = [];
    $allPoolIds = array_values(array_unique(array_merge(array_keys($byPoolRows), array_keys($claimsByPoolDay), array_keys($poolStartByDay))));
    foreach ($allPoolIds as $poolId) {
        $rows = $byPoolRows[$poolId] ?? [];
        usort($rows, static fn($a, $b) => strcmp((string) $a['snapshot_date'], (string) $b['snapshot_date']));
        $claimDays = $claimsByPoolDay[$poolId] ?? [];
        if (count($claimDays) > 0) {
            ksort($claimDays);
        }
        $generatedTotal = 0.0;
        $usedClaimDays = [];

        if (count($rows) >= 2) {
            for ($i = 1; $i < count($rows); $i++) {
                $prevRow = $rows[$i - 1];
                $curRow = $rows[$i];
                $prevDate = (string) ($prevRow['snapshot_date'] ?? '');
                $curDate = (string) ($curRow['snapshot_date'] ?? '');
                $prevValue = (float) ($prevRow['unclaimed_usd'] ?? 0.0);
                $curValue = (float) ($curRow['unclaimed_usd'] ?? 0.0);
                $delta = $curValue - $prevValue;

                $claimsBetween = 0.0;
                foreach ($claimDays as $claimDay => $claimAmount) {
                    if ($claimDay > $prevDate && $claimDay <= $curDate) {
                        $claimsBetween += (float) $claimAmount;
                        $usedClaimDays[$claimDay] = true;
                    }
                }

                $generated = max(0.0, $delta + $claimsBetween);
                $generatedTotal += $generated;

                distribute_generated_amount($dailyGenerated, $prevDate, $curDate, $generated, false);
            }
        }

        // If snapshots are sparse, include positive collects not covered by any interval.
        foreach ($claimDays as $claimDay => $claimAmount) {
            if (isset($usedClaimDays[$claimDay])) {
                continue;
            }
            $anchorDay = $poolStartByDay[$poolId] ?? $claimDay;
            if (count($rows) > 0) {
                $latestSnapBeforeClaim = null;
                foreach ($rows as $snap) {
                    $snapDay = (string) ($snap['snapshot_date'] ?? '');
                    if ($snapDay !== '' && $snapDay < $claimDay) {
                        $latestSnapBeforeClaim = $snapDay;
                    }
                }
                if ($latestSnapBeforeClaim !== null && $latestSnapBeforeClaim > $anchorDay) {
                    $anchorDay = $latestSnapBeforeClaim;
                }
            }

            $generatedTotal += (float) $claimAmount;
            $includeStart = ($anchorDay === ($poolStartByDay[$poolId] ?? ''));
            distribute_generated_amount($dailyGenerated, $anchorDay, $claimDay, (float) $claimAmount, $includeStart);
        }

        $latestUnclaimed = 0.0;
        if (count($rows) > 0) {
            $latestUnclaimed = (float) $rows[count($rows) - 1]['unclaimed_usd'];
        }
        $byPool[$poolId] = [
            'generated_total' => $generatedTotal,
            'latest_unclaimed' => $latestUnclaimed,
        ];
    }

    ksort($dailyGenerated);
    $dailyRows = [];
    foreach ($dailyGenerated as $date => $value) {
        $dailyRows[] = ['date' => (string) $date, 'generated' => (float) $value];
    }

    return [
        'by_pool' => $byPool,
        'daily_generated' => $dailyRows,
    ];
}
