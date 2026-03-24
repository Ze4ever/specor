<?php
/** @var array $dados */
/** @var array $poolRows */
/** @var array|null $importMeta */

$poolCatalog = [];
foreach ($dados as $row) {
    $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
    if ($poolId === '' || isset($poolCatalog[$poolId])) {
        continue;
    }
    $poolCatalog[$poolId] = [
        'wallet' => (string) ($row['wallet'] ?? ''),
        'chain' => (string) ($row['chain'] ?? ''),
        'asset_1' => (string) ($row['asset_1'] ?? ''),
        'asset_2' => (string) ($row['asset_2'] ?? ''),
    ];
}
foreach ($poolRows as $pool) {
    $poolId = normalize_pool_id((string) ($pool['pool_id'] ?? ''));
    if ($poolId === '') {
        continue;
    }
    $poolCatalog[$poolId] = [
        'wallet' => (string) ($pool['wallet'] ?? ($poolCatalog[$poolId]['wallet'] ?? '')),
        'chain' => (string) ($pool['chain'] ?? ($poolCatalog[$poolId]['chain'] ?? '')),
        'asset_1' => (string) ($pool['asset_1'] ?? ($poolCatalog[$poolId]['asset_1'] ?? '')),
        'asset_2' => (string) ($pool['asset_2'] ?? ($poolCatalog[$poolId]['asset_2'] ?? '')),
    ];
}
ksort($poolCatalog);

$filterPool = normalize_pool_id((string) ($_GET['tx_pool'] ?? ''));
$filterAction = strtolower(trim((string) ($_GET['tx_action_filter'] ?? '')));
$filterFrom = trim((string) ($_GET['tx_from'] ?? ''));
$filterTo = trim((string) ($_GET['tx_to'] ?? ''));
$filterSearch = strtolower(trim((string) ($_GET['tx_q'] ?? '')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $filterFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $filterTo = '';
}
if (!in_array($filterAction, ['', 'create', 'compound', 'remove', 'fees'], true)) {
    $filterAction = '';
}

$filteredTx = array_values(array_filter($dados, static function (array $row) use ($filterPool, $filterAction, $filterFrom, $filterTo, $filterSearch): bool {
    $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
    $action = strtolower((string) ($row['action'] ?? ''));
    $date = substr((string) ($row['date'] ?? ''), 0, 10);
    if ($filterPool !== '' && $poolId !== $filterPool) {
        return false;
    }
    if ($filterAction !== '' && $action !== $filterAction) {
        return false;
    }
    if ($filterFrom !== '' && $date !== '' && strcmp($date, $filterFrom) < 0) {
        return false;
    }
    if ($filterTo !== '' && $date !== '' && strcmp($date, $filterTo) > 0) {
        return false;
    }
    if ($filterSearch !== '') {
        $haystack = strtolower(
            implode(' ', [
                (string) ($row['pool_id'] ?? ''),
                (string) ($row['wallet'] ?? ''),
                (string) ($row['chain'] ?? ''),
                (string) ($row['asset_1'] ?? ''),
                (string) ($row['asset_2'] ?? ''),
                (string) ($row['transaction'] ?? ''),
                (string) ($row['uniswap'] ?? ''),
                (string) ($row['action'] ?? ''),
            ])
        );
        if (!str_contains($haystack, $filterSearch)) {
            return false;
        }
    }
    return true;
}));
$filteredTx = array_reverse($filteredTx);

$txCount = count($filteredTx);
$txTotalUsd = array_sum(array_map(static fn($r) => (float) ($r['total'] ?? 0.0), $filteredTx));
$txFeesUsd = array_sum(array_map(static fn($r) => (float) ($r['fees'] ?? 0.0), $filteredTx));
$txDepositUsd = array_sum(array_map(
    static fn($r) => (float) ($r['deposit_1_usd'] ?? 0.0) + (float) ($r['deposit_2_usd'] ?? 0.0),
    $filteredTx
));
$txLatestDate = $txCount > 0 ? (string) ($filteredTx[0]['date'] ?? '') : '';
$txActionCounts = ['create' => 0, 'compound' => 0, 'remove' => 0, 'fees' => 0];
foreach ($filteredTx as $row) {
    $a = strtolower((string) ($row['action'] ?? ''));
    if (isset($txActionCounts[$a])) {
        $txActionCounts[$a]++;
    }
}
?>

<section class="panel tx-hero">
  <div class="table-head">
    <h3>Transactions Overview</h3>
    <p class="muted">Resumo das transacoes filtradas e totais em USD.</p>
  </div>
  <div class="stats-grid tx-kpis">
    <article class="panel stat">
      <p>Total rows</p>
      <h2><?= $txCount ?></h2>
    </article>
    <article class="panel stat">
      <p>Total USD</p>
      <h2><?= format_money((float) $txTotalUsd) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total fees</p>
      <h2><?= format_money((float) $txFeesUsd) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total deposits</p>
      <h2><?= format_money((float) $txDepositUsd) ?></h2>
    </article>
    <article class="panel stat">
      <p>Latest tx</p>
      <h2><?= $txLatestDate !== '' ? h(format_date_display(substr($txLatestDate, 0, 10))) : '-' ?></h2>
    </article>
  </div>
  <div class="tx-action-row">
    <span class="chip tx-action-chip tx-action-create">create <?= (int) $txActionCounts['create'] ?></span>
    <span class="chip tx-action-chip tx-action-compound">compound <?= (int) $txActionCounts['compound'] ?></span>
    <span class="chip tx-action-chip tx-action-remove">remove <?= (int) $txActionCounts['remove'] ?></span>
    <span class="chip tx-action-chip tx-action-fees">fees <?= (int) $txActionCounts['fees'] ?></span>
  </div>
</section>

<section class="panel controls">
  <div class="control-head">
    <h3>Uniswap Data (manual)</h3>
  </div>

  <datalist id="txPoolIds">
    <?php foreach (array_keys($poolCatalog) as $pid): ?>
      <option value="<?= h((string) $pid) ?>"></option>
    <?php endforeach; ?>
  </datalist>

  <div class="tx-section tx-section-insert">
    <h4>Add Manual Transaction</h4>
    <form method="post" class="tx-add-form" data-tx-form data-tx-pool-catalog="<?= h((string) json_encode($poolCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="add_tx" />
      <div class="tx-add-layout">
        <div class="tx-add-main">
          <div class="tx-row tx-row-4">
            <label>Pool ID
              <input name="pool_id" type="text" list="txPoolIds" placeholder="Select existing pool" required data-tx-pool-id />
            </label>
            <label>Action
              <select name="tx_action" required>
                <option value="compound">compound</option>
                <option value="fees">fees</option>
              </select>
            </label>
            <label>Date<input name="tx_date" type="datetime-local" /></label>
            <label>Transaction URL<input name="transaction" type="text" placeholder="https://... (optional)" /></label>
          </div>
          <div class="tx-row tx-row-2">
            <label>Deposit 1<input name="deposit_1" type="number" step="0.00000001" value="0" required /></label>
            <label>Deposit 2<input name="deposit_2" type="number" step="0.00000001" value="0" required /></label>
          </div>
          <div class="tx-row tx-row-2">
            <label>Deposit 1 $<input name="deposit_1_usd" type="number" step="0.01" value="0" required /></label>
            <label>Deposit 2 $<input name="deposit_2_usd" type="number" step="0.01" value="0" required /></label>
          </div>
        </div>
        <aside class="tx-add-side">
          <div class="tx-auto-box">
            <div class="tx-auto-title">Defaults from pool</div>
            <div class="tx-auto-grid">
              <div class="tx-auto-item"><span>Wallet</span><b data-tx-wallet-view>-</b></div>
              <div class="tx-auto-item"><span>Chain</span><b data-tx-chain-view>-</b></div>
              <div class="tx-auto-item"><span>Asset 1</span><b data-tx-asset1-view>-</b></div>
              <div class="tx-auto-item"><span>Asset 2</span><b data-tx-asset2-view>-</b></div>
            </div>
            <div class="tx-form-help muted" data-tx-help>Select an existing Pool ID to load fixed data.</div>
          </div>
          <button type="submit">Add row</button>
        </aside>
      </div>
    </form>
  </div>

  <div class="tx-section tx-section-filter">
    <h4>Filters</h4>
    <form method="get" class="form-grid form-grid-tx tx-filter-form">
      <input type="hidden" name="tab" value="transactions" />
      <label>Filter Pool ID
        <input name="tx_pool" type="text" list="txPoolIds" value="<?= h($filterPool) ?>" />
      </label>
      <label>Action
        <select name="tx_action_filter">
          <option value="" <?= $filterAction === '' ? 'selected' : '' ?>>all</option>
          <option value="create" <?= $filterAction === 'create' ? 'selected' : '' ?>>create</option>
          <option value="compound" <?= $filterAction === 'compound' ? 'selected' : '' ?>>compound</option>
          <option value="remove" <?= $filterAction === 'remove' ? 'selected' : '' ?>>remove</option>
          <option value="fees" <?= $filterAction === 'fees' ? 'selected' : '' ?>>fees</option>
        </select>
      </label>
      <label>Start date<input type="date" name="tx_from" value="<?= h($filterFrom) ?>" /></label>
      <label>End date<input type="date" name="tx_to" value="<?= h($filterTo) ?>" /></label>
      <label>Search<input type="text" name="tx_q" value="<?= h((string) ($_GET['tx_q'] ?? '')) ?>" placeholder="pool, chain, wallet, asset..." /></label>
      <div class="inline-actions">
        <button type="submit" class="small">Apply filters</button>
        <a href="?tab=transactions" class="chip tx-filter-clear">Clear</a>
      </div>
    </form>
  </div>

  <div class="table-wrap">
    <table class="tx-table">
      <thead>
        <tr><th>#</th><th>Pool</th><th>Action</th><th>Pair</th><th>Amounts</th><th>Wallet / Chain</th><th>Date</th><th>Details</th></tr>
      </thead>
      <tbody>
        <?php if (count($filteredTx) === 0): ?>
          <tr><td colspan="8" class="empty">No rows for the selected filters.</td></tr>
        <?php else: ?>
          <?php foreach ($filteredTx as $idx => $row): ?>
            <?php
              $detailsId = 'tx-details-' . (int) ($row['id'] ?? ($idx + 1));
              $action = strtolower((string) ($row['action'] ?? ''));
              $actionClass = in_array($action, ['create', 'compound', 'remove', 'fees'], true) ? 'tx-action-' . $action : 'tx-action-other';
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
              $rowTotal = (float) ($row['total'] ?? 0.0);
              $rowFees = (float) ($row['fees'] ?? 0.0);
              $rowDeposits = (float) ($row['deposit_1_usd'] ?? 0.0) + (float) ($row['deposit_2_usd'] ?? 0.0);
            ?>
            <tr data-tx-row="1">
              <td><?= $idx + 1 ?></td>
              <td><?= h((string) $row['pool_id']) ?></td>
              <td><span class="chip tx-action-chip <?= h($actionClass) ?>"><?= h((string) $row['action']) ?></span></td>
              <td>
                <div class="tx-pair">
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
                <div class="tx-amount">
                  <div class="tx-amount-main"><?= format_money($rowTotal) ?></div>
                  <div class="muted">Deposits: <?= format_money($rowDeposits) ?></div>
                  <?php if ($rowFees > 0.0): ?>
                    <div class="muted">Fees: <?= format_money($rowFees) ?></div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="tx-wallet">
                  <div><?= h((string) $row['wallet']) ?></div>
                  <span class="chip tx-chain-chip"><?= h((string) $row['chain']) ?></span>
                </div>
              </td>
              <td><span class="nowrap"><?= h(format_datetime_display((string) $row['date'])) ?></span></td>
              <td>
                <button type="button" class="small" data-toggle-details="<?= h($detailsId) ?>">Ver tudo</button>
              </td>
            </tr>
            <tr id="<?= h($detailsId) ?>" class="tx-extra-row" hidden>
              <td colspan="8">
                <div class="tx-expanded">
                  <div class="tx-expanded-head">
                    <h4>Transaction details</h4>
                    <div class="tx-expanded-meta">
                      <span class="chip tx-action-chip <?= h($actionClass) ?>"><?= h((string) $row['action']) ?></span>
                      <span class="muted nowrap"><?= h(format_datetime_display((string) $row['date'])) ?></span>
                    </div>
                  </div>
                  <div class="tx-detail-grid">
                    <div class="tx-metric">
                      <span>Wallet</span>
                      <b><?= h((string) $row['wallet']) ?></b>
                    </div>
                    <div class="tx-metric">
                      <span>Chain</span>
                      <b><?= h((string) $row['chain']) ?></b>
                    </div>
                    <div class="tx-metric">
                      <span>Deposit 1</span>
                      <b><?= format_number((float) $row['deposit_1'], 8) ?></b>
                    </div>
                    <div class="tx-metric">
                      <span>Deposit 2</span>
                      <b><?= format_number((float) $row['deposit_2'], 8) ?></b>
                    </div>
                    <div class="tx-metric">
                      <span>Deposit 1 $</span>
                      <b><?= format_money((float) $row['deposit_1_usd']) ?></b>
                    </div>
                    <div class="tx-metric">
                      <span>Deposit 2 $</span>
                      <b><?= format_money((float) $row['deposit_2_usd']) ?></b>
                    </div>
                    <div class="tx-metric wide">
                      <span>TX URL</span>
                      <b><?= h((string) $row['transaction']) ?></b>
                    </div>
                    <div class="tx-metric wide">
                      <span>Uniswap URL</span>
                      <b><?= h((string) $row['uniswap']) ?></b>
                    </div>
                  </div>

                  <form method="post" class="form-grid form-grid-tx tx-edit-form" data-tx-form data-tx-pool-catalog="<?= h((string) json_encode($poolCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update_tx" />
                    <input type="hidden" name="tx_id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                    <label>Pool ID<input name="pool_id" type="text" list="txPoolIds" value="<?= h((string) $row['pool_id']) ?>" required data-tx-pool-id /></label>
                    <label>Action
                      <select name="tx_action" required>
                        <?php foreach (['create', 'compound', 'remove', 'fees'] as $a): ?>
                          <option value="<?= h($a) ?>" <?= strtolower((string) $row['action']) === $a ? 'selected' : '' ?>><?= h($a) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>Date<input name="tx_date" type="datetime-local" value="<?= h(date('Y-m-d\TH:i', strtotime((string) $row['date']))) ?>" /></label>
                    <label>Wallet<input name="wallet" type="text" value="<?= h((string) $row['wallet']) ?>" data-tx-wallet /></label>
                    <label>Chain<input name="chain" type="text" value="<?= h((string) $row['chain']) ?>" data-tx-chain /></label>
                    <label>Asset 1<input name="asset_1" type="text" value="<?= h((string) $row['asset_1']) ?>" data-tx-asset1 /></label>
                    <label>Asset 2<input name="asset_2" type="text" value="<?= h((string) $row['asset_2']) ?>" data-tx-asset2 /></label>
                    <label>Deposit 1<input name="deposit_1" type="number" step="0.00000001" value="<?= h((string) number_format((float) $row['deposit_1'], 8, '.', '')) ?>" required /></label>
                    <label>Deposit 2<input name="deposit_2" type="number" step="0.00000001" value="<?= h((string) number_format((float) $row['deposit_2'], 8, '.', '')) ?>" required /></label>
                    <label>Deposit 1 $<input name="deposit_1_usd" type="number" step="0.01" value="<?= h((string) number_format((float) $row['deposit_1_usd'], 2, '.', '')) ?>" required /></label>
                    <label>Deposit 2 $<input name="deposit_2_usd" type="number" step="0.01" value="<?= h((string) number_format((float) $row['deposit_2_usd'], 2, '.', '')) ?>" required /></label>
                    <label>Transaction URL<input name="transaction" type="text" value="<?= h((string) $row['transaction']) ?>" /></label>
                    <label>Uniswap URL<input name="uniswap" type="text" value="<?= h((string) $row['uniswap']) ?>" /></label>
                    <div class="inline-actions"><button type="submit" class="small">Save changes</button></div>
                  </form>

                  <form method="post" class="inline-actions" data-confirm="Remove this row?">
                    <input type="hidden" name="action" value="remove_tx" />
                    <?= csrf_input() ?>
                    <input type="hidden" name="tx_id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                    <button type="submit" class="small danger">Remove row</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
