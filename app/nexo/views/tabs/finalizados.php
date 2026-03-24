<?php
/** @var array $nexoDashboard */
$d = (array) ($nexoDashboard ?? []);
$finalized = (array) ($d['finalized_flexible'] ?? []);
$rewardRows = (array) ($d['finalized_reward_rows'] ?? []);
$closedTotals = (array) ($d['flex_finalized_totals'] ?? []);
$closedUsd = (float) ($closedTotals['total_usd'] ?? 0.0);
$closedNexo = (float) ($closedTotals['total_nexo'] ?? 0.0);
$closedDays = (int) ($closedTotals['days_total'] ?? 0);
$closedAvgUsd = $closedDays > 0 ? ($closedUsd / $closedDays) : 0.0;
$lastClosed = '';
foreach ($finalized as $row) {
    $dt = (string) ($row['finalized_at'] ?? '');
    if ($dt !== '' && ($lastClosed === '' || strcmp($dt, $lastClosed) > 0)) {
        $lastClosed = $dt;
    }
}
?>

<section class="panel closed-hero">
  <div class="table-head">
    <div>
      <h3>Flexible Closed Overview</h3>
      <p class="muted">Closed terms with final realized rewards.</p>
    </div>
  </div>
  <div class="stats-grid closed-kpis">
    <article class="panel stat">
      <p>Closed terms</p>
      <h2><?= count($finalized) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total days</p>
      <h2><?= $closedDays ?></h2>
    </article>
    <article class="panel stat">
      <p>Avg/day USD</p>
      <h2><?= $closedAvgUsd > 0 ? format_money($closedAvgUsd) : '-' ?></h2>
    </article>
    <article class="panel stat">
      <p>Total generated USD</p>
      <h2><?= format_money($closedUsd) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total generated NEXO</p>
      <h2><?= format_number($closedNexo, 4) ?></h2>
    </article>
    <article class="panel stat">
      <p>Last closed</p>
      <h2><?= $lastClosed !== '' ? h(format_datetime_display($lastClosed)) : '-' ?></h2>
    </article>
  </div>
</section>

<section class="panel table-panel">
  <div class="table-head">
    <div>
      <h3>Flexible Closed</h3>
      <p class="status-line">Closed terms with details and generated fees.</p>
    </div>
    <div class="inline-actions">
      <span class="chip">Total <?= count($finalized) ?></span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="compact-table">
      <thead><tr><th>Term</th><th>Token</th><th>Generated</th><th>Timing</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (count($finalized) === 0): ?>
          <tr><td colspan="5" class="empty">No closed terms.</td></tr>
        <?php else: ?>
          <?php foreach ($finalized as $row): ?>
            <?php
              $id = (int) ($row['term_id'] ?? 0);
            ?>
            <tr>
              <td>
                <div class="metric-stack metric-primary">
                  <span class="metric-label">Term</span>
                  <span class="metric-value">#<?= (int) ($row['term_id'] ?? 0) ?></span>
                  <span class="metric-sub">APY <?= format_number((float) ($row['apy'] ?? 0.0), 2) ?>%</span>
                </div>
              </td>
              <td>
                <div class="metric-stack">
                  <span class="metric-label">Token</span>
                  <span class="metric-value"><?= h((string) ($row['token'] ?? '-')) ?></span>
                  <span class="metric-sub"><?= format_number((float) ($row['principal'] ?? 0.0), 6) ?></span>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
                  <div><span>USD</span><b><?= format_money((float) ($row['total_usd'] ?? 0.0)) ?></b></div>
                  <div><span>NEXO</span><b><?= format_number((float) ($row['total_nexo'] ?? 0.0), 6) ?></b></div>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
                  <div><span>Days</span><b><?= (int) ($row['days_count'] ?? 0) ?></b></div>
                  <div><span>Start</span><b><?= h(format_datetime_display((string) ($row['started_at'] ?? ''))) ?></b></div>
                  <div><span>Closed</span><b><?= h(format_datetime_display((string) ($row['finalized_at'] ?? ''))) ?></b></div>
                </div>
              </td>
              <td>
                <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="inline-form-tight" data-confirm="Remove this closed term?">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="nexo_delete_finalized_term" />
                  <input type="hidden" name="term_id" value="<?= (int) ($row['term_id'] ?? 0) ?>" />
                  <button type="submit" class="small danger">Remove</button>
                </form>
              </td>
            </tr>
            <?php if (isset($rewardRows[$id]) && count($rewardRows[$id]) > 0): ?>
              <tr class="pool-extra-row">
                <td colspan="5">
                  <div class="tracking-log-list">
                    <?php foreach ($rewardRows[$id] as $log): ?>
                      <div>
                        <?= h((string) ($log['reward_date'] ?? '')) ?> -
                        <?= format_number((float) ($log['reward_nexo'] ?? 0.0), 6) ?> NEXO /
                        <?= format_money((float) ($log['reward_usd'] ?? 0.0)) ?> -
                        NEXO/USD <?= format_number((float) ($log['nexo_price_usd'] ?? 0.0), 6) ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
