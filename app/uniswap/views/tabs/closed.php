<?php
/** @var array $closedPools */
$closedCount = count($closedPools);
$totalInitial = array_sum(array_map(static fn($r) => (float) ($r['initial_total'] ?? 0.0), $closedPools));
$totalFinal = array_sum(array_map(static fn($r) => (float) ($r['total_now'] ?? 0.0), $closedPools));
$totalFees = array_sum(array_map(static fn($r) => (float) ($r['unclaimed'] ?? 0.0) + (float) ($r['claimed'] ?? 0.0), $closedPools));
$totalRoi = array_sum(array_map(
  static fn($r) => (float) ($r['roi_total'] ?? ((float) ($r['total_now'] ?? 0.0) + (float) ($r['unclaimed'] ?? 0.0) + (float) ($r['claimed'] ?? 0.0) - (float) ($r['initial_total'] ?? 0.0))),
  $closedPools
));
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
      <p>ROI Total</p>
      <h2 class="<?= $totalRoi < 0 ? 'error' : 'ok' ?>"><?= format_money((float) $totalRoi) ?></h2>
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
    <p id="statusLine" class="status-line">ROI Total = Total final + fees (claimed/unclaimed) - inicial.</p>
  </div>
  <div class="table-wrap">
    <table class="closed-table">
      <thead><tr><th>Order</th><th>Pool</th><th>Pair</th><th>ROI Total</th><th>Breakdown</th><th>If HODL</th><th>Close</th><th>Actions</th></tr></thead>
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
            $roiTotalRow = (float) ($row['roi_total'] ?? ((float) ($row['total_now'] ?? 0.0) + $totalFeesRow - (float) ($row['initial_total'] ?? 0.0)));
            $roiPctRow = (float) ($row['roi_pct'] ?? (((float) ($row['initial_total'] ?? 0.0)) > 0.0 ? ($roiTotalRow / (float) ($row['initial_total'] ?? 1.0)) * 100.0 : 0.0));
            $detailsId = 'closed-details-' . md5((string) $row['pool_id']);
            $initialToken1 = (float) ($row['initial_token1'] ?? 0.0);
            $initialToken2 = (float) ($row['initial_token2'] ?? 0.0);
            $finalToken1 = (float) ($row['final_token1'] ?? 0.0);
            $finalToken2 = (float) ($row['final_token2'] ?? 0.0);
            $deltaToken1 = (float) ($row['delta_token1'] ?? ($finalToken1 - $initialToken1));
            $deltaToken2 = (float) ($row['delta_token2'] ?? ($finalToken2 - $initialToken2));
            $hodlAtCloseRow = (float) ($row['hodl_at_close_view'] ?? ($row['hodl_at_close'] ?? 0.0));
            $hodlAtCloseNote = (string) ($row['hodl_at_close_note'] ?? '');
            $poolUrl = trim((string) ($row['pool_url'] ?? ''));
            $txUrl = trim((string) ($row['tx_url'] ?? ''));
            $txDate = (string) ($row['tx_date'] ?? '');
            $feePerDay = ((float) ($row['days_open'] ?? 0.0)) > 0.0
              ? ($totalFeesRow / (float) $row['days_open'])
              : 0.0;
          ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <?php if ($poolUrl !== ''): ?>
                <a class="pool-title pool-title-link" href="<?= h($poolUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h((string) $row['pool_id']) ?></a>
              <?php else: ?>
                <b><?= h((string) $row['pool_id']) ?></b>
              <?php endif; ?>
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
              <div class="metric-stack metric-primary">
                <span class="metric-label">ROI Total</span>
                <b class="metric-value <?= $roiTotalRow < 0 ? 'error' : 'ok' ?>"><?= format_money($roiTotalRow) ?></b>
                <span class="metric-sub <?= $roiPctRow < 0 ? 'error' : 'ok' ?>">ROI <?= format_number($roiPctRow, 2) ?>%</span>
              </div>
            </td>
            <td>
              <div class="metric-stack metric-list">
                <div><span>Total final (Pool)</span><b><?= format_money((float) $row['total_now']) ?></b></div>
                <div><span>Inicial</span><b><?= format_money((float) $row['initial_total']) ?></b></div>
                <div><span>Fees (U + C)</span><b><?= format_money($totalFeesRow) ?></b></div>
              </div>
            </td>
            <td>
              <div class="metric-stack">
                <span class="metric-label">If HODL<?= $hodlAtCloseNote !== '' ? ' (est.)' : '' ?></span>
                <b class="metric-value"><?= $hodlAtCloseRow > 0 ? format_money($hodlAtCloseRow) : 'n/a' ?></b>
              </div>
            </td>
            <td>
              <div class="closed-status">
                <span class="chip">Closed</span>
                <div class="muted nowrap"><?= h(format_datetime_display((string) $row['closed_at'])) ?></div>
              </div>
            </td>
            <td>
              <div class="inline-actions">
                <form method="post" data-confirm="Restore this pool to active pools?">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="restore_pool" />
                  <input type="hidden" name="pool_id" value="<?= h((string) $row['pool_id']) ?>" />
                  <button type="submit" class="small">Restore</button>
                </form>
                <button type="button" class="small outline" data-toggle-details="<?= h($detailsId) ?>">Ver tudo</button>
              </div>
            </td>
          </tr>
          <tr id="<?= h($detailsId) ?>" hidden class="closed-details-row">
            <td colspan="8">
              <div class="closed-expanded">
                <div class="closed-expanded-head">
                  <h4>Detalhes <?= h((string) $row['pool_id']) ?></h4>
                  <span class="muted nowrap">Close date: <?= h(format_datetime_display((string) $row['closed_at'])) ?></span>
                </div>
                <div class="closed-detail-sections">
                  <section class="detail-section">
                    <h4>Resultado</h4>
                    <div class="detail-grid closed-detail-grid">
                      <div class="metric-item">
                        <span class="metric-label">ROI Total</span>
                        <span class="metric-value <?= $roiTotalRow < 0 ? 'error' : 'ok' ?>"><?= format_money($roiTotalRow) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">ROI %</span>
                        <span class="metric-value <?= $roiPctRow < 0 ? 'error' : 'ok' ?>"><?= format_number($roiPctRow, 2) ?>%</span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Total final (Pool)</span>
                        <span class="metric-value"><?= format_money((float) $row['total_now']) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Total fees (U + C)</span>
                        <span class="metric-value"><?= format_money($totalFeesRow) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Fees / day</span>
                        <span class="metric-value"><?= format_money($feePerDay) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Inicial</span>
                        <span class="metric-value"><?= format_money((float) $row['initial_total']) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">If HODL<?= $hodlAtCloseNote !== '' ? ' (est.)' : '' ?></span>
                        <span class="metric-value"><?= $hodlAtCloseRow > 0 ? format_money($hodlAtCloseRow) : 'n/a' ?></span>
                      </div>
                    </div>
                  </section>
                  <section class="detail-section">
                    <h4>Tokens</h4>
                    <div class="detail-grid closed-detail-grid">
                      <div class="metric-item">
                        <span class="metric-label">Initial <?= h($asset1) ?></span>
                        <span class="metric-value"><?= format_number($initialToken1, 8) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Initial <?= h($asset2) ?></span>
                        <span class="metric-value"><?= format_number($initialToken2, 8) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Final <?= h($asset1) ?></span>
                        <span class="metric-value"><?= format_number($finalToken1, 8) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Final <?= h($asset2) ?></span>
                        <span class="metric-value"><?= format_number($finalToken2, 8) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Delta <?= h($asset1) ?></span>
                        <span class="metric-value <?= $deltaToken1 < 0 ? 'error' : 'ok' ?>">
                          <?= ($deltaToken1 > 0 ? '+' : '') . format_number($deltaToken1, 8) ?>
                        </span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Delta <?= h($asset2) ?></span>
                        <span class="metric-value <?= $deltaToken2 < 0 ? 'error' : 'ok' ?>">
                          <?= ($deltaToken2 > 0 ? '+' : '') . format_number($deltaToken2, 8) ?>
                        </span>
                      </div>
                    </div>
                  </section>
                  <section class="detail-section">
                    <h4>Meta</h4>
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
                        <span class="metric-label">APR</span>
                        <span class="metric-value"><?= format_number((float) $row['apr'] * 100, 2) ?>%</span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Unclaimed</span>
                        <span class="metric-value"><?= format_money((float) $row['unclaimed']) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Claimed</span>
                        <span class="metric-value"><?= format_money((float) $row['claimed']) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Close date</span>
                        <span class="metric-value"><?= h(format_datetime_display((string) $row['closed_at'])) ?></span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Pool URL</span>
                        <span class="metric-value">
                          <?php if ($poolUrl !== ''): ?>
                            <a class="pool-title-link" href="<?= h($poolUrl) ?>" target="_blank" rel="noopener noreferrer">Open</a>
                          <?php else: ?>
                            n/a
                          <?php endif; ?>
                        </span>
                      </div>
                      <div class="metric-item">
                        <span class="metric-label">Transaction URL</span>
                        <span class="metric-value">
                          <?php if ($txUrl !== ''): ?>
                            <a class="pool-title-link" href="<?= h($txUrl) ?>" target="_blank" rel="noopener noreferrer">Open</a>
                            <?php if ($txDate !== ''): ?>
                              <span class="muted nowrap"> (<?= h(format_datetime_display($txDate)) ?>)</span>
                            <?php endif; ?>
                          <?php else: ?>
                            n/a
                          <?php endif; ?>
                        </span>
                      </div>
                    </div>
                  </section>
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
