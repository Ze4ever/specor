<?php
/** @var array $nexoDashboard */
$d = (array) ($nexoDashboard ?? []);
$txRows = (array) ($d['transactions'] ?? []);
$txCount = count($txRows);
$bucketCounts = ['wallet' => 0, 'flexible' => 0, 'fixed' => 0];
foreach ($txRows as $row) {
    $bucket = strtolower((string) ($row['bucket'] ?? ''));
    if (isset($bucketCounts[$bucket])) {
        $bucketCounts[$bucket]++;
    }
}
$lastTx = $txCount > 0 ? (string) ($txRows[0]['tx_date'] ?? '') : '';
?>

<section class="panel closed-hero">
  <div class="table-head">
    <div>
      <h3>Transactions Overview</h3>
      <p class="muted">Manual record of wallet and term movements.</p>
    </div>
  </div>
  <div class="stats-grid closed-kpis">
    <article class="panel stat">
      <p>All transactions</p>
      <h2><?= $txCount ?></h2>
    </article>
    <article class="panel stat">
      <p>Wallet</p>
      <h2><?= $bucketCounts['wallet'] ?></h2>
    </article>
    <article class="panel stat">
      <p>Flexible</p>
      <h2><?= $bucketCounts['flexible'] ?></h2>
    </article>
    <article class="panel stat">
      <p>Fixed</p>
      <h2><?= $bucketCounts['fixed'] ?></h2>
    </article>
    <article class="panel stat">
      <p>Last tx</p>
      <h2><?= $lastTx !== '' ? h(format_datetime_display($lastTx)) : '-' ?></h2>
    </article>
  </div>
</section>

<section class="panel table-panel">
  <div class="table-head">
    <div>
      <h3>NEXO Transactions</h3>
      <p class="status-line">Manual record of wallet and term movements. APY/Term only apply to flexible/fixed entries.</p>
    </div>
    <div class="inline-actions">
      <span class="chip">Total <?= $txCount ?></span>
    </div>
  </div>
  <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="form-grid" style="margin-bottom:14px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="nexo_add_transaction" />
    <label>Date<input name="tx_date" type="datetime-local" required /></label>
    <label>Bucket
      <select name="bucket">
        <option value="wallet">wallet</option>
        <option value="flexible">flexible</option>
        <option value="fixed">fixed</option>
      </select>
    </label>
    <label>Action
      <select name="tx_action">
        <option value="add">add</option>
        <option value="remove">remove</option>
        <option value="adjust">adjust</option>
        <option value="finalize">finalize</option>
      </select>
    </label>
    <label>Token<input name="token" type="text" value="NEXO" required /></label>
    <label>Amount<input name="amount" type="number" step="0.01" required /></label>
    <label>Currency<input name="currency" type="text" value="NEXO" required /></label>
    <label>APY %<input name="apy" type="number" step="0.01" min="0" value="0" /></label>
    <label>Term (months)<input name="term_months" type="number" step="1" min="0" value="0" /></label>
    <label>Notes<input name="notes" type="text" /></label>
    <button type="submit">Add transaction</button>
  </form>
  <p class="hint">Tip: use currency `EUR` or `USD` for fiat movements and `TOKEN` for token adjustments.</p>
  <div class="table-wrap">
    <table class="compact-table">
      <thead><tr><th>Transaction</th><th>Type</th><th>Asset</th><th>Amount</th><th>APY / Term</th><th>Notes</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (count($txRows) === 0): ?>
          <tr><td colspan="7" class="empty">No transactions.</td></tr>
        <?php else: ?>
          <?php foreach ($txRows as $row): ?>
            <?php
              $actionName = strtolower((string) ($row['action'] ?? ''));
              $amountClass = '';
              $impactLabel = 'Neutral';
              if (in_array($actionName, ['remove', 'finalize'], true)) {
                  $amountClass = 'error';
                  $impactLabel = 'Outflow';
              } elseif ($actionName === 'add') {
                  $amountClass = 'ok';
                  $impactLabel = 'Inflow';
              }
            ?>
            <tr>
              <td>
                <div class="metric-stack metric-primary">
                  <span class="metric-label">Tx</span>
                  <span class="metric-value">#<?= (int) ($row['id'] ?? 0) ?></span>
                  <span class="metric-sub"><?= h(format_datetime_display((string) ($row['tx_date'] ?? ''))) ?></span>
                </div>
              </td>
              <td>
                <?php
                  $bucket = h((string) ($row['bucket'] ?? ''));
                  $action = h((string) ($row['action'] ?? ''));
                ?>
                <div class="metric-stack">
                  <span class="metric-label">Type</span>
                  <span class="metric-value"><?= $bucket ?></span>
                  <span class="metric-sub">
                    <span class="chip"><?= $bucket ?></span>
                    <span class="chip"><?= $action ?></span>
                  </span>
                </div>
              </td>
              <td>
                <div class="metric-stack">
                  <span class="metric-label">Token</span>
                  <span class="metric-value"><?= h((string) ($row['token'] ?? '')) ?></span>
                  <span class="metric-sub"><?= h((string) ($row['currency'] ?? '')) ?></span>
                </div>
              </td>
              <td>
                <div class="metric-stack">
                  <span class="metric-label">Amount</span>
                  <span class="metric-value <?= $amountClass ?>"><?= format_number((float) ($row['amount'] ?? 0.0), 2) ?></span>
                  <span class="metric-sub"><?= h((string) ($row['currency'] ?? '')) ?> · <?= h($impactLabel) ?></span>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
                  <div><span>APY</span><b><?= ((float) ($row['apy'] ?? 0.0)) > 0 ? format_number((float) ($row['apy'] ?? 0.0), 2) . '%' : '-' ?></b></div>
                  <div><span>Term</span><b><?= ((int) ($row['term_months'] ?? 0)) > 0 ? (int) ($row['term_months'] ?? 0) . ' months' : '-' ?></b></div>
                </div>
              </td>
              <td><?= h((string) ($row['notes'] ?? '')) !== '' ? h((string) ($row['notes'] ?? '')) : '-' ?></td>
              <td>
                <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="inline-form-tight">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="nexo_delete_transaction" />
                  <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>" />
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
