<?php
function nexo_build_view_state(): array
{
    $userId = current_user_id();
    if ($userId === null) {
        return [];
    }

    $activeTab = strtolower((string) ($_GET['tab'] ?? 'dashboard'));
    if (!in_array($activeTab, ['dashboard', 'mercado', 'flexible', 'finalizados', 'fixed', 'transactions', 'settings'], true)) {
        $activeTab = 'dashboard';
    }
    $wallet = nexo_get_wallet_state($userId);
    $today = gmdate('Y-m-d');
    $storedEurUsd = (float) ($wallet['eur_usd_rate'] ?? 1.0);
    $storedNexoUsd = (float) ($wallet['nexo_usd_price'] ?? 0.0);
    $eurUsdRate = $storedEurUsd;

    $liveEurUsd = nexo_get_token_price_usd($userId, 'EURC', 'euro-coin', 300, true);
    if ($liveEurUsd !== null && $liveEurUsd > 0.0) {
        $eurUsdRate = $liveEurUsd;
    }

    $liveNexoUsd = nexo_get_token_price_usd($userId, 'NEXO', 'nexo');
    $nextNexoUsd = $storedNexoUsd;
    if ($liveNexoUsd !== null && $liveNexoUsd > 0.0) {
        $nextNexoUsd = $liveNexoUsd;
    }

    if ($eurUsdRate > 0.0) {
        nexo_record_price_history($userId, 'EUR', $today, $eurUsdRate);
        nexo_record_price_history($userId, 'EURX', $today, $eurUsdRate);
        nexo_record_price_history($userId, 'EURS', $today, $eurUsdRate);
        nexo_record_price_history($userId, 'EURC', $today, $eurUsdRate);
    }
    if ($nextNexoUsd > 0.0) {
        nexo_record_price_history($userId, 'NEXO', $today, $nextNexoUsd);
    }

    if (abs($eurUsdRate - $storedEurUsd) > 0.000001 || abs($nextNexoUsd - $storedNexoUsd) > 0.000001) {
        nexo_save_wallet_state(
            $userId,
            (float) ($wallet['eurx_eur'] ?? 0.0),
            (float) ($wallet['nexo_tokens'] ?? 0.0),
            $eurUsdRate > 0.0 ? $eurUsdRate : $storedEurUsd,
            $nextNexoUsd
        );
    }
    $wallet['eur_usd_rate'] = $eurUsdRate > 0.0 ? $eurUsdRate : $storedEurUsd;
    $wallet['nexo_usd_price'] = $nextNexoUsd;
    nexo_seed_default_terms_if_empty($userId);
    if (!nexo_get_logs_paused($userId)) {
        nexo_generate_flexible_daily_rewards($userId, (float) ($wallet['nexo_usd_price'] ?? 1.0));
    }
    nexo_generate_fixed_daily_rewards($userId, (float) ($wallet['nexo_usd_price'] ?? 1.0));
    $flexibleTerms = nexo_get_flexible_terms($userId);
    $finalizedFlexible = nexo_get_finalized_terms($userId);
    $fixedTerms = nexo_get_fixed_terms($userId);
    $transactions = nexo_get_transactions($userId, 500);
    $marketTokens = nexo_get_market_tokens($userId);
    $flexRewardsByTerm = nexo_get_flexible_rewards_by_term($userId);
    $recentFlexibleRewards = nexo_get_recent_flexible_rewards($userId, 90);
    $finalizedIds = array_map(static fn($r) => (int) ($r['term_id'] ?? 0), $finalizedFlexible);
    $finalizedRewardsByTerm = [];
    $finalizedRewardRows = nexo_get_finalized_logs_by_term($userId, $finalizedIds);
    $flexRewardStats = nexo_get_flexible_reward_stats($userId);
    $fixedRewardsByTerm = nexo_get_fixed_rewards_by_term($userId);
    $recentFixedRewards = nexo_get_recent_fixed_rewards($userId, 90);
    $fixedRewardStats = nexo_get_fixed_reward_stats($userId);

    $eurxEur = (float) ($wallet['eurx_eur'] ?? 0.0);
    $nexoTokens = (float) ($wallet['nexo_tokens'] ?? 0.0);
    $eurUsdRate = max(0.000001, (float) ($wallet['eur_usd_rate'] ?? 1.0));
    $nexoUsdPrice = max(0.0, (float) ($wallet['nexo_usd_price'] ?? 0.0));
    $eurxUsd = $eurxEur * $eurUsdRate;
    $nexoUsd = $nexoTokens * $nexoUsdPrice;

    $flexibleRows = [];
    $annualFlexibleUsd = 0.0;
    $flexPrincipalUsdTotal = 0.0;
    foreach ($flexibleTerms as $row) {
        $termId = (int) ($row['id'] ?? 0);
        $principalUsd = (float) ($row['principal_usd'] ?? 0.0);
        $token = (string) ($row['token'] ?? '');
        if ($principalUsd <= 0.0) {
            $principalTokens = (float) ($row['principal'] ?? 0.0);
            if (nexo_is_eur_like_token($token)) {
                $principalUsd = $principalTokens * $eurUsdRate;
            } else {
                $price = nexo_get_token_price_usd($userId, $token, (string) ($row['coingecko_id'] ?? ''), 21600);
                $principalUsd = $price !== null && $price > 0.0 ? ($principalTokens * $price) : $principalTokens;
            }
        }
        $annualUsd = $principalUsd * ((float) $row['apy'] / 100.0);
        $dailyUsd = $annualUsd / 365.0;
        $agg = $flexRewardsByTerm[$termId] ?? ['days_count' => 0, 'total_usd' => 0.0, 'total_nexo' => 0.0, 'last_day' => ''];
        $annualFlexibleUsd += $annualUsd;
        $flexPrincipalUsdTotal += $principalUsd;
        $flexibleRows[] = [
            'id' => $termId,
            'token' => $token,
            'coingecko_id' => (string) ($row['coingecko_id'] ?? ''),
            'principal' => (float) ($row['principal'] ?? 0.0),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'apy' => (float) ($row['apy'] ?? 0.0),
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

    $marketRows = [];
    foreach ($marketTokens as $row) {
        $token = (string) ($row['token'] ?? '');
        if ($token === '') {
            continue;
        }
        $resolvedPrice = nexo_get_token_price_usd($userId, $token, (string) ($row['coingecko_id'] ?? ''), 21600);
        $marketRows[] = [
            'token' => $token,
            'coingecko_id' => (string) ($row['coingecko_id'] ?? ''),
            'use_manual' => (int) ($row['use_manual'] ?? 0),
            'manual_price_usd' => (float) ($row['manual_price_usd'] ?? 0.0),
            'price_usd' => (float) ($resolvedPrice ?? 0.0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    $fixedRows = [];
    $fixedPrincipalTokens = 0.0;
    $annualFixedTokens = 0.0;
    $termProjectedTokens = 0.0;
    $fixedPrincipalUsdTotal = 0.0;
    foreach ($fixedTerms as $row) {
        $principal = (float) ($row['principal_tokens'] ?? 0.0);
        $apy = (float) ($row['apy'] ?? 0.0);
        $months = max(1, (int) ($row['term_months'] ?? 12));
        $token = (string) ($row['token'] ?? '');
        $principalUsd = 0.0;
        if (nexo_is_eur_like_token($token)) {
            $principalUsd = $principal * $eurUsdRate;
        } else {
            $priceUsd = nexo_get_token_price_usd($userId, $token, nexo_default_coingecko_id_for_token($token), 21600);
            $principalUsd = $priceUsd !== null && $priceUsd > 0.0 ? ($principal * $priceUsd) : 0.0;
        }
        $dailyUsd = $principalUsd > 0.0 ? (($principalUsd * ($apy / 100.0)) / 365.0) : 0.0;
        $agg = $fixedRewardsByTerm[(int) ($row['id'] ?? 0)] ?? ['days_count' => 0, 'total_usd' => 0.0, 'total_nexo' => 0.0, 'last_day' => ''];
        $annual = $principal * ($apy / 100.0);
        $termYield = $annual * ($months / 12.0);
        $fixedPrincipalTokens += $principal;
        $annualFixedTokens += $annual;
        $termProjectedTokens += $termYield;
        $fixedPrincipalUsdTotal += $principalUsd;
        $fixedRows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'token' => $token,
            'principal_tokens' => $principal,
            'apy' => $apy,
            'term_months' => $months,
            'started_at' => (string) ($row['started_at'] ?? ''),
            'annual_tokens' => $annual,
            'term_tokens' => $termYield,
            'principal_usd' => $principalUsd,
            'daily_usd' => $dailyUsd,
            'days_generated' => (int) ($agg['days_count'] ?? 0),
            'generated_usd' => (float) ($agg['total_usd'] ?? 0.0),
            'generated_nexo' => (float) ($agg['total_nexo'] ?? 0.0),
            'last_generated_day' => (string) ($agg['last_day'] ?? ''),
        ];
    }

    $finalizedTotalUsd = 0.0;
    $finalizedTotalNexo = 0.0;
    $finalizedDays = 0;
    foreach ($finalizedFlexible as $row) {
        $finalizedTotalUsd += (float) ($row['total_usd'] ?? 0.0);
        $finalizedTotalNexo += (float) ($row['total_nexo'] ?? 0.0);
        $finalizedDays += (int) ($row['days_count'] ?? 0);
    }
    $flexTotalUsdAll = (float) ($flexRewardStats['total_usd'] ?? 0.0) + $finalizedTotalUsd;
    $flexTotalNexoAll = (float) ($flexRewardStats['total_nexo'] ?? 0.0) + $finalizedTotalNexo;
    $fixedTotalUsdAll = (float) ($fixedRewardStats['total_usd'] ?? 0.0);
    $fixedTotalNexoAll = (float) ($fixedRewardStats['total_nexo'] ?? 0.0);

    $principalUsdTotal = $flexPrincipalUsdTotal + $fixedPrincipalUsdTotal;
    $rewardUsdTotal = $flexTotalUsdAll + $fixedTotalUsdAll;
    $rewardNexoTotal = $flexTotalNexoAll + $fixedTotalNexoAll;
    $portfolioUsdTotal = $principalUsdTotal + $rewardUsdTotal;

    $flexRewardRate = $flexPrincipalUsdTotal > 0.0 ? ($flexTotalUsdAll / $flexPrincipalUsdTotal) * 100.0 : 0.0;
    $fixedRewardRate = $fixedPrincipalUsdTotal > 0.0 ? ($fixedTotalUsdAll / $fixedPrincipalUsdTotal) * 100.0 : 0.0;
    $rewardRateTotal = $principalUsdTotal > 0.0 ? ($rewardUsdTotal / $principalUsdTotal) * 100.0 : 0.0;
    $rewardDaysTotal = (int) ($flexRewardStats['total_rows'] ?? 0) + $finalizedDays + (int) ($fixedRewardStats['total_rows'] ?? 0);
    $rewardAvgDayUsd = $rewardDaysTotal > 0 ? ($rewardUsdTotal / $rewardDaysTotal) : 0.0;

    $segmentRows = [
        ['label' => 'Flexible principal', 'usd' => $flexPrincipalUsdTotal],
        ['label' => 'Fixed principal', 'usd' => $fixedPrincipalUsdTotal],
        ['label' => 'Rewards', 'usd' => $rewardUsdTotal],
    ];
    $segmentRows = array_values(array_filter($segmentRows, static fn($row) => (float) ($row['usd'] ?? 0.0) > 0.0));
    $segmentTotal = array_sum(array_map(static fn($row) => (float) ($row['usd'] ?? 0.0), $segmentRows));
    $palette = ['#60a5fa', '#34d399', '#fbbf24', '#f87171', '#a78bfa', '#22d3ee'];
    $portfolioChart = [];
    foreach ($segmentRows as $idx => $row) {
        $usd = (float) ($row['usd'] ?? 0.0);
        $pct = $segmentTotal > 0.0 ? ($usd / $segmentTotal) * 100.0 : 0.0;
        $portfolioChart[] = [
            'token' => (string) ($row['label'] ?? ''),
            'usd' => $usd,
            'pct' => $pct,
            'color' => $palette[$idx % count($palette)],
        ];
    }

    return [
        'activeTab' => $activeTab,
        'currentUsername' => current_username(),
        'nexoFormAction' => './nexo.php?tab=' . $activeTab,
        'nexoDashboard' => [
            'as_of' => substr((string) ($wallet['updated_at'] ?? db_now()), 0, 10),
            'eur_usd_rate' => $eurUsdRate,
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
            'recent_flexible_rewards' => $recentFlexibleRewards,
            'market_rows' => $marketRows,
            'flex_reward_stats' => $flexRewardStats,
            'flex_finalized_totals' => [
                'total_usd' => $finalizedTotalUsd,
                'total_nexo' => $finalizedTotalNexo,
                'days_total' => $finalizedDays,
            ],
            'flex_all_totals' => [
                'total_usd' => $flexTotalUsdAll,
                'total_nexo' => $flexTotalNexoAll,
            ],
            'fixed_all_totals' => [
                'total_usd' => $fixedTotalUsdAll,
                'total_nexo' => $fixedTotalNexoAll,
            ],
            'portfolio' => [
                'principal_usd' => $principalUsdTotal,
                'flex_principal_usd' => $flexPrincipalUsdTotal,
                'fixed_principal_usd' => $fixedPrincipalUsdTotal,
                'rewards_usd' => $rewardUsdTotal,
                'rewards_nexo' => $rewardNexoTotal,
                'total_usd' => $portfolioUsdTotal,
                'reward_rate_pct' => $rewardRateTotal,
                'reward_avg_day_usd' => $rewardAvgDayUsd,
                'flex_reward_rate_pct' => $flexRewardRate,
                'fixed_reward_rate_pct' => $fixedRewardRate,
                'reward_days' => $rewardDaysTotal,
            ],
            'portfolio_chart' => $portfolioChart,
            'flexible_count' => count($flexibleTerms),
            'fixed_count' => count($fixedTerms),
            'finalized_flexible' => $finalizedFlexible,
            'finalized_rewards_by_term' => $finalizedRewardsByTerm,
            'finalized_reward_rows' => $finalizedRewardRows,
            'fixed_rewards_by_term' => $fixedRewardsByTerm,
            'recent_fixed_rewards' => $recentFixedRewards,
            'fixed_reward_stats' => $fixedRewardStats,
        ],
    ];
}
