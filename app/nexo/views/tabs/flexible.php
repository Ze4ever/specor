<?php
/** @var array $nexoDashboard */
$d = (array) ($nexoDashboard ?? []);
$flexibleRows = (array) ($d['flexible_rows'] ?? []);
$dailyLogs = (array) ($d['recent_flexible_rewards'] ?? []);
$stats = (array) ($d['flex_reward_stats'] ?? []);
$closedTotals = (array) ($d['flex_finalized_totals'] ?? []);
$allTotals = (array) ($d['flex_all_totals'] ?? []);
$lastLogDay = (string) ($stats['last_day'] ?? '');
$avgPrice30d = (float) ($stats['avg_price_30d'] ?? 0.0);
$marketRows = (array) ($d['market_rows'] ?? []);
$priceMap = [];
$minStart = '';
$today = gmdate('Y-m-d');
$closedUsd = (float) ($closedTotals['total_usd'] ?? 0.0);
$closedNexo = (float) ($closedTotals['total_nexo'] ?? 0.0);
$closedDays = (int) ($closedTotals['days_total'] ?? 0);
$closedAvgUsd = $closedDays > 0 ? ($closedUsd / $closedDays) : 0.0;
$allUsd = (float) ($allTotals['total_usd'] ?? 0.0);
$allNexo = (float) ($allTotals['total_nexo'] ?? 0.0);
$activeUsd = (float) ($stats['total_usd'] ?? 0.0);
$activeNexo = (float) ($stats['total_nexo'] ?? 0.0);
$activeRows = (int) ($stats['total_rows'] ?? 0);
$activeAvgUsd = $activeRows > 0 ? ($activeUsd / $activeRows) : 0.0;
$closedCount = count((array) ($d['finalized_flexible'] ?? []));
$activePrincipalUsd = 0.0;
$activeDailyUsd = 0.0;
$activeApyWeighted = 0.0;
foreach ($flexibleRows as $row) {
    $principalUsd = (float) ($row['principal_usd'] ?? 0.0);
    $activePrincipalUsd += $principalUsd;
    $activeDailyUsd += (float) ($row['daily_usd'] ?? 0.0);
    $activeApyWeighted += $principalUsd * (float) ($row['apy'] ?? 0.0);
}
$activeApyAvg = $activePrincipalUsd > 0 ? ($activeApyWeighted / $activePrincipalUsd) : 0.0;
foreach ($marketRows as $row) {
    $token = strtoupper(trim((string) ($row['token'] ?? '')));
    $cgId = trim((string) ($row['coingecko_id'] ?? ''));
    if ($token !== '' && $cgId !== '') {
        $priceMap[$token] = $cgId;
    }
}
foreach ($flexibleRows as $row) {
    $token = strtoupper(trim((string) ($row['token'] ?? '')));
    if ($token === '' || isset($priceMap[$token])) {
        continue;
    }
    $cgId = trim((string) ($row['coingecko_id'] ?? ''));
    if ($cgId === '') {
        $cgId = nexo_default_coingecko_id_for_token($token);
    }
    if ($cgId !== '') {
        $priceMap[$token] = $cgId;
    }
}
foreach ($flexibleRows as $row) {
    $started = substr((string) ($row['started_at'] ?? ''), 0, 10);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $started) !== 1) {
        continue;
    }
    if ($minStart === '' || $started < $minStart) {
        $minStart = $started;
    }
}
if (!isset($priceMap['NEXO'])) {
    $priceMap['NEXO'] = 'nexo';
}
if (!isset($priceMap['EURC'])) {
    $priceMap['EURC'] = 'euro-coin';
}
$logCount = count($dailyLogs);
$logUsdSum = 0.0;
$logNexoSum = 0.0;
$logTokenSet = [];
foreach ($dailyLogs as $row) {
    $logUsdSum += (float) ($row['reward_usd'] ?? 0.0);
    $logNexoSum += (float) ($row['reward_nexo'] ?? 0.0);
    $tk = strtoupper(trim((string) ($row['token'] ?? '')));
    if ($tk !== '') {
        $logTokenSet[$tk] = true;
    }
}
$logTokenCount = count($logTokenSet);
?>

<section class="panel closed-hero">
  <div class="table-head">
    <div>
      <h3>Flexible Overview</h3>
      <p class="muted">Active positions summary. EUR tokens use EURC for daily EUR/USD.</p>
    </div>
  </div>
  <div class="stats-grid closed-kpis">
    <article class="panel stat">
      <p>Active terms</p>
      <h2><?= count($flexibleRows) ?></h2>
    </article>
    <article class="panel stat">
      <p>Invested USD (active)</p>
      <h2><?= format_money($activePrincipalUsd) ?></h2>
    </article>
    <article class="panel stat">
      <p>Daily pace USD</p>
      <h2><?= $activeDailyUsd > 0 ? format_money($activeDailyUsd) : '-' ?></h2>
    </article>
    <article class="panel stat">
      <p>Rewards USD (active)</p>
      <h2><?= format_money($activeUsd) ?></h2>
    </article>
    <article class="panel stat">
      <p>Rewards NEXO (active)</p>
      <h2><?= format_number($activeNexo, 4) ?></h2>
    </article>
    <article class="panel stat">
      <p>Avg APY (active)</p>
      <h2><?= $activeApyAvg > 0 ? format_number($activeApyAvg, 2) . '%' : '-' ?></h2>
    </article>
    <article class="panel stat">
      <p>Last log</p>
      <h2><?= $lastLogDay !== '' ? h($lastLogDay) : '-' ?></h2>
    </article>
  </div>
</section>

<section class="panel table-panel">
  <div class="table-head">
    <div>
      <h3>Flexible Terms</h3>
      <p class="status-line">Add a term, then review performance below. APY blank = default for EUR tokens.</p>
    </div>
  </div>
  <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="form-grid" style="margin-bottom:14px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="nexo_add_flexible_term" />
    <label>Token
      <input name="token" type="text" value="EURC" placeholder="EURC" required />
    </label>
    <label>Amount
      <input name="principal" type="number" step="0.000001" min="0.000001" placeholder="0.00" required />
    </label>
    <label>APY %
      <input name="apy" type="number" step="0.01" min="0" placeholder="Leave empty for default" />
    </label>
    <label>Deposit date
      <input name="started_at" type="datetime-local" required />
    </label>
    <button type="submit">Add term</button>
  </form>
  <p class="hint">Daily logs use the day's price. Use "Refresh logs" to fill older days.</p>
  <div class="table-wrap">
    <table class="compact-table">
      <thead><tr><th>Position</th><th>Invested</th><th>Pace</th><th>Rewards</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (count($flexibleRows) === 0): ?>
          <tr><td colspan="5" class="empty">No flexible terms.</td></tr>
        <?php else: ?>
          <?php foreach ($flexibleRows as $row): ?>
            <tr>
              <td>
                <?php
                  $startLabel = format_datetime_display((string) ($row['started_at'] ?? ''));
                  $lastDay = (string) ($row['last_generated_day'] ?? '');
                  $subLine = $lastDay !== '' ? ('Start ' . $startLabel . ' | Last ' . $lastDay) : ('Start ' . $startLabel);
                ?>
                <div class="metric-stack metric-primary">
                  <span class="metric-label">Position</span>
                  <span class="metric-value">#<?= (int) ($row['id'] ?? 0) ?> | <?= h((string) ($row['token'] ?? '-')) ?></span>
                  <span class="metric-sub"><?= h($subLine) ?></span>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
                  <div><span>Tokens</span><b><?= format_number((float) ($row['principal'] ?? 0.0), 6) ?></b></div>
                  <div><span>Base USD</span><b><?= format_money((float) ($row['principal_usd'] ?? 0.0)) ?></b></div>
                  <div><span>APY</span><b><?= format_number((float) ($row['apy'] ?? 0.0), 2) ?>%</b></div>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
                  <div><span>Daily USD</span><b><?= format_money((float) ($row['daily_usd'] ?? 0.0)) ?></b></div>
                  <div><span>Days</span><b><?= (int) ($row['days_generated'] ?? 0) ?></b></div>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
                  <div><span>USD</span><b><?= format_money((float) ($row['generated_usd'] ?? 0.0)) ?></b></div>
                  <div><span>NEXO</span><b><?= format_number((float) ($row['generated_nexo'] ?? 0.0), 6) ?></b></div>
                </div>
              </td>
              <td>
                <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="inline-form-tight">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="nexo_finalize_flexible_term" />
                  <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                  <button type="submit" class="small">Close</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel table-panel" data-coingecko-map='<?= h((string) json_encode($priceMap, JSON_UNESCAPED_SLASHES)) ?>'>
  <div class="table-head">
    <div>
      <h3>Daily Log (00:00 UTC)</h3>
      <p class="status-line">Daily rewards per term. EUR tokens use EURC FX for USD conversion.</p>
    </div>
    <div class="inline-actions">
      <span class="chip">Logs <?= $logCount ?></span>
      <span class="chip">USD <?= format_money($logUsdSum) ?></span>
      <span class="chip">NEXO <?= format_number($logNexoSum, 4) ?></span>
      <span class="chip">Tokens <?= $logTokenCount ?></span>
      <span class="chip">Range <?= $minStart !== '' ? h($minStart) : '-' ?> -> <?= h($today) ?></span>
      <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" id="nexoRefreshLogsForm" class="inline-form-tight" data-confirm="Refresh logs? This will regenerate the entire range.">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="nexo_refresh_flexible_logs" />
        <input type="hidden" id="nexoPriceHistoryJson" name="price_history_json" value="" />
        <input type="hidden" id="nexoRefreshStart" name="start_date" value="<?= h($minStart) ?>" />
        <input type="hidden" id="nexoRefreshEnd" name="end_date" value="<?= h($today) ?>" />
        <button type="button" id="nexoRefreshLogsBtn" class="small">Refresh logs</button>
      </form>
      <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="inline-form-tight" data-confirm="Delete ALL daily logs?">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="nexo_delete_all_flexible_rewards" />
        <button type="submit" class="small danger">Delete logs</button>
      </form>
    </div>
  </div>
  <div class="table-wrap">
    <table class="compact-table">
      <thead><tr><th>Log</th><th>Token & FX</th><th>Reward</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (count($dailyLogs) === 0): ?>
          <tr><td colspan="4" class="empty">No daily logs yet.</td></tr>
        <?php else: ?>
          <?php foreach ($dailyLogs as $row): ?>
            <?php
              $token = strtoupper((string) ($row['token'] ?? ''));
              $eurRate = (float) ($row['eur_usd_rate'] ?? 0.0);
              $dailyEur = (float) ($row['daily_eur'] ?? 0.0);
              $nexoPrice = (float) ($row['nexo_price_usd'] ?? 0.0);
              $isEurLike = in_array($token, ['EUR', 'EURX', 'EURS', 'EURC'], true);
            ?>
            <tr>
              <td>
                <div class="metric-stack metric-primary">
                  <span class="metric-label">Date</span>
                  <span class="metric-value"><?= h((string) ($row['reward_date'] ?? '-')) ?></span>
                  <span class="metric-sub">Term #<?= (int) ($row['term_id'] ?? 0) ?></span>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
                  <div><span>Token</span><b><?= h($token !== '' ? $token : '-') ?></b></div>
                  <?php if ($isEurLike): ?>
                    <div><span>EUR/USD</span><b><?= $eurRate > 0 ? format_number($eurRate, 4) : '-' ?></b></div>
                    <div><span>Daily EUR</span><b><?= $dailyEur > 0 ? format_number($dailyEur, 4) : '-' ?></b></div>
                  <?php else: ?>
                    <div><span>NEXO/USD</span><b><?= $nexoPrice > 0 ? format_number($nexoPrice, 6) : '-' ?></b></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
                  <div><span>USD</span><b><?= format_money((float) ($row['reward_usd'] ?? 0.0)) ?></b></div>
                  <div><span>NEXO</span><b><?= format_number((float) ($row['reward_nexo'] ?? 0.0), 6) ?></b></div>
                </div>
              </td>
              <td>
                <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="inline-form-tight" data-confirm="Remove this daily log?">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="nexo_delete_flexible_reward" />
                  <input type="hidden" name="reward_id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                  <button type="submit" class="danger small">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

