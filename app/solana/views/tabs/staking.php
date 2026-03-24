<?php
/** @var array $stakes */
$stakes = is_array($stakes ?? null) ? $stakes : [];
$activeStakes = array_values(array_filter($stakes, static fn($s) => ($s['status'] ?? '') === 'active'));
$closedStakes = array_values(array_filter($stakes, static fn($s) => ($s['status'] ?? '') === 'closed'));
$totalActiveUsd = array_sum(array_map(static fn($s) => (float) ($s['amount_usd'] ?? 0.0), $activeStakes));
$totalRewardsUsd = array_sum(array_map(static fn($s) => (float) ($s['rewards_usd'] ?? 0.0), $stakes));
?>

<section class="panel staking-hero">
  <div class="table-head">
    <h3>Staking Overview</h3>
    <p class="muted">Stake positions on Solana with rewards tracking.</p>
  </div>
  <div class="stats-grid staking-kpis">
    <article class="panel stat">
      <p>Active stakes</p>
      <h2><?= count($activeStakes) ?></h2>
    </article>
    <article class="panel stat">
      <p>Closed stakes</p>
      <h2><?= count($closedStakes) ?></h2>
    </article>
    <article class="panel stat">
      <p>Active USD</p>
      <h2><?= format_money((float) $totalActiveUsd) ?></h2>
    </article>
    <article class="panel stat">
      <p>Total rewards</p>
      <h2><?= format_money((float) $totalRewardsUsd) ?></h2>
    </article>
  </div>
</section>

<section class="panel table-panel">
  <div class="table-head">
    <h3>Staking Positions</h3>
    <p class="status-line">Add, edit and close stake positions.</p>
  </div>

  <form method="post" class="form-grid form-grid-tx staking-add-form">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="add_stake" />
    <label>Wallet<input name="stake_wallet" type="text" required /></label>
    <label>Validator<input name="stake_validator" type="text" required /></label>
    <label>Token<input name="stake_token" type="text" required /></label>
    <label>Amount (tokens)<input name="stake_amount_tokens" type="number" step="0.00000001" value="0" required /></label>
    <label>Amount USD<input name="stake_amount_usd" type="number" step="0.01" value="0" required /></label>
    <label>APY %<input name="stake_apy" type="number" step="0.01" value="0" required /></label>
    <label>Rewards USD<input name="stake_rewards_usd" type="number" step="0.01" value="0" /></label>
    <label>Start date<input name="stake_start_date" type="datetime-local" /></label>
    <label>Notes<input name="stake_notes" type="text" placeholder="optional" /></label>
    <div class="inline-actions">
      <button type="submit" class="small">Add stake</button>
    </div>
  </form>

  <div class="table-wrap">
    <table class="staking-table">
      <thead>
        <tr><th>#</th><th>Token</th><th>Amounts</th><th>APY / Rewards</th><th>Validator</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php if (count($stakes) === 0): ?>
          <tr><td colspan="7" class="empty">No staking positions.</td></tr>
        <?php else: ?>
          <?php foreach ($stakes as $idx => $row): ?>
            <?php $detailsId = 'stake-details-' . (int) ($row['id'] ?? ($idx + 1)); ?>
            <tr>
              <td><?= $idx + 1 ?></td>
              <td>
                <b><?= h((string) ($row['token'] ?? '')) ?></b>
                <div class="muted"><?= h((string) ($row['wallet'] ?? '')) ?></div>
              </td>
              <td>
                <div><b><?= format_number((float) ($row['amount_tokens'] ?? 0.0), 6) ?></b></div>
                <div class="muted"><?= format_money((float) ($row['amount_usd'] ?? 0.0)) ?></div>
              </td>
              <td>
                <div><b><?= format_number((float) ($row['apy'] ?? 0.0), 2) ?>%</b></div>
                <div class="muted">Rewards <?= format_money((float) ($row['rewards_usd'] ?? 0.0)) ?></div>
              </td>
              <td><?= h((string) ($row['validator'] ?? '')) ?></td>
              <td><span class="chip <?= (string) ($row['status'] ?? '') === 'closed' ? 'error' : 'ok' ?>"><?= h((string) ($row['status'] ?? 'active')) ?></span></td>
              <td>
                <div class="inline-actions">
                  <button type="button" class="small" data-toggle-details="<?= h($detailsId) ?>">Ver tudo</button>
                  <?php if ((string) ($row['status'] ?? '') !== 'closed'): ?>
                    <form method="post" data-confirm="Close this stake?">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="close_stake" />
                      <input type="hidden" name="stake_id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                      <button type="submit" class="small">Close</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php $startDateRaw = (string) ($row['start_date'] ?? ''); ?>
            <?php $startDateValue = $startDateRaw !== '' ? date('Y-m-d\\TH:i', strtotime($startDateRaw)) : ''; ?>
            <tr id="<?= h($detailsId) ?>" hidden>
              <td colspan="7">
                <div class="tx-expanded">
                  <div class="tx-expanded-head">
                    <h4>Stake details</h4>
                    <span class="muted nowrap"><?= h((string) ($row['start_date'] ?? '')) ?></span>
                  </div>
                  <form method="post" class="form-grid form-grid-tx">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update_stake" />
                    <input type="hidden" name="stake_id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                    <label>Wallet<input name="stake_wallet" type="text" value="<?= h((string) ($row['wallet'] ?? '')) ?>" /></label>
                    <label>Validator<input name="stake_validator" type="text" value="<?= h((string) ($row['validator'] ?? '')) ?>" /></label>
                    <label>Token<input name="stake_token" type="text" value="<?= h((string) ($row['token'] ?? '')) ?>" /></label>
                    <label>Amount (tokens)<input name="stake_amount_tokens" type="number" step="0.00000001" value="<?= h((string) number_format((float) ($row['amount_tokens'] ?? 0.0), 8, '.', '')) ?>" /></label>
                    <label>Amount USD<input name="stake_amount_usd" type="number" step="0.01" value="<?= h((string) number_format((float) ($row['amount_usd'] ?? 0.0), 2, '.', '')) ?>" /></label>
                    <label>APY %<input name="stake_apy" type="number" step="0.01" value="<?= h((string) number_format((float) ($row['apy'] ?? 0.0), 2, '.', '')) ?>" /></label>
                    <label>Rewards USD<input name="stake_rewards_usd" type="number" step="0.01" value="<?= h((string) number_format((float) ($row['rewards_usd'] ?? 0.0), 2, '.', '')) ?>" /></label>
                    <label>Start date<input name="stake_start_date" type="datetime-local" value="<?= h($startDateValue) ?>" /></label>
                    <label>Status
                      <select name="stake_status">
                        <option value="active" <?= (string) ($row['status'] ?? '') === 'active' ? 'selected' : '' ?>>active</option>
                        <option value="closed" <?= (string) ($row['status'] ?? '') === 'closed' ? 'selected' : '' ?>>closed</option>
                      </select>
                    </label>
                    <label>Notes<input name="stake_notes" type="text" value="<?= h((string) ($row['notes'] ?? '')) ?>" /></label>
                    <div class="inline-actions">
                      <button type="submit" class="small">Save changes</button>
                    </div>
                  </form>

                  <form method="post" class="inline-actions" data-confirm="Remove this stake?">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="remove_stake" />
                    <input type="hidden" name="stake_id" value="<?= (int) ($row['id'] ?? 0) ?>" />
                    <button type="submit" class="small danger">Remove</button>
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
