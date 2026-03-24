<?php
/** @var array $poolRows */
/** @var array $poolTxMap */
/** @var array $poolTxHistory */
/** @var array $feeSnapshots */
/** @var array $dados */

$feeRowsByPool = [];
foreach ($feeSnapshots as $snap) {
    $pid = (string) ($snap['pool_id'] ?? '');
    $date = (string) ($snap['snapshot_date'] ?? '');
    if ($pid === '' || $date === '') {
        continue;
    }
    $feeRowsByPool[$pid][] = [
        'date' => $date,
        'unclaimed' => (float) ($snap['unclaimed_usd'] ?? 0.0),
    ];
}

$buildGeneratedSeries = static function (array $rows, string $createRaw, array $claimedByDate): array {
    $byDate = [];
    foreach ($rows as $row) {
        $d = substr((string) ($row['date'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            continue;
        }
        $byDate[$d] = (float) ($row['unclaimed'] ?? 0.0);
    }
    if (count($byDate) === 0) {
        return [];
    }
    ksort($byDate);

    $firstSnapDate = (string) array_key_first($byDate);
    $createDate = substr(trim($createRaw), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $createDate)) {
        $createDate = $firstSnapDate;
    }
    if (strcmp($createDate, $firstSnapDate) > 0) {
        $createDate = $firstSnapDate;
    }

    $claimedClean = [];
    foreach ($claimedByDate as $date => $value) {
        $d = substr((string) $date, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            continue;
        }
        $claimedClean[$d] = (float) ($claimedClean[$d] ?? 0.0) + (float) $value;
    }
    ksort($claimedClean);
    $claimedDates = array_keys($claimedClean);
    $claimedPtr = 0;
    $claimedAcc = 0.0;

    $series = [];
    $prevDate = null;
    $prevEffective = 0.0;
    foreach ($byDate as $date => $curUnclaimed) {
        $curDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$curDateObj instanceof DateTimeImmutable) {
            continue;
        }
        while ($claimedPtr < count($claimedDates) && strcmp((string) $claimedDates[$claimedPtr], $date) <= 0) {
            $claimDate = (string) $claimedDates[$claimedPtr];
            $claimedAcc += (float) ($claimedClean[$claimDate] ?? 0.0);
            $claimedPtr++;
        }
        $curEffective = (float) $curUnclaimed + $claimedAcc;

        if ($prevDate === null) {
            $startDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $createDate);
            if (!$startDateObj instanceof DateTimeImmutable) {
                $startDateObj = $curDateObj;
            }
            $days = (int) $startDateObj->diff($curDateObj)->format('%a') + 1;
            $days = max(1, $days);
            $perDay = $curEffective / $days;
            for ($i = 0; $i < $days; $i++) {
                $d = $startDateObj->modify('+' . $i . ' day');
                $series[] = [
                    'date' => $d->format('Y-m-d'),
                    'value' => $perDay,
                ];
            }
        } else {
            $prevDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $prevDate);
            if (!$prevDateObj instanceof DateTimeImmutable) {
                $prevDateObj = $curDateObj;
            }
            $days = (int) $prevDateObj->diff($curDateObj)->format('%a');
            if ($days <= 0) {
                $prevDate = $date;
                $prevEffective = $curEffective;
                continue;
            }
            $delta = $curEffective - $prevEffective;
            $perDay = $delta / $days;
            for ($i = 1; $i <= $days; $i++) {
                $d = $prevDateObj->modify('+' . $i . ' day');
                $series[] = [
                    'date' => $d->format('Y-m-d'),
                    'value' => $perDay,
                ];
            }
        }

        $prevDate = $date;
        $prevEffective = $curEffective;
    }

    return $series;
};
?>
<section class="panel table-panel">
  <div class="table-head">
    <h3>Pools (drag, adjust weight, close)</h3>
    <div class="inline-actions">
      <form method="post" id="poolOrdererForm" class="inline-form-tight">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_pool_order" />
        <input type="hidden" name="pool_order_json" id="poolOrdererJson" value="" />
        <span class="muted">Drag by the Order column. Saves automatically.</span>
      </form>
    </div>
  </div>
  <div class="table-wrap">
    <table class="pools-table">
      <thead><tr><th>Order</th><th>Pool</th><th>Pair / Weight</th><th>Overview</th><th>Performance Fees</th><th>Actions</th></tr></thead>
      <tbody id="sortablePools">
      <?php if (count($poolRows) === 0): ?>
        <tr><td colspan="6" class="empty">No active pools.</td></tr>
      <?php else: ?>
        <?php foreach ($poolRows as $i => $pool): ?>
          <?php $detailsId = 'pool-details-' . md5((string) $pool['pool_id']); ?>
          <?php
            $poolSnaps = $feeRowsByPool[(string) $pool['pool_id']] ?? [];

            $claimedByDate = [];
            $claimedLogRows = [];
            $compoundLogRows = [];
            $txIdxs = $poolTxMap[(string) $pool['pool_id']] ?? [];
            foreach ($txIdxs as $txIdx) {
                if (!isset($dados[$txIdx])) {
                    continue;
                }
                $tx = $dados[$txIdx];
                $action = strtolower((string) ($tx['action'] ?? ''));
                $date = substr((string) ($tx['date'] ?? ''), 0, 10);
                if ($date === '') {
                    continue;
                }
                $total = (float) ($tx['total'] ?? 0.0);
                $feesValue = (float) ($tx['fees'] ?? 0.0);
                $depUsdSum = (float) ($tx['deposit_1_usd'] ?? 0.0) + (float) ($tx['deposit_2_usd'] ?? 0.0);
                $amount = $total;
                if ($action === 'fees' && abs($feesValue) > 0.0000001) {
                    $amount = $feesValue;
                } elseif (in_array($action, ['create', 'compound', 'remove'], true) && abs($amount) <= 0.0000001 && $depUsdSum > 0.0) {
                    $amount = $depUsdSum;
                }
                if ($action === 'fees') {
                    $claimedByDate[$date] = (float) ($claimedByDate[$date] ?? 0.0) + $amount;
                    $claimedLogRows[] = [
                        'date' => (string) ($tx['date'] ?? ''),
                        'value' => $amount,
                    ];
                } elseif ($action === 'compound') {
                    $compoundLogRows[] = [
                        'date' => (string) ($tx['date'] ?? ''),
                        'value' => $amount,
                    ];
                }
            }

            $dailySeries = $buildGeneratedSeries($poolSnaps, (string) ($pool['create'] ?? ''), $claimedByDate);
            $dailySeries = array_slice($dailySeries, -365);

            $generatedByDate = [];
            foreach ($dailySeries as $rowDaily) {
                $d = (string) ($rowDaily['date'] ?? '');
                if ($d === '') {
                    continue;
                }
                $generatedByDate[$d] = (float) ($generatedByDate[$d] ?? 0.0) + (float) ($rowDaily['value'] ?? 0.0);
            }
            ksort($generatedByDate);

            $generatedSeries = array_map(static fn($d, $v) => ['date' => (string) $d, 'value' => (float) $v], array_keys($generatedByDate), array_values($generatedByDate));

            $generatedSeries = array_slice($generatedSeries, -365);
            $feesGeneratedTotal = (float) $pool['unclaimed'] + (float) $pool['claimed'];
            $feesTotalLp = (float) ($pool['fees_total_lp'] ?? ((float) $pool['unclaimed'] + (float) $pool['claimed']));
            $feesGeneratedDisplay = (float) ($pool['fees_generated'] ?? 0.0);
            if ($feesGeneratedDisplay <= 0.0) {
                $feesGeneratedDisplay = (float) $pool['unclaimed'] + (float) $pool['claimed'];
            }
            $portfolioWithFees = (float) $pool['total_now'] + $feesTotalLp;
            $txCount = isset($poolTxMap[(string) $pool['pool_id']]) ? count($poolTxMap[(string) $pool['pool_id']]) : 0;
            $claimsCount = count($claimedLogRows);
            $compoundsCount = count($compoundLogRows);
            $unclaimedSharePct = $feesTotalLp > 0.0 ? (((float) $pool['unclaimed'] / $feesTotalLp) * 100.0) : 0.0;
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
            $currentFormId = 'current-form-' . md5((string) $pool['pool_id']);
            $current1Input = rtrim(rtrim(number_format((float) $pool['current_1'], 8, '.', ''), '0'), '.');
            $current2Input = rtrim(rtrim(number_format((float) $pool['current_2'], 8, '.', ''), '0'), '.');
            if ($current1Input === '') {
                $current1Input = '0';
            }
            if ($current2Input === '') {
                $current2Input = '0';
            }
            $generatedSeriesJson = (string) json_encode($generatedSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $generatedSeriesB64 = base64_encode($generatedSeriesJson);

            $claimedLogRows = array_reverse($claimedLogRows);
            $compoundLogRows = array_reverse($compoundLogRows);
          ?>
          <tr draggable="false" data-pool-id="<?= h((string) $pool['pool_id']) ?>" data-pool-row="1">
            <td class="ord-cell"><?= $i + 1 ?></td>
            <td>
              <?php $poolUrl = trim((string) ($pool['uniswap'] ?? '')); ?>
              <?php $hasPoolUrl = preg_match('/^https?:\/\//i', $poolUrl) === 1; ?>
              <?php if ($hasPoolUrl): ?>
                <a class="pool-title pool-title-link" href="<?= h($poolUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h((string) $pool['pool_id']) ?></a>
              <?php else: ?>
                <div class="pool-title"><?= h((string) $pool['pool_id']) ?></div>
              <?php endif; ?>
              <div class="pool-sub muted"><?= h((string) $pool['chain']) ?></div>
              <div class="pool-meta-chips compact">
                <span class="chip">Tx <?= $txCount ?></span>
                <span class="chip">Days <?= format_number((float) $pool['days_open'], 0) ?></span>
                <span class="chip">Claims <?= $claimsCount ?></span>
                <span class="chip">Compounds <?= $compoundsCount ?></span>
              </div>
              <div class="inline-actions current-submit-wrap">
                <button type="submit" class="small" form="<?= h($currentFormId) ?>">Update</button>
              </div>
            </td>
            <td>
              <div class="weight-wrap">
              <div class="weight-pair pair-badge"><?= h($asset1) ?> / <?= h($asset2) ?></div>
              <div class="weight-legend-top">
                  <span class="token-legend">
                    <span class="token-icon" data-token="<?= h($asset1) ?>">
                      <img
                        src="https://cdn.jsdelivr.net/gh/spothq/cryptocurrency-icons@master/128/color/<?= h($asset1Icon) ?>.png"
                        alt="<?= h($asset1) ?>"
                        loading="lazy"
                        onerror="this.style.display='none'; this.parentNode.classList.add('token-icon-fallback');"
                      />
                      <span class="token-icon-text"><?= h($asset1Short) ?></span>
                    </span>
                    <span><?= format_number((float) $pool['token1_weight_pct'], 1) ?>%</span>
                  </span>
                  <span class="token-legend">
                    <span class="token-icon" data-token="<?= h($asset2) ?>">
                      <img
                        src="https://cdn.jsdelivr.net/gh/spothq/cryptocurrency-icons@master/128/color/<?= h($asset2Icon) ?>.png"
                        alt="<?= h($asset2) ?>"
                        loading="lazy"
                        onerror="this.style.display='none'; this.parentNode.classList.add('token-icon-fallback');"
                      />
                      <span class="token-icon-text"><?= h($asset2Short) ?></span>
                    </span>
                    <span><?= format_number((float) $pool['token2_weight_pct'], 1) ?>%</span>
                  </span>
              </div>
                <div class="weight-bar">
                  <span class="w1" style="width: <?= format_percent_css((float) $pool['token1_weight_pct']) ?>%"></span>
                  <span class="w2" style="width: <?= format_percent_css((float) $pool['token2_weight_pct']) ?>%"></span>
                </div>
                <form method="post" class="current-inline" id="<?= h($currentFormId) ?>">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="save_pool_override" />
                  <input type="hidden" name="pool_id" value="<?= h((string) $pool['pool_id']) ?>" />
                  <label><?= h((string) $pool['asset_1']) ?>
                    <input type="number" step="0.00000001" name="current_1" value="<?= h($current1Input) ?>" />
                  </label>
                  <label><?= h((string) $pool['asset_2']) ?>
                    <input type="number" step="0.00000001" name="current_2" value="<?= h($current2Input) ?>" />
                  </label>
                </form>
              </div>
            </td>
            <td>
              <div class="simple-card">
                <div class="highlight-block">
                  <div class="simple-row highlight-row"><span>Pool</span><b class="highlight-value"><?= format_money((float) $pool['total_now']) ?></b></div>
                  <div class="simple-row highlight-row"><span>Unclaimed</span><b class="highlight-value"><?= format_money((float) $pool['unclaimed']) ?></b></div>
                </div>
                <div class="simple-row"><span>Inicial</span><b><?= format_money((float) $pool['initial_total']) ?></b></div>
                <div class="simple-row"><span>ROI</span><b class="<?= (float) $pool['roi'] < 0 ? 'error' : 'ok' ?>"><?= format_money((float) $pool['roi']) ?></b></div>
                <div class="simple-row"><span>IL</span><b class="<?= (float) $pool['il_value'] < 0 ? 'error' : 'ok' ?>"><?= format_money((float) $pool['il_value']) ?></b></div>
              </div>
            </td>
            <td>
              <div class="simple-card">
                <div class="highlight-block">
                  <div class="simple-row highlight-row"><span>APR</span><b class="highlight-value"><?= format_number((float) $pool['apr'] * 100, 2) ?>%</b></div>
                  <div class="simple-row highlight-row"><span>P/day</span><b class="highlight-value"><?= format_money((float) $pool['pday']) ?></b></div>
                </div>
                <div class="simple-row"><span>Claimed</span><b><?= format_money((float) $pool['claimed']) ?></b></div>
                <div class="simple-row"><span>Compound</span><b><?= format_money((float) $pool['compound']) ?></b></div>
              </div>
            </td>
            <td>
              <div class="inline-actions">
                <button type="button" class="small outline" data-toggle-details="<?= h($detailsId) ?>">Ver tudo</button>
                <!-- Uniswap sync removed (Node-based scraper not available on host) -->
              </div>
            </td>
          </tr>
          <tr id="<?= h($detailsId) ?>" class="pool-extra-row" hidden>
            <td colspan="6">
              <div class="pool-expanded">
                <div class="pool-expanded-top">
                  <div class="detail-sections">
                    <section class="detail-section">
                      <h4>Summary</h4>
                      <div class="detail-grid detail-grid-2">
                        <div class="metric-item"><span class="metric-label">Create</span><span class="metric-value"><?= h(format_datetime_display((string) $pool['create'])) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Days open</span><span class="metric-value"><?= format_number((float) $pool['days_open'], 1) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Initial total</span><span class="metric-value"><?= format_money((float) $pool['initial_total']) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Portfolio + Fees</span><span class="metric-value"><?= format_money($portfolioWithFees) ?></span></div>
                        <div class="metric-item"><span class="metric-label">HODL (benchmark)</span><span class="metric-value"><?= format_money((float) $pool['hodl_now']) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Rows / Claims / Compounds</span><span class="metric-value"><?= $txCount ?> / <?= $claimsCount ?> / <?= $compoundsCount ?></span></div>
                      </div>
                    </section>

                    <section class="detail-section">
                      <h4>Position</h4>
                      <div class="detail-grid detail-grid-2">
                        <div class="metric-item"><span class="metric-label">Deposit <?= h((string) $pool['asset_1']) ?></span><span class="metric-value"><?= format_number((float) $pool['deposit_1'], 8) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Deposit <?= h((string) $pool['asset_2']) ?></span><span class="metric-value"><?= format_number((float) $pool['deposit_2'], 8) ?></span></div>
                        <div class="metric-item"><span class="metric-label"><?= h((string) $pool['asset_1']) ?> atual</span><span class="metric-value"><?= format_number((float) $pool['current_1'], 8) ?></span></div>
                        <div class="metric-item"><span class="metric-label"><?= h((string) $pool['asset_2']) ?> atual</span><span class="metric-value"><?= format_number((float) $pool['current_2'], 8) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Weight inicial <?= h((string) $pool['asset_1']) ?></span><span class="metric-value"><?= format_number((float) $pool['initial_token1_weight_pct'], 1) ?>%</span></div>
                        <div class="metric-item"><span class="metric-label">Weight inicial <?= h((string) $pool['asset_2']) ?></span><span class="metric-value"><?= format_number((float) $pool['initial_token2_weight_pct'], 1) ?>%</span></div>
                      </div>
                    </section>

                    <section class="detail-section">
                      <h4>Fees</h4>
                      <div class="detail-grid detail-grid-2">
                        <div class="metric-item"><span class="metric-label">Fees generated</span><span class="metric-value"><?= format_money($feesGeneratedDisplay) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Total fees (U+C)</span><span class="metric-value"><?= format_money($feesTotalLp) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Unclaimed</span><span class="metric-value"><?= format_money((float) $pool['unclaimed']) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Claimed</span><span class="metric-value"><?= format_money((float) $pool['claimed']) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Compound</span><span class="metric-value"><?= format_money((float) $pool['compound']) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Unclaimed %</span><span class="metric-value"><?= format_number($unclaimedSharePct, 1) ?>%</span></div>
                      </div>
                    </section>

                    <section class="detail-section">
                      <h4>Risk and Performance</h4>
                      <div class="detail-grid detail-grid-2">
                        <div class="metric-item"><span class="metric-label">ROI</span><span class="metric-value <?= (float) $pool['roi'] < 0 ? 'error' : 'ok' ?>"><?= format_money((float) $pool['roi']) ?></span></div>
                        <div class="metric-item"><span class="metric-label">IL (M - Y)</span><span class="metric-value <?= (float) $pool['il_value'] < 0 ? 'error' : 'ok' ?>"><?= format_money((float) $pool['il_value']) ?></span></div>
                        <div class="metric-item"><span class="metric-label">Alpha (Perf vs HODL)</span><span class="metric-value <?= (float) $pool['alpha_ratio'] < 1 ? 'error' : 'ok' ?>"><?= format_number((float) $pool['alpha_ratio'], 4) ?>x</span></div>
                        <div class="metric-item"><span class="metric-label">Yield Efficiency</span><span class="metric-value <?= (float) $pool['yield_eff'] < 0 ? 'error' : ((float) $pool['yield_eff'] > 0 ? 'ok' : '') ?>"><?= format_number((float) $pool['yield_eff'], 4) ?>x</span></div>
                      </div>
                    </section>
                  </div>
                  <aside class="pool-ops-card">
                    <h4>Quick Actions</h4>
                    <form method="post" class="pool-quick-form" data-compound-form>
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="compound_pool" />
                      <input type="hidden" name="compound_pool_id" value="<?= h((string) $pool['pool_id']) ?>" />
                      <label>Deposit <?= h((string) $pool['asset_1']) ?>
                        <input type="number" step="0.00000001" min="0" name="compound_deposit_1" value="0" required />
                      </label>
                      <label>Deposit <?= h((string) $pool['asset_2']) ?>
                        <input type="number" step="0.00000001" min="0" name="compound_deposit_2" value="0" required />
                      </label>
                      <label>Deposit <?= h((string) $pool['asset_1']) ?> $
                        <input type="number" step="0.01" min="0" name="compound_deposit_1_usd" value="0" required />
                      </label>
                      <label>Deposit <?= h((string) $pool['asset_2']) ?> $
                        <input type="number" step="0.01" min="0" name="compound_deposit_2_usd" value="0" required />
                      </label>
                      <p class="muted">Compound total: <b data-compound-total>$0.00</b></p>
                      <button type="submit" class="small">Dar compound</button>
                    </form>
                    <form method="post" class="inline-actions" data-confirm="Close this pool and freeze the data?">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="close_pool" />
                      <input type="hidden" name="pool_id" value="<?= h((string) $pool['pool_id']) ?>" />
                      <button type="submit" class="small tiny danger">Close pool</button>
                    </form>
                  </aside>
                </div>
                <div
                  class="fees-history"
                  data-fees-series-b64="<?= h($generatedSeriesB64) ?>"
                  data-fees-series="<?= h($generatedSeriesJson) ?>"
                >
                  <div class="fees-history-head">
                    <h4>Tracking</h4>
                    <div class="fees-periods">
                      <button type="button" class="small active" data-fees-period="day">Day</button>
                      <button type="button" class="small" data-fees-period="month">Month</button>
                      <button type="button" class="small" data-fees-period="year">Year</button>
                    </div>
                  </div>
                  <article class="fees-chart-card">
                    <h5>Daily fees change (Unclaimed + Claimed)</h5>
                    <div class="fees-bars" data-fees-bars></div>
                    <div class="fees-more-row" data-fees-more-wrap hidden>
                      <button type="button" class="small" data-fees-load-more>Load more</button>
                      <span class="muted" data-fees-count></span>
                    </div>
                  </article>
                  <div class="tracking-lines">
                    <div>
                      <b>Claimed log:</b>
                      <?php if (count($claimedLogRows) === 0): ?>
                        <span class="muted">sem registos</span>
                      <?php else: ?>
                        <div class="tracking-log-list">
                          <?php foreach ($claimedLogRows as $log): ?>
                            <div><?= h(format_datetime_display((string) $log['date'])) ?> - <?= format_money((float) $log['value']) ?></div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                    <div>
                      <b>Compound log:</b>
                      <?php if (count($compoundLogRows) === 0): ?>
                        <span class="muted">sem registos</span>
                      <?php else: ?>
                        <div class="tracking-log-list">
                          <?php foreach ($compoundLogRows as $log): ?>
                            <div><?= h(format_datetime_display((string) $log['date'])) ?> - <?= format_money((float) $log['value']) ?></div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
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
