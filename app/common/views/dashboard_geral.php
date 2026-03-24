<?php
/** @var array $overview */
/** @var array $nexoDashboard */
/** @var array $poolRows */
/** @var array $dashboardAnalytics */
/** @var array $dash */
/** @var array $dados */

$overview = (array) ($overview ?? []);
$uniswap = (array) ($overview['uniswap'] ?? []);
$nexo = (array) ($overview['nexo'] ?? []);
$combined = (array) ($overview['combined'] ?? []);
$nexoDashboard = (array) ($nexoDashboard ?? []);

$totalUsd = (float) ($combined['total_usd'] ?? 0.0);
$uniswapTotal = (float) ($uniswap['total_usd'] ?? 0.0);
$nexoTotal = (float) ($nexo['total_usd'] ?? 0.0);

$poolRows = (array) ($poolRows ?? []);
$dados = (array) ($dados ?? []);
$dashboardAnalytics = (array) ($dashboardAnalytics ?? []);
$dash = (array) ($dash ?? []);

$topPools = $poolRows;
usort($topPools, static fn($a, $b) => ((float) ($b['total_now'] ?? 0.0)) <=> ((float) ($a['total_now'] ?? 0.0)));
$topPools = array_slice($topPools, 0, 5);

$recentTx = array_slice(array_reverse($dados), 0, 6);
$recentRewards = array_slice((array) ($nexoDashboard['recent_flexible_rewards'] ?? []), 0, 6);
?>

<div class="dash-wrap">
  <section class="dash-hero">
    <article class="panel hero-card">
      <div class="hero-main">
        <div class="hero-left">
          <p class="hero-label">Total portfolio</p>
          <h1 class="hero-value"><?= format_money($totalUsd) ?></h1>
          <div class="hero-sub">
            <span>Uniswap <?= format_money($uniswapTotal) ?></span>
            <span>NEXO <?= format_money($nexoTotal) ?></span>
          </div>
        </div>
        <div class="hero-kpis">
          <div class="hero-kpi"><span>Pools</span><b><?= (int) ($uniswap['pool_count'] ?? 0) ?></b></div>
          <div class="hero-kpi"><span>P/day</span><b><?= format_money((float) ($uniswap['pday'] ?? 0.0)) ?></b></div>
          <div class="hero-kpi"><span>Fees 7d</span><b><?= format_money((float) ($uniswap['fees_7d'] ?? 0.0)) ?></b></div>
          <div class="hero-kpi"><span>Rewards 30d</span><b><?= format_money((float) ($nexo['rewards_30d_usd'] ?? 0.0)) ?></b></div>
        </div>
      </div>
    </article>
  </section>

  <section class="dashboard-summary-grid dashboard-kpi-grid">
    <article class="panel summary-card">
      <h3>Uniswap Overview</h3>
      <div class="summary-list">
        <div class="simple-row"><span>Total USD</span><b><?= format_money($uniswapTotal) ?></b></div>
        <div class="simple-row"><span>ROI</span><b class="<?= (float) ($uniswap['roi'] ?? 0.0) < 0 ? 'error' : 'ok' ?>"><?= format_money((float) ($uniswap['roi'] ?? 0.0)) ?></b></div>
        <div class="simple-row"><span>Weighted APR</span><b><?= format_number((float) ($uniswap['weighted_apr'] ?? 0.0) * 100.0, 2) ?>%</b></div>
        <div class="simple-row"><span>Unclaimed</span><b><?= format_money((float) ($uniswap['unclaimed'] ?? 0.0)) ?></b></div>
      </div>
      <div class="inline-actions" style="margin-top:10px;">
        <a class="small" href="./uniswap.php?tab=dashboard">Open Uniswap</a>
      </div>
    </article>

    <article class="panel summary-card">
      <h3>NEXO Overview</h3>
      <div class="summary-list">
        <div class="simple-row"><span>Total USD</span><b><?= format_money($nexoTotal) ?></b></div>
        <div class="simple-row"><span>EURX USD</span><b><?= format_money((float) ($nexo['eurx_usd'] ?? 0.0)) ?></b></div>
        <div class="simple-row"><span>NEXO USD</span><b><?= format_money((float) ($nexo['nexo_usd'] ?? 0.0)) ?></b></div>
        <div class="simple-row"><span>Rewards 7d</span><b><?= format_money((float) ($nexo['rewards_7d_usd'] ?? 0.0)) ?></b></div>
      </div>
      <div class="inline-actions" style="margin-top:10px;">
        <a class="small" href="./nexo.php?tab=dashboard">Open NEXO</a>
      </div>
    </article>

    <article class="panel summary-card">
      <h3>Health Snapshot</h3>
      <div class="summary-list">
        <div class="simple-row"><span>Positive ROI pools</span><b><?= (int) ($dashboardAnalytics['positive_roi_pools'] ?? 0) ?>/<?= (int) ($dashboardAnalytics['pool_count'] ?? 0) ?></b></div>
        <div class="simple-row"><span>Yield > 1 pools</span><b><?= (int) ($dashboardAnalytics['yield_above_one_pools'] ?? 0) ?>/<?= (int) ($dashboardAnalytics['pool_count'] ?? 0) ?></b></div>
        <div class="simple-row"><span>Best month</span><b class="ok"><?= h((string) (($dashboardAnalytics['best_month']['month'] ?? '-'))) ?></b></div>
        <div class="simple-row"><span>Worst month</span><b class="error"><?= h((string) (($dashboardAnalytics['worst_month']['month'] ?? '-'))) ?></b></div>
      </div>
    </article>
  </section>

  <section class="dash-modules-grid">
    <article class="panel dash-module">
      <div class="table-head">
        <h3>Top Pools</h3>
        <p class="status-line">Largest pools by current value.</p>
      </div>
      <div class="table-wrap">
        <table class="compact-table">
          <thead><tr><th>Pool</th><th>Pair</th><th>Total</th><th>ROI</th><th>APR</th></tr></thead>
          <tbody>
            <?php if (count($topPools) === 0): ?>
              <tr><td colspan="5" class="empty">No active pools.</td></tr>
            <?php else: ?>
              <?php foreach ($topPools as $pool): ?>
                <?php $roiClass = (float) ($pool['roi'] ?? 0.0) < 0 ? 'error' : 'ok'; ?>
                <tr>
                  <td><?= h((string) ($pool['pool_id'] ?? '')) ?></td>
                  <td><?= h((string) ($pool['asset_1'] ?? '')) ?> / <?= h((string) ($pool['asset_2'] ?? '')) ?></td>
                  <td class="num"><?= format_money((float) ($pool['total_now'] ?? 0.0)) ?></td>
                  <td class="<?= $roiClass ?>"><?= format_money((float) ($pool['roi'] ?? 0.0)) ?></td>
                  <td class="num"><?= format_number((float) ($pool['apr'] ?? 0.0) * 100.0, 2) ?>%</td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </article>

    <article class="panel dash-module">
      <div class="table-head">
        <h3>Recent Uniswap Activity</h3>
        <p class="status-line">Latest transactions across pools.</p>
      </div>
      <div class="table-wrap">
        <table class="compact-table">
          <thead><tr><th>Date</th><th>Pool</th><th>Action</th><th>Total</th></tr></thead>
          <tbody>
            <?php if (count($recentTx) === 0): ?>
              <tr><td colspan="4" class="empty">No transactions.</td></tr>
            <?php else: ?>
              <?php foreach ($recentTx as $row): ?>
                <tr>
                  <td><?= h(format_datetime_display((string) ($row['date'] ?? ''))) ?></td>
                  <td><?= h((string) ($row['pool_id'] ?? '')) ?></td>
                  <td><?= h((string) ($row['action'] ?? '')) ?></td>
                  <td class="num"><?= format_money((float) ($row['total'] ?? 0.0)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </article>
  </section>

  <section class="dash-modules-grid">
    <article class="panel dash-module">
      <div class="table-head">
        <h3>NEXO Rewards (Recent)</h3>
        <p class="status-line">Latest daily rewards.</p>
      </div>
      <div class="table-wrap">
        <table class="compact-table">
          <thead><tr><th>Date</th><th>Token</th><th>NEXO</th><th>USD</th></tr></thead>
          <tbody>
            <?php if (count($recentRewards) === 0): ?>
              <tr><td colspan="4" class="empty">No rewards yet.</td></tr>
            <?php else: ?>
              <?php foreach ($recentRewards as $row): ?>
                <tr>
                  <td><?= h((string) ($row['reward_date'] ?? '')) ?></td>
                  <td><?= h((string) ($row['token'] ?? '')) ?></td>
                  <td><?= format_number((float) ($row['reward_nexo'] ?? 0.0), 6) ?></td>
                  <td><?= format_money((float) ($row['reward_usd'] ?? 0.0)) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </article>

    <article class="panel dash-module">
      <div class="table-head">
        <h3>Action Shortcuts</h3>
        <p class="status-line">Jump straight into operations.</p>
      </div>
      <div class="tracking-grid">
        <article class="tracking-card active">
          <div class="tracking-head"><b>Uniswap Pools</b><span class="chip">app</span></div>
          <p class="muted">Manage pools, fees and transactions.</p>
          <div class="inline-actions">
            <a class="tiny" href="./uniswap.php?tab=pools">Pools</a>
            <a class="tiny" href="./uniswap.php?tab=fees">Fees</a>
            <a class="tiny" href="./uniswap.php?tab=transactions">Transactions</a>
          </div>
        </article>
        <article class="tracking-card active">
          <div class="tracking-head"><b>NEXO Wallet</b><span class="chip">app</span></div>
          <p class="muted">Flexible, fixed, market, and transactions.</p>
          <div class="inline-actions">
            <a class="tiny" href="./nexo.php?tab=flexible">Flexible</a>
            <a class="tiny" href="./nexo.php?tab=fixed">Fixed</a>
            <a class="tiny" href="./nexo.php?tab=transactions">Transactions</a>
          </div>
        </article>
      </div>
    </article>
  </section>
</div>
