<?php
/** @var array $closedPools */
$closedCount = count($closedPools);
$totalInitial = array_sum(array_map(static fn($r) => (float) ($r['initial_total'] ?? 0.0), $closedPools));
$totalFinal = array_sum(array_map(static fn($r) => (float) ($r['total_now'] ?? 0.0), $closedPools));
$totalFees = array_sum(array_map(static fn($r) => (float) ($r['unclaimed'] ?? 0.0) + (float) ($r['claimed'] ?? 0.0), $closedPools));
$totalPnl = $totalFinal - $totalInitial;
$avgDays = $closedCount > 0
  ? array_sum(array_map(static fn($r) => (float) ($r['days_open'] ?? 0.0), $closedPools)) / $closedCount
  : 0.0;
?>
<section class="panel closed-hero">
  <div class="table-head">
    <h3>Closed Pools Overview</h3>
    <p class="muted">Pools encerradas com dados congelados na data de fecho.</p>
  </div>
  <div class="stats-grid closed-kpis">
    <article class="panel stat">
      <p>Closed pools</p>
      <h2><?= $closedCount ?></h2>
    </article>
    <article class="panel stat">
      <p>Total final</p>
      <h2><?= format_money((float) $totalFinal) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total PnL</p>
      <h2 class="<?= $totalPnl < 0 ? 'error' : 'ok' ?>"><?= format_money((float) $totalPnl) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total fees (U + C)</p>
      <h2><?= format_money((float) $totalFees) ?></h2>
    </article>
    <article class="panel stat">
      <p>Avg days open</p>
      <h2><?= format_number((float) $avgDays, 1) ?></h2>
    </article>
  </div>
</section>

<section class="panel table-panel">
  <div class="table-head">
    <h3>Closed Pools</h3>
    <p id="statusLine" class="status-line">Same layout as active pools, with data frozen at the close date.</p>
  </div>
  <div class="table-wrap">
    <table class="closed-table">
      <thead><tr><th>Order</th><th>Pool</th><th>Pair</th><th>Totals</th><th>Performance</th><th>Close</th><th>Actions</th><th>Details</th></tr></thead>
      <tbody>
      <?php if (count($closedPools) === 0): ?>
        <tr><td colspan="8" class="empty">No closed pools.</td></tr>
      <?php else: ?>
          <?php foreach ($closedPools as $i => $row): ?>
          <?php
            $asset1 = strtoupper(trim((string) ($row['asset_1'] ?? '')));
            $asset2 = strtoupper(trim((string) ($row['asset_2'] ?? '')));
            $asset1Short = $asset1 !== '' ? substr($asset1, 0, 3) : '';
            $asset2Short = $asset2 !== '' ? substr($asset2, 0, 3) : '';
            $iconMap = [
                'ZRO' => 'zrx',
                'WLD' => 'worldcoin',
            ];
            $asset1Icon = strtolower($iconMap[$asset1] ?? $asset1);
            $asset2Icon = strtolower($iconMap[$asset2] ?? $asset2);
            $totalFeesRow = (float) ($row['unclaimed'] ?? 0.0) + (float) ($row['claimed'] ?? 0.0);
            $pnlRow = (float) ($row['total_now'] ?? 0.0) - (float) ($row['initial_total'] ?? 0.0);
            $detailsId = 'closed-details-' . md5((string) $row['pool_id']);
          ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <b><?= h((string) $row['pool_id']) ?></b>
              <div class="muted"><?= h((string) $row['chain']) ?></div>
            </td>
            <td>
              <div class="closed-pair">
                <span class="token-icon" data-token="<?= h($asset1) ?>">
                  <img
                    src="https://cdn.jsdelivr.net/gh/spothq/cryptocurrency-icons@master/128/color/<?= h($asset1Icon) ?>.png"
                    alt="<?= h($asset1) ?>"
                    loading="lazy"
                    onerror="this.style.display='none'; this.parentNode.classList.add('token-icon-fallback');"
                  />
                  <span class="token-icon-text"><?= h($asset1Short) ?></span>
                </span>
                <span class="token-icon" data-token="<?= h($asset2) ?>">
                  <img
                    src="https://cdn.jsdelivr.net/gh/spothq/cryptocurrency-icons@master/128/color/<?= h($asset2Icon) ?>.png"
                    alt="<?= h($asset2) ?>"
                    loading="lazy"
                    onerror="this.style.display='none'; this.parentNode.classList.add('token-icon-fallback');"
                  />
                  <span class="token-icon-text"><?= h($asset2Short) ?></span>
                </span>
                <span class="pair-text"><?= h((string) $row['asset_1']) ?> / <?= h((string) $row['asset_2']) ?></span>
              </div>
            </td>
            <td>
              <div class="closed-metrics">
                <div><span>Total final</span><b><?= format_money((float) $row['total_now']) ?></b></div>
                <div><span>Initial</span><b><?= format_money((float) $row['initial_total']) ?></b></div>
                <div><span>PnL</span><b class="<?= $pnlRow < 0 ? 'error' : 'ok' ?>"><?= format_money($pnlRow) ?></b></div>
                <div><span>Total fees</span><b><?= format_money($totalFeesRow) ?></b></div>
              </div>
            </td>
            <td>
              <div class="closed-metrics">
                <div><span>ROI</span><b class="<?= (float) $row['roi'] < 0 ? 'error' : 'ok' ?>"><?= format_money((float) $row['roi']) ?></b></div>
                <div><span>APR</span><b><?= format_number((float) $row['apr'] * 100, 2) ?>%</b></div>
                <div><span>Days open</span><b><?= format_number((float) $row['days_open'], 1) ?></b></div>
              </div>
            </td>
            <td>
              <div class="closed-status">
                <span class="chip">Closed</span>
                <div class="muted nowrap"><?= h(format_datetime_display((string) $row['closed_at'])) ?></div>
              </div>
            </td>
            <td>
              <form method="post" class="inline-actions" data-confirm="Restore this pool to active pools?">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="restore_pool" />
                <input type="hidden" name="pool_id" value="<?= h((string) $row['pool_id']) ?>" />
                <button type="submit" class="small">Restore</button>
              </form>
            </td>
            <td>
              <button type="button" class="small" data-toggle-details="<?= h($detailsId) ?>">Ver tudo</button>
            </td>
          </tr>
          <tr id="<?= h($detailsId) ?>" hidden class="closed-details-row">
            <td colspan="8">
              <div class="closed-expanded">
                <div class="closed-expanded-head">
                  <h4>Detalhes <?= h((string) $row['pool_id']) ?></h4>
                  <span class="muted nowrap">Close date: <?= h(format_datetime_display((string) $row['closed_at'])) ?></span>
                </div>
                <div class="detail-grid closed-detail-grid">
                  <div class="metric-item">
                    <span class="metric-label">Wallet</span>
                    <span class="metric-value"><?= h((string) $row['wallet']) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">Days open</span>
                    <span class="metric-value"><?= format_number((float) $row['days_open'], 1) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">Initial total</span>
                    <span class="metric-value"><?= format_money((float) $row['initial_total']) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">Final total</span>
                    <span class="metric-value"><?= format_money((float) $row['total_now']) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">Frozen unclaimed</span>
                    <span class="metric-value"><?= format_money((float) $row['unclaimed']) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">Total claimed</span>
                    <span class="metric-value"><?= format_money((float) $row['claimed']) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">Total fees</span>
                    <span class="metric-value"><?= format_money($totalFeesRow) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">PnL</span>
                    <span class="metric-value <?= $pnlRow < 0 ? 'error' : 'ok' ?>"><?= format_money($pnlRow) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">ROI</span>
                    <span class="metric-value <?= (float) $row['roi'] < 0 ? 'error' : 'ok' ?>"><?= format_money((float) $row['roi']) ?></span>
                  </div>
                  <div class="metric-item">
                    <span class="metric-label">APR</span>
                    <span class="metric-value"><?= format_number((float) $row['apr'] * 100, 2) ?>%</span>
                  </div>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
