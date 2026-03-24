<?php
/** @var array $dash */
/** @var array $poolRows */
/** @var array $feeDaily */
/** @var array $monthlyPerformance */
/** @var array $trackingSources */
/** @var array $dashboardAnalytics */
/** @var array $dados */

$topPools = $poolRows;
usort($topPools, static fn($a, $b) => ((float) $b['roi']) <=> ((float) $a['roi']));
$topPools = array_slice($topPools, 0, 8);

$monthlyNow = count($monthlyPerformance) > 0 ? $monthlyPerformance[count($monthlyPerformance) - 1] : null;
$monthlyPrev = count($monthlyPerformance) > 1 ? $monthlyPerformance[count($monthlyPerformance) - 2] : null;
$currentMonthKey = date('Y-m');
$tokenRows = (array) ($dashboardAnalytics['by_token'] ?? []);
$iconMap = [
    'ZRO' => 'layerzero',
    'WLD' => 'worldcoin',
];
function token_icon_id(string $symbol, array $iconMap): string
{
    $tk = strtoupper(trim($symbol));
    $mapped = $iconMap[$tk] ?? $tk;
    return strtolower($mapped);
}

$palette = ['#34d399', '#60a5fa', '#fbbf24', '#f87171', '#a78bfa', '#22d3ee', '#f472b6', '#84cc16'];
$tokenChartRows = [];
foreach ($tokenRows as $idx => $row) {
    $tokenChartRows[] = [
        'token' => (string) ($row['token'] ?? ''),
        'pct' => (float) ($row['pct'] ?? 0.0),
        'usd' => (float) ($row['usd'] ?? 0.0),
        'qty' => (float) ($row['qty'] ?? 0.0),
        'color' => $palette[$idx % count($palette)],
    ];
}
$tokenChartJson = (string) json_encode($tokenChartRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$topToken = null;
$topTokenValue = -INF;
foreach ($tokenRows as $row) {
    $usd = (float) ($row['usd'] ?? 0.0);
    if ($usd > $topTokenValue) {
        $topTokenValue = $usd;
        $topToken = $row;
    }
}
$topChain = null;
$topChainValue = -INF;
foreach ((array) ($dashboardAnalytics['by_chain'] ?? []) as $row) {
    $value = (float) ($row['value'] ?? 0.0);
    if ($value > $topChainValue) {
        $topChainValue = $value;
        $topChain = $row;
    }
}
$topWallet = null;
$topWalletValue = -INF;
foreach ((array) ($dashboardAnalytics['by_wallet'] ?? []) as $row) {
    $value = (float) ($row['value'] ?? 0.0);
    if ($value > $topWalletValue) {
        $topWalletValue = $value;
        $topWallet = $row;
    }
}

$feeChartRows = array_slice($feeDaily, -7);
$feeChartMax = 0.0;
foreach ($feeChartRows as $fRow) {
    $feeChartMax = max($feeChartMax, abs((float) ($fRow['generated'] ?? 0.0)));
}
if ($feeChartMax <= 0.0) {
    $feeChartMax = 1.0;
}

$monthlyDetailMap = [];
foreach ($monthlyPerformance as $row) {
    $monthKey = (string) ($row['month'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $monthKey) !== 1) {
        continue;
    }
    $monthlyDetailMap[$monthKey] = [
        'summary' => [
            'fees' => (float) ($row['fees'] ?? 0.0),
            'inflow' => (float) ($row['inflow'] ?? 0.0),
            'outflow' => (float) ($row['outflow'] ?? 0.0),
            'net' => (float) ($row['net'] ?? 0.0),
            'tx_count' => (int) ($row['tx_count'] ?? 0),
        ],
        'rows' => [],
    ];
}
foreach ((array) $dados as $tx) {
    $monthKey = substr((string) ($tx['date'] ?? ''), 0, 7);
    if (!isset($monthlyDetailMap[$monthKey])) {
        continue;
    }
    $dateRaw = (string) ($tx['date'] ?? '');
    $monthlyDetailMap[$monthKey]['rows'][] = [
        'date' => $dateRaw,
        'date_label' => strlen($dateRaw) >= 16 ? substr($dateRaw, 0, 16) : $dateRaw,
        'pool_id' => (string) ($tx['pool_id'] ?? ''),
        'action' => (string) ($tx['action'] ?? ''),
        'pair' => strtoupper(trim((string) ($tx['asset_1'] ?? '') . ' / ' . (string) ($tx['asset_2'] ?? ''))),
        'total' => (float) ($tx['total'] ?? 0.0),
        'fees' => (float) ($tx['fees'] ?? 0.0),
        'wallet' => (string) ($tx['wallet'] ?? ''),
        'chain' => (string) ($tx['chain'] ?? ''),
    ];
}
foreach ($monthlyDetailMap as &$detail) {
    usort($detail['rows'], static fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
}
unset($detail);
$monthlyDetailJson = (string) json_encode($monthlyDetailMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>

<div class="dash-wrap">
<section class="dash-hero">
  <article class="panel hero-card">
    <div class="hero-main">
      <div class="hero-left">
        <p class="hero-label">Portfolio total</p>
        <h1 class="hero-value"><?= format_money((float) ($dash['total_usd'] ?? 0.0)) ?></h1>
        <div class="hero-sub">
          <?php $heroRoi = (float) ($dash['roi'] ?? 0.0); ?>
          <?php $heroFees7d = (float) ($dash['fees_7d'] ?? 0.0); ?>
          <span class="<?= $heroRoi < 0 ? 'error' : 'ok' ?>">ROI <?= format_money($heroRoi) ?></span>
          <span>Fees 7d <?= format_money($heroFees7d) ?></span>
          <?php $heroNetAlloc = (float) ($dashboardAnalytics['capital_net'] ?? 0.0); ?>
          <span class="<?= $heroNetAlloc < 0 ? 'error' : 'ok' ?>">Net allocation <?= format_money($heroNetAlloc) ?></span>
        </div>
      </div>
      <div class="hero-kpis">
        <div class="hero-kpi"><span>Pools</span><b><?= (int) ($dash['pool_count'] ?? 0) ?></b></div>
        <div class="hero-kpi"><span>Unclaimed</span><b><?= format_money((float) ($dash['unclaimed'] ?? 0.0)) ?></b></div>
        <div class="hero-kpi"><span>P/day</span><b><?= format_money((float) ($dash['pday'] ?? 0.0)) ?></b></div>
        <div class="hero-kpi"><span>Fees today</span><b><?= format_money((float) ($dash['fees_today'] ?? 0.0)) ?></b></div>
      </div>
    </div>
  </article>
</section>

<section class="dashboard-summary-grid dashboard-kpi-grid">
  <article class="panel summary-card">
    <h3>Capital Flow</h3>
    <div class="summary-list">
      <div class="simple-row"><span>Capital allocated</span><b><?= format_money((float) ($dashboardAnalytics['capital_in'] ?? 0.0)) ?></b></div>
      <div class="simple-row"><span>Capital withdrawn</span><b><?= format_money((float) ($dashboardAnalytics['capital_out'] ?? 0.0)) ?></b></div>
      <div class="simple-row"><span>Reinvested</span><b><?= format_money((float) ($dashboardAnalytics['capital_compounded'] ?? 0.0)) ?></b></div>
      <div class="simple-row"><span>Net allocation</span><b class="<?= (float) ($dashboardAnalytics['capital_net'] ?? 0.0) < 0 ? 'error' : 'ok' ?>"><?= format_money((float) ($dashboardAnalytics['capital_net'] ?? 0.0)) ?></b></div>
    </div>
  </article>
  <article class="panel summary-card">
    <h3>Operations</h3>
    <div class="summary-list">
      <div class="simple-row"><span>Total transactions</span><b><?= (int) ($dashboardAnalytics['tx_count'] ?? 0) ?></b></div>
      <div class="simple-row"><span>Tx per day</span><b><?= format_number((float) ($dashboardAnalytics['avg_tx_per_day'] ?? 0.0), 2) ?></b></div>
      <div class="simple-row"><span>Claims</span><b><?= (int) ($dashboardAnalytics['claims_count'] ?? 0) ?></b></div>
      <div class="simple-row"><span>Compounds</span><b><?= (int) ($dashboardAnalytics['compounds_count'] ?? 0) ?></b></div>
    </div>
  </article>
  <article class="panel summary-card">
    <h3>Portfolio Quality</h3>
    <div class="summary-list">
      <div class="simple-row"><span>Positive ROI pools</span><b><?= (int) ($dashboardAnalytics['positive_roi_pools'] ?? 0) ?>/<?= (int) ($dashboardAnalytics['pool_count'] ?? 0) ?></b></div>
      <div class="simple-row"><span>Yield &gt; 1 pools</span><b><?= (int) ($dashboardAnalytics['yield_above_one_pools'] ?? 0) ?>/<?= (int) ($dashboardAnalytics['pool_count'] ?? 0) ?></b></div>
      <div class="simple-row"><span>Closed pools</span><b><?= (int) ($dash['closed_pool_count'] ?? 0) ?></b></div>
      <div class="simple-row"><span>Top-3 concentration</span><b><?= format_number((float) ($dashboardAnalytics['top3_concentration_pct'] ?? 0.0), 1) ?>%</b></div>
    </div>
  </article>
</section>

<section class="dash-modules-grid">
  <article class="panel dash-module monthly-performance">
    <div class="monthly-head">
      <div>
        <h3>Monthly Performance</h3>
        <p class="status-line">Net = inflow - outflow. Fees are shown separately.</p>
      </div>
      <div class="monthly-legend">
        <span class="legend-chip inflow">Inflow</span>
        <span class="legend-chip outflow">Outflow</span>
        <span class="legend-chip fees">Fees</span>
        <span class="legend-chip net">Net</span>
      </div>
    </div>
    <div class="stats-grid stats-grid-mini monthly-kpis">
      <article class="panel stat">
        <p>Current month (net)</p>
        <h2 class="<?= $monthlyNow !== null && (float) ($monthlyNow['net'] ?? 0.0) < 0 ? 'error' : 'ok' ?>">
          <?= $monthlyNow !== null ? format_money((float) ($monthlyNow['net'] ?? 0.0)) : format_money(0.0) ?>
        </h2>
      </article>
      <article class="panel stat">
        <p>Previous month (net)</p>
        <h2 class="<?= $monthlyPrev !== null && (float) ($monthlyPrev['net'] ?? 0.0) < 0 ? 'error' : 'ok' ?>">
          <?= $monthlyPrev !== null ? format_money((float) ($monthlyPrev['net'] ?? 0.0)) : format_money(0.0) ?>
        </h2>
      </article>
      <article class="panel stat">
        <p>Average Fees/Day (30d)</p>
        <h2 class="fee-tone"><?= format_money((float) ($dashboardAnalytics['fees_daily_avg'] ?? 0.0)) ?></h2>
      </article>
    </div>

    <div class="fees-days-grid">
      <?php if (count($feeChartRows) === 0): ?>
        <div class="day-card empty">
          <div class="day-meta">No data</div>
          <div class="day-value">-</div>
        </div>
      <?php else: ?>
        <?php foreach ($feeChartRows as $row): ?>
          <?php
            $value = (float) ($row['generated'] ?? 0.0);
            $ratio = abs($value) / $feeChartMax;
            $height = $ratio > 0.0 ? max(2.0, min(100.0, $ratio * 100.0)) : 0.0;
            $date = (string) ($row['date'] ?? '');
            $label = strlen($date) >= 10 ? substr($date, 8, 2) . '-' . substr($date, 5, 2) : $date;
            $heightCss = number_format($height, 1, '.', '');
            $valueAbs = abs($value);
            $valueDec = ($valueAbs > 0.0 && $valueAbs < 0.01) ? 4 : 2;
            $valueDisplay = '$' . number_format($value, $valueDec, '.', '');
          ?>
          <div class="day-card" title="<?= h($date) ?> | <?= h($valueDisplay) ?>">
            <div class="day-track">
              <div class="day-bar <?= $value < 0 ? 'negative' : 'positive' ?>" style="height: <?= h($heightCss) ?>%"></div>
            </div>
            <div class="day-meta"><?= h($label) ?></div>
            <div class="day-value <?= $value < 0 ? 'error' : 'ok' ?>"><?= h($valueDisplay) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <p class="hint" style="margin-top:8px;">
      Inflow includes create + compound. Outflow includes remove. Fees are reported separately and do not affect net.
    </p>

    <div class="table-wrap">
      <table class="compact-table js-sortable-table monthly-table monthly-performance-table" data-sort-default-col="0" data-sort-default-dir="desc">
        <thead>
          <tr>
            <th data-sort-type="text">Month</th>
            <th data-sort-type="number">Fees</th>
            <th data-sort-type="number" title="Create + Compound">Inflow</th>
            <th data-sort-type="number" title="Remove">Outflow</th>
            <th data-sort-type="number" title="Net = Inflow - Outflow (fees shown separately)">Net</th>
            <th data-sort-type="number">Tx</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($monthlyPerformance) === 0): ?>
            <tr><td colspan="6" class="empty">No monthly data yet.</td></tr>
          <?php else: ?>
            <?php foreach (array_reverse($monthlyPerformance) as $row): ?>
              <tr>
                <?php $rowMonth = (string) ($row['month'] ?? ''); ?>
                <?php $isCurrentMonth = $rowMonth === $currentMonthKey; ?>
                <td data-sort-value="<?= h($rowMonth) ?>" class="<?= $isCurrentMonth ? 'month-current' : '' ?>">
                  <b><button type="button" class="month-link month-detail-btn" data-month-key="<?= h($rowMonth) ?>"><?= h($rowMonth) ?></button></b>
                </td>
                <td data-sort-value="<?= h((string) ((float) ($row['fees'] ?? 0.0))) ?>" class="num"><?= format_money((float) ($row['fees'] ?? 0.0)) ?></td>
                <td data-sort-value="<?= h((string) ((float) ($row['inflow'] ?? 0.0))) ?>" class="num"><?= format_money((float) ($row['inflow'] ?? 0.0)) ?></td>
                <td data-sort-value="<?= h((string) ((float) ($row['outflow'] ?? 0.0))) ?>" class="num"><?= format_money((float) ($row['outflow'] ?? 0.0)) ?></td>
                <?php $netVal = (float) ($row['net'] ?? 0.0); ?>
                <td data-sort-value="<?= h((string) $netVal) ?>" class="<?= $netVal < 0 ? 'error' : 'ok' ?> <?= $isCurrentMonth ? 'month-current' : '' ?>">
                  <span class="value-badge <?= $netVal < 0 ? 'error' : 'ok' ?>"><?= format_money($netVal) ?></span>
                </td>
                <td data-sort-value="<?= h((string) ((int) ($row['tx_count'] ?? 0))) ?>" class="num"><?= (int) ($row['tx_count'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <dialog class="month-modal" id="monthlyDetailsModal" data-monthly-details='<?= h($monthlyDetailJson) ?>'>
      <div class="month-modal-head">
        <h3 data-month-modal-title>Monthly details</h3>
        <button type="button" class="x-min" data-month-modal-close>x</button>
      </div>
      <div class="stats-grid stats-grid-mini month-modal-stats">
        <article class="panel stat"><p>Fees</p><h2 data-month-fees>$0.00</h2></article>
        <article class="panel stat"><p>Inflow</p><h2 data-month-inflow>$0.00</h2></article>
        <article class="panel stat"><p>Outflow</p><h2 data-month-outflow>$0.00</h2></article>
        <article class="panel stat"><p>Net</p><h2 data-month-net>$0.00</h2></article>
        <article class="panel stat"><p>Tx</p><h2 data-month-txcount>0</h2></article>
      </div>
      <div class="table-wrap">
        <table class="compact-table">
          <thead><tr><th>Date</th><th>Pool</th><th>Action</th><th>Pair</th><th>Total</th><th>Fees</th><th>Wallet</th><th>Chain</th></tr></thead>
          <tbody data-month-modal-rows>
            <tr><td colspan="8" class="empty">No transactions this month.</td></tr>
          </tbody>
        </table>
      </div>
    </dialog>
  </article>

  <article class="panel dash-module ops-module ops-stacked">
    <div class="table-head ops-head">
      <div>
        <h3>Operational Distribution</h3>
        <p class="status-line">Portfolio mix by token, chain and wallet.</p>
      </div>
      <div class="ops-actions">
        <button class="tiny" type="submit" form="tokenTargetsForm">Save targets</button>
      </div>
    </div>
    <div class="ops-kpis">
      <div class="ops-kpi">
        <span>Top token</span>
        <b><?= $topToken ? h((string) ($topToken['token'] ?? '-')) : '-' ?></b>
        <em><?= $topToken ? format_money((float) ($topToken['usd'] ?? 0.0)) : '-' ?></em>
      </div>
      <div class="ops-kpi">
        <span>Top chain</span>
        <b><?= $topChain ? h((string) ($topChain['label'] ?? '-')) : '-' ?></b>
        <em><?= $topChain ? format_money((float) ($topChain['value'] ?? 0.0)) : '-' ?></em>
      </div>
      <div class="ops-kpi">
        <span>Top wallet</span>
        <b><?= $topWallet ? h((string) ($topWallet['label'] ?? '-')) : '-' ?></b>
        <em><?= $topWallet ? format_money((float) ($topWallet['value'] ?? 0.0)) : '-' ?></em>
      </div>
      <div class="ops-kpi">
        <span>Tokens tracked</span>
        <b><?= count($tokenRows) ?></b>
        <em><?= count($tokenRows) > 0 ? format_number((float) array_sum(array_map(static fn($r) => (float) ($r['pct'] ?? 0.0), $tokenRows)), 1) . '%' : '-' ?></em>
      </div>
    </div>
    <form method="post" id="tokenTargetsForm" class="token-donut-interactive ops-top ops-top-stacked">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="save_token_targets_batch" />
      <input type="hidden" name="tab" value="dashboard" />
      <div class="token-donut-col">
        <canvas class="token-donut-canvas" width="260" height="260" data-token-chart='<?= h($tokenChartJson) ?>'></canvas>
        <div class="token-donut-center">
          <b data-token-hover-label>Portfolio</b>
          <span data-token-hover-value>Hover the chart</span>
        </div>
      </div>
      <div class="table-wrap fit-wrap">
        <table class="compact-table fit-table token-table js-sortable-table" data-sort-default-col="2" data-sort-default-dir="desc">
          <thead><tr><th data-sort-type="text">Token</th><th data-sort-type="number">Qty</th><th data-sort-type="number">USD</th><th data-sort-type="number">%</th><th data-sort-type="number">Target %</th><th data-sort-type="number">Drift</th></tr></thead>
          <tbody>
            <?php if (count($tokenRows) === 0): ?>
              <tr><td colspan="6" class="empty">No token distribution yet.</td></tr>
            <?php else: ?>
              <?php foreach ($tokenRows as $idx => $row): ?>
                <?php $color = $palette[$idx % count($palette)]; ?>
                <?php $dev = (float) ($row['deviation_pp'] ?? 0.0); ?>
                <tr data-token-idx="<?= $idx ?>">
                  <td data-sort-value="<?= h((string) ($row['token'] ?? '')) ?>"><span class="token-color" style="background: <?= h($color) ?>;"></span><?= h((string) ($row['token'] ?? '')) ?></td>
                  <td data-sort-value="<?= h((string) ((float) ($row['qty'] ?? 0.0))) ?>"><?= format_number((float) ($row['qty'] ?? 0.0), 6) ?></td>
                  <td data-sort-value="<?= h((string) ((float) ($row['usd'] ?? 0.0))) ?>"><?= format_money((float) ($row['usd'] ?? 0.0)) ?></td>
                  <td data-sort-value="<?= h((string) ((float) ($row['pct'] ?? 0.0))) ?>"><?= format_number((float) ($row['pct'] ?? 0.0), 1) ?>%</td>
                  <?php $targetPct = (float) ($row['target_pct'] ?? 0.0); ?>
                  <?php $targetPctInt = (int) round($targetPct); ?>
                  <td data-sort-value="<?= h((string) $targetPctInt) ?>">
                    <input class="target-inline-input" type="number" name="target_pct[<?= h((string) ($row['token'] ?? '')) ?>]" step="1" min="0" max="100" inputmode="numeric" value="<?= h((string) $targetPctInt) ?>" />
                  </td>
                  <td data-sort-value="<?= h((string) $dev) ?>" class="<?= $dev < 0 ? 'error' : 'ok' ?>"><?= format_number($dev, 1) ?>pp</td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </form>

    <div class="ops-bottom ops-stacked">
      <div class="table-wrap fit-wrap">
        <table class="compact-table fit-table js-sortable-table" data-sort-default-col="1" data-sort-default-dir="desc">
          <thead><tr><th data-sort-type="text">Chain</th><th data-sort-type="number">Total</th><th data-sort-type="number">%</th></tr></thead>
          <tbody>
            <?php foreach ((array) ($dashboardAnalytics['by_chain'] ?? []) as $row): ?>
              <tr>
                <td data-sort-value="<?= h((string) ($row['label'] ?? '')) ?>"><?= h((string) ($row['label'] ?? '')) ?></td>
                <td data-sort-value="<?= h((string) ((float) ($row['value'] ?? 0.0))) ?>"><?= format_money((float) ($row['value'] ?? 0.0)) ?></td>
                <td data-sort-value="<?= h((string) ((float) ($row['pct'] ?? 0.0))) ?>"><?= format_number((float) ($row['pct'] ?? 0.0), 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="table-wrap fit-wrap">
        <table class="compact-table fit-table js-sortable-table" data-sort-default-col="1" data-sort-default-dir="desc">
          <thead><tr><th data-sort-type="text">Wallet</th><th data-sort-type="number">Total</th><th data-sort-type="number">%</th></tr></thead>
          <tbody>
            <?php foreach ((array) ($dashboardAnalytics['by_wallet'] ?? []) as $row): ?>
              <tr>
                <td data-sort-value="<?= h((string) ($row['label'] ?? '')) ?>"><?= h((string) ($row['label'] ?? '')) ?></td>
                <td data-sort-value="<?= h((string) ((float) ($row['value'] ?? 0.0))) ?>"><?= format_money((float) ($row['value'] ?? 0.0)) ?></td>
                <td data-sort-value="<?= h((string) ((float) ($row['pct'] ?? 0.0))) ?>"><?= format_number((float) ($row['pct'] ?? 0.0), 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </article>
</section>

</div>
