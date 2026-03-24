<?php
/** @var array $nexoDashboard */
$d = (array) ($nexoDashboard ?? []);
$asOf = (string) ($d['as_of'] ?? '');
$eurUsd = (float) ($d['eur_usd_rate'] ?? 0.0);
$nexoPrice = (float) ($d['nexo_usd_price'] ?? 0.0);
$flexCount = (int) ($d['flexible_count'] ?? 0);
$fixedCount = (int) ($d['fixed_count'] ?? 0);
$flexStats = (array) ($d['flex_reward_stats'] ?? []);
$fixedStats = (array) ($d['fixed_reward_stats'] ?? []);
$flexAll = (array) ($d['flex_all_totals'] ?? []);
$fixedAll = (array) ($d['fixed_all_totals'] ?? []);
$flexClosed = (array) ($d['flex_finalized_totals'] ?? []);
$portfolio = (array) ($d['portfolio'] ?? []);
$portfolioChart = (array) ($d['portfolio_chart'] ?? []);
$flexAllUsd = (float) ($flexAll['total_usd'] ?? 0.0);
$flexAllNexo = (float) ($flexAll['total_nexo'] ?? 0.0);
$fixedAllUsd = (float) ($fixedAll['total_usd'] ?? ($fixedStats['total_usd'] ?? 0.0));
$fixedAllNexo = (float) ($fixedAll['total_nexo'] ?? ($fixedStats['total_nexo'] ?? 0.0));
$flexDaysAll = (int) ($flexStats['total_rows'] ?? 0) + (int) ($flexClosed['days_total'] ?? 0);
$flexAvgDayAll = $flexDaysAll > 0 ? ($flexAllUsd / $flexDaysAll) : 0.0;
$fixedLogs = (int) ($fixedStats['total_rows'] ?? 0);
$fixedAvgDay = $fixedLogs > 0 ? ($fixedAllUsd / $fixedLogs) : 0.0;
$portfolioTotalUsd = (float) ($portfolio['total_usd'] ?? 0.0);
$principalUsd = (float) ($portfolio['principal_usd'] ?? 0.0);
$flexPrincipalUsd = (float) ($portfolio['flex_principal_usd'] ?? 0.0);
$fixedPrincipalUsd = (float) ($portfolio['fixed_principal_usd'] ?? 0.0);
$rewardsUsd = (float) ($portfolio['rewards_usd'] ?? ($flexAllUsd + $fixedAllUsd));
$rewardsNexo = (float) ($portfolio['rewards_nexo'] ?? ($flexAllNexo + $fixedAllNexo));
$rewardRate = (float) ($portfolio['reward_rate_pct'] ?? 0.0);
$rewardAvgDay = (float) ($portfolio['reward_avg_day_usd'] ?? 0.0);
$portfolioTotalEur = $eurUsd > 0 ? ($portfolioTotalUsd / $eurUsd) : 0.0;
$principalPct = $portfolioTotalUsd > 0 ? ($principalUsd / $portfolioTotalUsd) * 100.0 : 0.0;
$rewardsPct = $portfolioTotalUsd > 0 ? ($rewardsUsd / $portfolioTotalUsd) * 100.0 : 0.0;
$portfolioChartJson = (string) json_encode($portfolioChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>

<div class="dash-wrap">
  <section class="dash-hero">
    <article class="panel hero-card">
      <div class="hero-main">
        <div class="hero-left">
          <p class="hero-label">Total Portfolio</p>
          <h1 class="hero-value">€<?= format_number($portfolioTotalEur, 2) ?><span class="hero-value-sub"><?= format_money($portfolioTotalUsd) ?></span></h1>
          <div class="hero-sub">
            <span>As of <?= $asOf !== '' ? h($asOf) : '-' ?></span>
            <span>EUR/USD (EURC) <?= $eurUsd > 0 ? format_number($eurUsd, 4) : '-' ?></span>
            <span>NEXO/USD <?= $nexoPrice > 0 ? format_number($nexoPrice, 4) : '-' ?></span>
          </div>
        </div>
        <div class="hero-kpis">
          <div class="hero-kpi"><span>Flexible terms</span><b><?= $flexCount ?></b></div>
          <div class="hero-kpi"><span>Fixed terms</span><b><?= $fixedCount ?></b></div>
          <div class="hero-kpi"><span>ROI</span><b><?= $rewardRate > 0 ? format_number($rewardRate, 2) . '%' : '-' ?></b></div>
          <div class="hero-kpi"><span>Avg/day USD</span><b><?= $rewardAvgDay > 0 ? format_money($rewardAvgDay) : '-' ?></b></div>
        </div>
      </div>
    </article>
  </section>

  <section class="ops-layout">
    <div class="ops-left">
      <div class="dash-stack">
        <article class="panel summary-card">
          <h3>Flexible</h3>
          <div class="summary-list">
            <div class="simple-row"><span>Principal USD</span><b><?= format_money($flexPrincipalUsd) ?></b></div>
            <div class="simple-row"><span>Rewards USD</span><b><?= format_money($flexAllUsd) ?></b></div>
            <div class="simple-row"><span>ROI</span><b><?= (float) ($portfolio['flex_reward_rate_pct'] ?? 0.0) > 0 ? format_number((float) ($portfolio['flex_reward_rate_pct'] ?? 0.0), 2) . '%' : '-' ?></b></div>
            <div class="simple-row"><span>Avg/day USD</span><b><?= $flexAvgDayAll > 0 ? format_money($flexAvgDayAll) : '-' ?></b></div>
          </div>
        </article>
        <article class="panel summary-card">
          <h3>Fixed</h3>
          <div class="summary-list">
            <div class="simple-row"><span>Principal USD</span><b><?= format_money($fixedPrincipalUsd) ?></b></div>
            <div class="simple-row"><span>Rewards USD</span><b><?= format_money($fixedAllUsd) ?></b></div>
            <div class="simple-row"><span>ROI</span><b><?= (float) ($portfolio['fixed_reward_rate_pct'] ?? 0.0) > 0 ? format_number((float) ($portfolio['fixed_reward_rate_pct'] ?? 0.0), 2) . '%' : '-' ?></b></div>
            <div class="simple-row"><span>Avg/day USD</span><b><?= $fixedAvgDay > 0 ? format_money($fixedAvgDay) : '-' ?></b></div>
          </div>
        </article>
      </div>
    </div>
    <div class="ops-right">
      <section class="panel dash-module ops-module ops-stacked">
        <div class="table-head ops-head">
          <div>
            <h3>Portfolio Mix</h3>
            <p class="status-line">Flexible vs fixed vs rewards.</p>
          </div>
        </div>
        <div class="token-donut-interactive ops-top ops-top-stacked">
          <div class="token-donut-col">
            <canvas class="token-donut-canvas" width="260" height="260" data-token-chart='<?= h($portfolioChartJson) ?>'></canvas>
            <div class="token-donut-center">
              <b data-token-hover-label>Portfolio</b>
              <span data-token-hover-value>Hover the chart</span>
            </div>
          </div>
          <div class="table-wrap fit-wrap">
            <table class="compact-table fit-table token-table js-sortable-table" data-sort-default-col="1" data-sort-default-dir="desc">
              <thead><tr><th data-sort-type="text">Group</th><th data-sort-type="number">USD</th><th data-sort-type="number">%</th></tr></thead>
              <tbody>
                <?php if (count($portfolioChart) === 0): ?>
                  <tr><td colspan="3" class="empty">No portfolio distribution yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($portfolioChart as $idx => $row): ?>
                    <?php
                      $label = (string) ($row['token'] ?? '-');
                      $usd = (float) ($row['usd'] ?? 0.0);
                      $pct = (float) ($row['pct'] ?? 0.0);
                    ?>
                    <tr data-token-idx="<?= $idx ?>">
                      <td data-sort-value="<?= h($label) ?>"><span class="token-color" style="background: <?= h((string) ($row['color'] ?? '#60a5fa')) ?>;"></span><?= h($label) ?></td>
                      <td data-sort-value="<?= h((string) $usd) ?>"><?= format_money($usd) ?></td>
                      <td data-sort-value="<?= h((string) $pct) ?>"><?= format_number($pct, 1) ?>%</td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  </section>
</div>
