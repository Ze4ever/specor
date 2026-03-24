<?php
/** @var array $poolRows */
/** @var array $feeSnapshots */
/** @var array $feeDaily */
/** @var array $dados */

$today = date('Y-m-d');
$recentByPool = [];
$snapshotsByPool = [];
$snapshotsByDate = [];
$claimedByPoolDate = [];
$claimedTxByPool = [];
$poolSnapshotMeta = [];
$dayTotals = [];
$dayDeltaMap = [];
$dayClaimedTotals = [];
foreach ($feeSnapshots as $snap) {
    $pid = (string) ($snap['pool_id'] ?? '');
    $date = (string) ($snap['snapshot_date'] ?? '');
    if ($pid === '') {
        continue;
    }
    $snapshotsByPool[$pid][] = $snap;
    if ($date !== '') {
        $snapshotsByDate[$date][] = $snap;
        $dayTotals[$date] = (float) ($dayTotals[$date] ?? 0.0) + (float) ($snap['unclaimed_usd'] ?? 0.0);
    }
    if (!isset($recentByPool[$pid]) || strcmp((string) $snap['snapshot_date'], (string) $recentByPool[$pid]['snapshot_date']) > 0) {
        $recentByPool[$pid] = $snap;
    }
}

foreach ($dados as $txRow) {
    $action = strtolower((string) ($txRow['action'] ?? ''));
    if ($action !== 'fees') {
        continue;
    }
    $poolId = (string) ($txRow['pool_id'] ?? '');
    $date = substr((string) ($txRow['date'] ?? ''), 0, 10);
    if ($poolId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        continue;
    }
    $feesValue = (float) ($txRow['fees'] ?? 0.0);
    $totalValue = (float) ($txRow['total'] ?? 0.0);
    $amount = abs($feesValue) > 0.0000001 ? $feesValue : $totalValue;
    if ($amount <= 0.0) {
        continue;
    }
    $claimedByPoolDate[$poolId][$date] = (float) ($claimedByPoolDate[$poolId][$date] ?? 0.0) + $amount;
    $claimedTxByPool[$poolId][] = [
        'id' => (int) ($txRow['id'] ?? 0),
        'date' => (string) ($txRow['date'] ?? ''),
        'amount' => $amount,
        'source' => (string) ($txRow['transaction'] ?? ''),
    ];
}
foreach ($claimedByPoolDate as $pid => $rows) {
    ksort($rows);
    $claimedByPoolDate[$pid] = $rows;
    foreach ($rows as $date => $value) {
        $dayClaimedTotals[$date] = (float) ($dayClaimedTotals[$date] ?? 0.0) + (float) $value;
    }
}
foreach ($claimedTxByPool as $pid => $rows) {
    usort($rows, static fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
    $claimedTxByPool[$pid] = $rows;
}

foreach ($snapshotsByPool as $pid => $rows) {
    usort($rows, static fn($a, $b) => strcmp((string) ($a['snapshot_date'] ?? ''), (string) ($b['snapshot_date'] ?? '')));
    $count = count($rows);
    $last = $count > 0 ? $rows[$count - 1] : null;
    $prev = $count > 1 ? $rows[$count - 2] : null;
    $delta = 0.0;
    $avgPerDay = null;
    if ($last !== null) {
        $poolClaimRows = $claimedByPoolDate[$pid] ?? [];
        $poolClaimDates = array_keys($poolClaimRows);
        $claimPtr = 0;
        $claimAcc = 0.0;
        $lastValue = 0.0;
        $prevValue = 0.0;
        foreach ($rows as $row) {
            $rowDate = (string) ($row['snapshot_date'] ?? '');
            while ($claimPtr < count($poolClaimDates) && strcmp((string) $poolClaimDates[$claimPtr], $rowDate) <= 0) {
                $claimAcc += (float) ($poolClaimRows[(string) $poolClaimDates[$claimPtr]] ?? 0.0);
                $claimPtr++;
            }
            $effective = (float) ($row['unclaimed_usd'] ?? 0.0) + $claimAcc;
            if ($prev !== null && $rowDate === (string) ($prev['snapshot_date'] ?? '')) {
                $prevValue = $effective;
            }
            if ($rowDate === (string) ($last['snapshot_date'] ?? '')) {
                $lastValue = $effective;
            }
        }
        if ($prev !== null) {
            $delta = $lastValue - $prevValue;
            $d1 = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($prev['snapshot_date'] ?? ''));
            $d2 = DateTimeImmutable::createFromFormat('Y-m-d', (string) ($last['snapshot_date'] ?? ''));
            if ($d1 instanceof DateTimeImmutable && $d2 instanceof DateTimeImmutable) {
                $days = max(1, (int) $d1->diff($d2)->format('%a'));
                $avgPerDay = $delta / $days;
            }
        }
    }
    $poolSnapshotMeta[$pid] = [
        'count' => $count,
        'delta' => $delta,
        'avg_per_day' => $avgPerDay,
    ];
}

ksort($dayTotals);
ksort($dayClaimedTotals);
$allClaimDates = array_keys($dayClaimedTotals);
$claimPtr = 0;
$claimAcc = 0.0;
$prevDayEffective = null;
foreach ($dayTotals as $date => $total) {
    while ($claimPtr < count($allClaimDates) && strcmp((string) $allClaimDates[$claimPtr], (string) $date) <= 0) {
        $claimAcc += (float) ($dayClaimedTotals[(string) $allClaimDates[$claimPtr]] ?? 0.0);
        $claimPtr++;
    }
    $effectiveTotal = (float) $total + $claimAcc;
    $dayDeltaMap[$date] = $prevDayEffective === null ? 0.0 : ($effectiveTotal - $prevDayEffective);
    $prevDayEffective = $effectiveTotal;
}
krsort($snapshotsByDate);

$totalUnclaimed = array_sum(array_map(static fn($p) => (float) ($p['unclaimed'] ?? 0.0), $poolRows));
$totalClaimed = array_sum(array_map(static fn($p) => (float) ($p['claimed'] ?? 0.0), $poolRows));
$claimedByPool = [];
$poolUniswapById = [];
foreach ($poolRows as $poolRowMeta) {
    $pid = (string) ($poolRowMeta['pool_id'] ?? '');
    if ($pid === '') {
        continue;
    }
    $claimedByPool[$pid] = (float) ($poolRowMeta['claimed'] ?? 0.0);
    $poolUniswapById[$pid] = trim((string) ($poolRowMeta['uniswap'] ?? ''));
}
$latestSnapshotDate = count($feeSnapshots) > 0 ? (string) max(array_map(static fn($r) => (string) ($r['snapshot_date'] ?? ''), $feeSnapshots)) : '';
$feeToday = count($feeDaily) > 0 ? (float) ($feeDaily[count($feeDaily) - 1]['generated'] ?? 0.0) : 0.0;
$fee7d = array_sum(array_map(static fn($r) => (float) ($r['generated'] ?? 0.0), array_slice($feeDaily, -7)));
?>

<section class="panel fees-hero">
  <div class="table-head">
    <h3>Fees Overview</h3>
    <p class="muted">Unclaimed + claimed tracking across pools and snapshots.</p>
  </div>
  <div class="stats-grid fees-kpis">
    <article class="panel stat">
      <p>Current unclaimed</p>
      <h2><?= format_money((float) $totalUnclaimed) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total claimed</p>
      <h2><?= format_money((float) $totalClaimed) ?></h2>
    </article>
    <article class="panel stat">
      <p>Fees today</p>
      <h2><?= format_money((float) $feeToday) ?></h2>
    </article>
    <article class="panel stat">
      <p>Fees 7 days</p>
      <h2><?= format_money((float) $fee7d) ?></h2>
    </article>
    <article class="panel stat">
      <p>Latest snapshot</p>
      <h2><?= $latestSnapshotDate !== '' ? h(format_date_display($latestSnapshotDate)) : '-' ?></h2>
    </article>
  </div>
</section>

<section class="panel table-panel">
  <div class="table-head">
    <div>
      <h3>Daily Fees by Pool</h3>
      <p id="statusLine" class="status-line">Update unclaimed values and keep a clean daily history. Dica: use Tab para ir direto para a proxima fee.</p>
    </div>
  </div>

  <form method="post">
    <?= csrf_input() ?>

    <div class="control-head fees-controls">
      <label class="fees-date">Snapshot date
        <input type="date" name="snapshot_date" value="<?= h($today) ?>" required />
      </label>
      <button type="submit" name="action" value="save_daily_fees">Save daily record</button>
    </div>

    <div class="table-wrap">
      <table class="fees-table">
        <thead><tr><th>Order</th><th>Pool</th><th>Pair</th><th>Current unclaimed</th><th>Claimed Fees</th><th>Snapshots</th><th title="Effective change between the last two snapshots (Unclaimed + Total claimed)">Last delta</th><th>Avg/day</th><th>Edit fees</th><th>Actions</th><th>Last snapshot</th></tr></thead>
        <tbody>
          <?php if (count($poolRows) === 0): ?>
            <tr><td colspan="11" class="empty">No active pools.</td></tr>
          <?php else: ?>
            <?php foreach ($poolRows as $i => $pool): ?>
              <?php $last = $recentByPool[(string) $pool['pool_id']] ?? null; ?>
              <?php $claimFormId = 'claim-fees-' . md5((string) $pool['pool_id']); ?>
              <?php $detailsId = 'fees-details-' . md5((string) $pool['pool_id']); ?>
              <?php $poolSnaps = $snapshotsByPool[(string) $pool['pool_id']] ?? []; ?>
              <?php $poolClaimedByDate = $claimedByPoolDate[(string) $pool['pool_id']] ?? []; ?>
              <?php $poolClaimedTxRows = $claimedTxByPool[(string) $pool['pool_id']] ?? []; ?>
              <?php $poolMeta = $poolSnapshotMeta[(string) $pool['pool_id']] ?? ['count' => 0, 'delta' => 0.0, 'avg_per_day' => null]; ?>
              <?php
                $poolUrl = (string) ($poolUniswapById[(string) $pool['pool_id']] ?? '');
                $asset1 = strtoupper(trim((string) ($pool['asset_1'] ?? '')));
                $asset2 = strtoupper(trim((string) ($pool['asset_2'] ?? '')));
                $asset1Short = $asset1 !== '' ? substr($asset1, 0, 3) : '';
                $asset2Short = $asset2 !== '' ? substr($asset2, 0, 3) : '';
                $iconMap = [
                    'ZRO' => 'zrx',
                    'WLD' => 'worldcoin',
                ];
                $asset1Icon = strtolower($iconMap[$asset1] ?? $asset1);
                $asset2Icon = strtolower($iconMap[$asset2] ?? $asset2);
              ?>
              <?php $hasPoolUrl = preg_match('/^https?:\/\//i', $poolUrl) === 1; ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td>
                  <?php if ($hasPoolUrl): ?>
                    <a class="pool-title-link" href="<?= h($poolUrl) ?>" target="_blank" rel="noopener noreferrer"><b><?= h((string) $pool['pool_id']) ?></b></a>
                  <?php else: ?>
                    <b><?= h((string) $pool['pool_id']) ?></b>
                  <?php endif; ?>
                  <div class="muted"><?= h((string) $pool['chain']) ?></div>
                </td>
                <td class="fees-pair-cell">
                  <div class="fees-pair">
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
                    <span class="pair-text"><?= h((string) $pool['asset_1']) ?> / <?= h((string) $pool['asset_2']) ?></span>
                  </div>
                  <div class="fees-total">Total pool <b><?= format_money((float) ($pool['total_now'] ?? 0.0)) ?></b></div>
                </td>
                <td class="fees-unclaimed-cell">
                  <div class="fees-key">Current unclaimed</div>
                  <b class="fees-unclaimed"><?= format_money((float) $pool['unclaimed']) ?></b>
                </td>
                <td><b><?= format_money((float) $pool['claimed']) ?></b></td>
                <td><?= (int) ($poolMeta['count'] ?? 0) ?></td>
                <?php $deltaVal = (float) ($poolMeta['delta'] ?? 0.0); ?>
                <?php $deltaClass = $deltaVal < 0 ? 'error' : 'ok'; ?>
                <td><span class="fees-delta-badge <?= $deltaClass ?>"><?= format_money($deltaVal) ?></span></td>
                <td>
                  <?php if ($poolMeta['avg_per_day'] !== null): ?>
                    <?php $avgVal = (float) $poolMeta['avg_per_day']; ?>
                    <?php $avgClass = $avgVal < 0 ? 'error' : 'ok'; ?>
                    <span class="fees-delta-badge <?= $avgClass ?>"><?= format_money($avgVal) ?></span>
                  <?php else: ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <input type="hidden" name="pool_id[]" value="<?= h((string) $pool['pool_id']) ?>" />
                  <div class="fees-input-wrap">
                    <button type="button" class="fees-step" data-fees-step="-1" tabindex="-1" aria-label="Diminuir fee">-</button>
                    <input type="number" class="fees-input" name="unclaimed[]" step="0.01" min="0" value="<?= h(number_format((float) $pool['unclaimed'], 2, '.', '')) ?>" inputmode="decimal" required />
                    <button type="button" class="fees-step" data-fees-step="1" tabindex="-1" aria-label="Aumentar fee">+</button>
                  </div>
                </td>
                <td>
                  <div class="inline-actions">
                    <button type="submit" class="small" form="<?= h($claimFormId) ?>" data-confirm="Claim fees for this pool?" tabindex="-1">Claim fees</button>
                    <button type="button" class="small" data-toggle-details="<?= h($detailsId) ?>" tabindex="-1">View all</button>
                  </div>
                </td>
                <td>
                  <?php if ($last): ?>
                    <div class="inline-actions">
                      <span class="nowrap"><?= h(format_date_display((string) $last['snapshot_date'])) ?></span>
                    </div>
                  <?php else: ?>
                    <span class="muted">No history</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr id="<?= h($detailsId) ?>" hidden>
                <td colspan="11">
                  <div class="pool-expanded">
                    <h4>Pool snapshots <?= h((string) $pool['pool_id']) ?></h4>
                    <?php
                      $lastSnapDate = $last ? h(format_date_display((string) $last['snapshot_date'])) : '-';
                      $lastSnapUnclaimed = $last ? format_money((float) ($last['unclaimed_usd'] ?? 0.0)) : '-';
                      $deltaVal = (float) ($poolMeta['delta'] ?? 0.0);
                      $deltaClass = $deltaVal < 0 ? 'error' : 'ok';
                      $avgVal = $poolMeta['avg_per_day'] !== null ? (float) $poolMeta['avg_per_day'] : null;
                      $avgClass = ($avgVal ?? 0.0) < 0 ? 'error' : 'ok';
                    ?>
                    <div class="fees-summary">
                      <div class="fees-summary-item"><span>Last snapshot</span><b><?= $lastSnapDate ?></b></div>
                      <div class="fees-summary-item"><span>Unclaimed (last)</span><b><?= $lastSnapUnclaimed ?></b></div>
                      <div class="fees-summary-item"><span>Total claimed</span><b><?= format_money((float) $pool['claimed']) ?></b></div>
                      <div class="fees-summary-item">
                        <span>Last delta</span>
                        <span class="fees-delta-badge <?= $deltaClass ?>"><?= format_money($deltaVal) ?></span>
                      </div>
                      <div class="fees-summary-item">
                        <span>Avg/day</span>
                        <?php if ($avgVal !== null): ?>
                          <span class="fees-delta-badge <?= $avgClass ?>"><?= format_money($avgVal) ?></span>
                        <?php else: ?>
                          <span class="muted">-</span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="table-wrap">
                      <table class="compact-table">
                        <thead><tr><th>Date</th><th>Unclaimed</th><th>Claimed Snapshot</th><th>Delta</th><th></th></tr></thead>
                        <tbody>
                          <?php if (count($poolSnaps) === 0): ?>
                            <tr><td colspan="5" class="empty">No snapshots.</td></tr>
                          <?php else: ?>
                            <?php
                              $poolSnapsAsc = $poolSnaps;
                              usort($poolSnapsAsc, static fn($a, $b) => strcmp((string) ($a['snapshot_date'] ?? ''), (string) ($b['snapshot_date'] ?? '')));
                              $poolSnapDelta = [];
                              $poolClaimedSnapshot = [];
                              $prevSnapVal = null;
                              $claimedDates = array_keys($poolClaimedByDate);
                              $claimedPtr = 0;
                              $claimedAcc = 0.0;
                              foreach ($poolSnapsAsc as $snapAscRow) {
                                  $snapDateKey = (string) ($snapAscRow['snapshot_date'] ?? '');
                                  $snapVal = (float) ($snapAscRow['unclaimed_usd'] ?? 0.0);
                                  while ($claimedPtr < count($claimedDates) && strcmp((string) $claimedDates[$claimedPtr], $snapDateKey) <= 0) {
                                      $claimedAcc += (float) ($poolClaimedByDate[(string) $claimedDates[$claimedPtr]] ?? 0.0);
                                      $claimedPtr++;
                                  }
                                  $poolClaimedSnapshot[$snapDateKey] = $claimedAcc;
                                  $effectiveVal = $snapVal + $claimedAcc;
                                  $poolSnapDelta[$snapDateKey] = $prevSnapVal === null ? 0.0 : ($effectiveVal - $prevSnapVal);
                                  $prevSnapVal = $effectiveVal;
                              }
                              $unclaimedSeries = array_map(static fn($r) => [
                                  'date' => (string) ($r['snapshot_date'] ?? ''),
                                  'value' => (float) ($r['unclaimed_usd'] ?? 0.0),
                              ], $poolSnapsAsc);
                              $deltaSeries = array_map(static fn($r) => [
                                  'date' => (string) ($r['snapshot_date'] ?? ''),
                                  'value' => (float) ($poolSnapDelta[(string) ($r['snapshot_date'] ?? '')] ?? 0.0),
                              ], $poolSnapsAsc);
                              $unclaimedSeriesJson = (string) json_encode($unclaimedSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                              $unclaimedSeriesB64 = base64_encode($unclaimedSeriesJson);
                              $deltaSeriesJson = (string) json_encode($deltaSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                              $deltaSeriesB64 = base64_encode($deltaSeriesJson);
                            ?>
                            <?php foreach (array_reverse($poolSnapsAsc) as $snapRow): ?>
                              <?php $deleteSnapshotRowFormId = 'delete-snap-row-' . md5((string) $pool['pool_id'] . '-' . (string) $snapRow['snapshot_date']); ?>
                              <?php $snapDateKey = (string) ($snapRow['snapshot_date'] ?? ''); ?>
                              <?php $rowDelta = (float) ($poolSnapDelta[$snapDateKey] ?? 0.0); ?>
                              <?php $rowClaimedSnapshot = (float) ($poolClaimedSnapshot[$snapDateKey] ?? 0.0); ?>
                              <tr>
                                <td class="nowrap"><?= h(format_date_display((string) $snapRow['snapshot_date'])) ?></td>
                                <td><?= format_money((float) $snapRow['unclaimed_usd']) ?></td>
                                <td><?= format_money($rowClaimedSnapshot) ?></td>
                                <td class="<?= $rowDelta < 0 ? 'error' : 'ok' ?>"><?= format_money($rowDelta) ?></td>
                                <td>
                                  <button type="submit" class="x-min" form="<?= h($deleteSnapshotRowFormId) ?>" title="Delete this snapshot row">x</button>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                    <?php if (count($poolSnaps) > 0): ?>
                      <div class="fees-chart-grid" style="margin-top:10px;">
                        <article class="fees-chart-card">
                          <h5>Unclaimed per snapshot</h5>
                          <div class="fees-history" data-fees-step="15" data-fees-series-b64="<?= h($unclaimedSeriesB64) ?>" data-fees-series="<?= h($unclaimedSeriesJson) ?>">
                            <div class="fees-periods">
                              <button type="button" class="small active" data-fees-period="day">Day</button>
                              <button type="button" class="small" data-fees-period="month">Month</button>
                              <button type="button" class="small" data-fees-period="year">Year</button>
                            </div>
                            <div class="fees-bars" data-fees-bars></div>
                            <div class="fees-more-row" data-fees-more-wrap hidden>
                              <button type="button" class="small" data-fees-load-more>Load more</button>
                              <span class="muted" data-fees-count></span>
                            </div>
                          </div>
                        </article>
                        <article class="fees-chart-card">
                          <h5>Delta per snapshot</h5>
                          <div class="fees-history" data-fees-step="15" data-fees-series-b64="<?= h($deltaSeriesB64) ?>" data-fees-series="<?= h($deltaSeriesJson) ?>">
                            <div class="fees-periods">
                              <button type="button" class="small active" data-fees-period="day">Day</button>
                              <button type="button" class="small" data-fees-period="month">Month</button>
                              <button type="button" class="small" data-fees-period="year">Year</button>
                            </div>
                            <div class="fees-bars" data-fees-bars></div>
                            <div class="fees-more-row" data-fees-more-wrap hidden>
                              <button type="button" class="small" data-fees-load-more>Load more</button>
                              <span class="muted" data-fees-count></span>
                            </div>
                          </div>
                        </article>
                      </div>
                    <?php endif; ?>
                    <div class="table-wrap" style="margin-top:10px;">
                      <table class="compact-table">
                        <thead><tr><th>Date</th><th>Claimed</th><th>Source</th><th></th></tr></thead>
                        <tbody>
                          <?php if (count($poolClaimedTxRows) === 0): ?>
                            <tr><td colspan="4" class="empty">No claimed records for this pool.</td></tr>
                          <?php else: ?>
                            <?php foreach ($poolClaimedTxRows as $claimRow): ?>
                              <?php $removeClaimFormId = 'remove-claim-tx-' . (int) ($claimRow['id'] ?? 0); ?>
                              <tr>
                                <td class="nowrap"><?= h(format_datetime_display((string) ($claimRow['date'] ?? ''))) ?></td>
                                <td><?= format_money((float) ($claimRow['amount'] ?? 0.0)) ?></td>
                                <td><?= h((string) ($claimRow['source'] ?? '')) ?></td>
                                <td><button type="submit" class="x-min" form="<?= h($removeClaimFormId) ?>" title="Delete this claim">x</button></td>
                              </tr>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </form>

  <?php foreach ($poolRows as $pool): ?>
    <?php $claimFormId = 'claim-fees-' . md5((string) $pool['pool_id']); ?>
    <form method="post" id="<?= h($claimFormId) ?>" data-confirm="Claim fees for this pool?">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="claim_pool_fees" />
      <input type="hidden" name="claim_pool_id" value="<?= h((string) $pool['pool_id']) ?>" />
    </form>
  <?php endforeach; ?>

  <?php foreach ($feeSnapshots as $snapshotRow): ?>
    <?php $deleteSnapshotRowFormId = 'delete-snap-row-' . md5((string) $snapshotRow['pool_id'] . '-' . (string) $snapshotRow['snapshot_date']); ?>
    <form method="post" id="<?= h($deleteSnapshotRowFormId) ?>" data-confirm="Delete this pool snapshot?">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="delete_fee_snapshot" />
      <input type="hidden" name="pool_id" value="<?= h((string) $snapshotRow['pool_id']) ?>" />
      <input type="hidden" name="snapshot_date" value="<?= h((string) $snapshotRow['snapshot_date']) ?>" />
    </form>
  <?php endforeach; ?>

  <?php foreach ($claimedTxByPool as $poolClaimRows): ?>
    <?php foreach ($poolClaimRows as $claimRow): ?>
      <?php $removeClaimFormId = 'remove-claim-tx-' . (int) ($claimRow['id'] ?? 0); ?>
      <form method="post" id="<?= h($removeClaimFormId) ?>" data-confirm="Delete this claim row and adjust fees?">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="remove_tx" />
        <input type="hidden" name="tx_id" value="<?= (int) ($claimRow['id'] ?? 0) ?>" />
      </form>
    <?php endforeach; ?>
  <?php endforeach; ?>
</section>

<section class="panel table-panel">
  <div class="table-head"><h3>Snapshot History</h3></div>
  <?php if (count($snapshotsByDate) === 0): ?>
    <p class="muted">No records.</p>
  <?php else: ?>
    <?php foreach ($snapshotsByDate as $date => $rowsByDate): ?>
      <?php $totalDay = array_sum(array_map(static fn($r) => (float) ($r['unclaimed_usd'] ?? 0.0), $rowsByDate)); ?>
      <?php $totalClaimedDay = (float) ($dayClaimedTotals[(string) $date] ?? 0.0); ?>
      <details class="snapshot-day">
        <summary>
          <span><b><?= h(format_date_display((string) $date)) ?></b></span>
          <span class="muted"><?= count($rowsByDate) ?> pool(s)</span>
          <span class="muted">Total unclaimed: <?= format_money((float) $totalDay) ?></span>
          <span class="muted">Total claimed: <?= format_money((float) $totalClaimedDay) ?></span>
          <?php $dayDelta = (float) ($dayDeltaMap[$date] ?? 0.0); ?>
          <span class="<?= $dayDelta < 0 ? 'error' : 'ok' ?>">Delta: <?= format_money($dayDelta) ?></span>
        </summary>
        <form method="post" class="inline-actions" data-confirm="Delete all snapshots for this day across all pools?">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="delete_fee_snapshot_day" />
          <input type="hidden" name="snapshot_date" value="<?= h((string) $date) ?>" />
          <button type="submit" class="small danger">Delete day</button>
        </form>
        <div class="table-wrap">
          <table class="compact-table">
            <thead><tr><th>Pool</th><th>Unclaimed</th><th>Claimed on day</th><th>Current claimed</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($rowsByDate as $row): ?>
                <?php $deleteSnapshotRowFormId = 'delete-snap-row-' . md5((string) $row['pool_id'] . '-' . (string) $row['snapshot_date']); ?>
                <?php $poolClaimedNow = (float) ($claimedByPool[(string) ($row['pool_id'] ?? '')] ?? 0.0); ?>
                <?php $poolClaimedDay = (float) ($claimedByPoolDate[(string) ($row['pool_id'] ?? '')][(string) ($row['snapshot_date'] ?? '')] ?? 0.0); ?>
                <tr>
                  <td><?= h((string) $row['pool_id']) ?></td>
                  <td><?= format_money((float) $row['unclaimed_usd']) ?></td>
                  <td><?= format_money((float) $poolClaimedDay) ?></td>
                  <td><?= format_money((float) $poolClaimedNow) ?></td>
                  <td>
                    <button type="submit" class="x-min" form="<?= h($deleteSnapshotRowFormId) ?>" title="Delete this snapshot row">x</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
