<?php
/** @var array $nexoDashboard */
$d = (array) ($nexoDashboard ?? []);
$fixedRows = (array) ($d['fixed_rows'] ?? []);
$fixedLogs = (array) ($d['recent_fixed_rewards'] ?? []);
$fixedStats = (array) ($d['fixed_reward_stats'] ?? []);
$lastLogDay = (string) ($fixedStats['last_day'] ?? '');
$avgDailyUsd = ((int) ($fixedStats['total_rows'] ?? 0)) > 0
  ? ((float) ($fixedStats['total_usd'] ?? 0.0) / (int) ($fixedStats['total_rows'] ?? 1))
  : 0.0;
$annualFixedTokens = (float) ($d['annual_fixed_tokens'] ?? 0.0);
$termProjectedTokens = (float) ($d['term_projected_tokens'] ?? 0.0);
?>

<section class="panel closed-hero">
  <div class="table-head">
    <div>
      <h3>Fixed Overview</h3>
      <p class="muted">Rewards are logged daily based on the NEXO/USD price for the day.</p>
    </div>
  </div>
  <div class="stats-grid closed-kpis">
    <article class="panel stat">
      <p>Active terms</p>
      <h2><?= count($fixedRows) ?></h2>
    </article>
    <article class="panel stat">
      <p>Daily logs</p>
      <h2><?= (int) ($fixedStats['total_rows'] ?? 0) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total generated USD</p>
      <h2><?= format_money((float) ($fixedStats['total_usd'] ?? 0.0)) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total generated NEXO</p>
      <h2><?= format_number((float) ($fixedStats['total_nexo'] ?? 0.0), 4) ?></h2>
    </article>
    <article class="panel stat">
      <p>Avg/day USD</p>
      <h2><?= $avgDailyUsd > 0 ? format_money($avgDailyUsd) : '-' ?></h2>
    </article>
    <article class="panel stat">
      <p>Annual yield (tokens)</p>
      <h2><?= format_number($annualFixedTokens, 3) ?></h2>
    </article>
    <article class="panel stat">
      <p>Projected term yield</p>
      <h2><?= format_number($termProjectedTokens, 3) ?></h2>
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
      <h3>Fixed Terms</h3>
      <p class="status-line">Daily rewards use the NEXO price of each day. Gains per day are logged automatically.</p>
    </div>
    <div class="inline-actions">
      <span class="chip">Active <?= count($fixedRows) ?></span>
      <span class="chip">Annual yield <?= format_number((float) ($d['annual_fixed_tokens'] ?? 0.0), 3) ?> NEXO</span>
    </div>
  </div>
  <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="form-grid" style="margin-bottom:14px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="nexo_add_fixed_term" />
    <label>Token<input name="token" type="text" value="NEXO" required /></label>
    <label>Principal Tokens<input name="principal_tokens" type="number" step="0.01" min="0.01" required /></label>
    <label>APY %<input name="apy" type="number" step="0.01" min="0" required /></label>
    <label>Term (months)<input name="term_months" type="number" step="1" min="1" required /></label>
    <label>Date<input name="started_at" type="datetime-local" required /></label>
    <button type="submit">Add fixed</button>
  </form>
  <p class="hint">Yield per term assumes annual APY and adjusts to the number of months.</p>
  <div class="table-wrap">
    <table class="compact-table">
      <thead><tr><th>Term</th><th>Token</th><th>Base & APY</th><th>Daily Pace</th><th>Generated</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (count($fixedRows) === 0): ?>
          <tr><td colspan="6" class="empty">No fixed terms.</td></tr>
        <?php else: ?>
          <?php foreach ($fixedRows as $row): ?>
            <tr>
              <td>
                <?php
                  $startLabel = format_datetime_display((string) ($row['started_at'] ?? ''));
                  $lastDay = (string) ($row['last_generated_day'] ?? '');
                  $termLabel = (int) ($row['term_months'] ?? 0) . ' months';
                  $subLine = $lastDay !== '' ? ($termLabel . ' · Start ' . $startLabel . ' · Last ' . $lastDay) : ($termLabel . ' · Start ' . $startLabel);
                ?>
                <div class="metric-stack metric-primary">
                  <span class="metric-label">Term</span>
                  <span class="metric-value">#<?= (int) ($row['id'] ?? 0) ?></span>
                  <span class="metric-sub"><?= h($subLine) ?></span>
                </div>
              </td>
              <td>
                <div class="metric-stack">
                  <span class="metric-label">Token</span>
                  <span class="metric-value"><?= h((string) ($row['token'] ?? '-')) ?></span>
                  <span class="metric-sub"><?= format_number((float) ($row['principal_tokens'] ?? 0.0), 2) ?> tokens</span>
                </div>
              </td>
              <td>
                <div class="metric-stack metric-list">
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
                  <input type="hidden" name="action" value="nexo_remove_fixed_term" />
                  <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                  <button type="submit" class="danger small">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel table-panel">
  <div class="table-head">
    <div>
      <h3>Daily Log (00:00 UTC)</h3>
      <p class="status-line">Rewards are calculated daily based on NEXO/USD price for that day.</p>
    </div>
    <div class="inline-actions">
      <span class="chip">Logs <?= (int) ($fixedStats['total_rows'] ?? 0) ?></span>
    </div>
  </div>
  <div class="table-wrap">
    <table class="compact-table">
      <thead><tr><th>Log</th><th>Token & Price</th><th>Reward</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (count($fixedLogs) === 0): ?>
          <tr><td colspan="4" class="empty">No daily logs yet.</td></tr>
        <?php else: ?>
          <?php foreach ($fixedLogs as $row): ?>
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
                  <div><span>Token</span><b><?= h((string) ($row['token'] ?? '-')) ?></b></div>
                  <div><span>NEXO/USD</span><b><?= format_number((float) ($row['nexo_price_usd'] ?? 0.0), 6) ?></b></div>
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
                  <input type="hidden" name="action" value="nexo_delete_fixed_reward" />
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
