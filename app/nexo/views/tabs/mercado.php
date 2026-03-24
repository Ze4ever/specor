<?php
/** @var array $nexoDashboard */
$d = (array) ($nexoDashboard ?? []);
$rows = (array) ($d['market_rows'] ?? []);
$manualCount = 0;
$coingeckoMap = [];
$lastUpdated = '';
foreach ($rows as $row) {
    if ((int) ($row['use_manual'] ?? 0) === 1) {
        $manualCount++;
    }
    $updated = (string) ($row['updated_at'] ?? '');
    if ($updated !== '' && ($lastUpdated === '' || strcmp($updated, $lastUpdated) > 0)) {
        $lastUpdated = $updated;
    }
    if ((int) ($row['use_manual'] ?? 0) === 1) {
        continue;
    }
    $token = (string) ($row['token'] ?? '');
    $cgId = (string) ($row['coingecko_id'] ?? '');
    if ($token !== '' && $cgId !== '') {
        $coingeckoMap[$token] = $cgId;
    }
}
$totalCount = count($rows);
$autoCount = max(0, $totalCount - $manualCount);
$eurUsdRate = (float) ($d['eur_usd_rate'] ?? 0.0);
?>

<section class="panel closed-hero">
  <div class="table-head">
    <div>
      <h3>Market Overview</h3>
      <p class="muted">EUR/USD uses EURC (euro-coin) for the daily FX rate.</p>
    </div>
  </div>
  <div class="stats-grid closed-kpis">
    <article class="panel stat">
      <p>Tokens tracked</p>
      <h2><?= $totalCount ?></h2>
    </article>
    <article class="panel stat">
      <p>Auto priced</p>
      <h2><?= $autoCount ?></h2>
    </article>
    <article class="panel stat">
      <p>Manual priced</p>
      <h2><?= $manualCount ?></h2>
    </article>
    <article class="panel stat">
      <p>EUR/USD (EURC)</p>
      <h2><?= $eurUsdRate > 0 ? format_number($eurUsdRate, 4) : '-' ?></h2>
    </article>
    <article class="panel stat">
      <p>Last update</p>
      <h2><?= $lastUpdated !== '' ? h(format_datetime_display($lastUpdated)) : '-' ?></h2>
    </article>
  </div>
</section>

<section class="panel table-panel" data-coingecko-map='<?= h((string) json_encode($coingeckoMap, JSON_UNESCAPED_SLASHES)) ?>'>
  <div class="table-head">
    <div>
      <h3>Market</h3>
      <p class="status-line">Register tokens to keep a local cache, with optional manual price.</p>
    </div>
    <div class="inline-actions">
      <span class="chip">Tokens <?= count($rows) ?></span>
      <span class="chip">Manual <?= $manualCount ?></span>
      <label class="market-search">Filter
        <input id="marketFilterInput" type="text" placeholder="Search token" />
      </label>
    </div>
  </div>
  <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="form-grid" style="margin-bottom:14px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="nexo_market_save" />
    <label>Token<input name="token" type="text" placeholder="EURX" required /></label>
    <label>Coingecko ID<input name="coingecko_id" type="text" placeholder="stasis-eurs" /></label>
    <label>Manual price USD<input name="manual_price_usd" type="number" step="0.000001" min="0" value="0" /></label>
    <label>Manual mode
      <select name="use_manual">
        <option value="0">No</option>
        <option value="1">Yes</option>
      </select>
    </label>
    <button type="submit">Save Token</button>
  </form>

  <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" id="marketRefreshForm" class="inline-form-tight" style="margin-bottom:12px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="nexo_market_bulk_update" />
    <input type="hidden" id="marketPricesJson" name="market_prices_json" value="" />
    <button type="button" class="small" id="refreshMarketBtn">Refresh from market</button>
    <span class="muted">Use the cache to avoid repeated calls.</span>
  </form>

  <div class="table-wrap">
    <table class="market-table">
      <thead><tr><th>Token</th><th>Pricing</th><th>Updated</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (count($rows) === 0): ?>
          <tr><td colspan="4" class="empty">No market tokens recorded.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <?php
              $token = strtoupper(trim((string) ($row['token'] ?? '')));
              $tokenShort = $token !== '' ? substr($token, 0, 4) : '';
              $iconId = strtolower($token);
              $cgId = (string) ($row['coingecko_id'] ?? '');
              $manual = ((int) ($row['use_manual'] ?? 0)) === 1;
            ?>
            <tr data-market-token="<?= h((string) ($row['token'] ?? '')) ?>">
              <td>
                <div class="market-token">
                  <span class="token-icon" data-token="<?= h($token) ?>">
                    <img
                      src="https://cdn.jsdelivr.net/gh/spothq/cryptocurrency-icons@master/128/color/<?= h($iconId) ?>.png"
                      alt="<?= h($token) ?>"
                      loading="lazy"
                      onerror="this.style.display='none'; this.parentNode.classList.add('token-icon-fallback');"
                    />
                    <span class="token-icon-text"><?= h($tokenShort) ?></span>
                  </span>
                  <div>
                    <div><b><?= h($token !== '' ? $token : '-') ?></b></div>
                    <div class="muted"><?= $cgId !== '' ? h($cgId) : 'No coingecko id' ?></div>
                  </div>
                </div>
              </td>
              <td>
                <div class="market-price"><?= format_number((float) ($row['price_usd'] ?? 0.0), 6) ?> USD</div>
                <div class="muted">
                  <?php if ($manual): ?>
                    <span class="chip market-chip">manual</span>
                  <?php else: ?>
                    <span class="chip market-chip">auto</span>
                  <?php endif; ?>
                  <?php if ($manual): ?>
                    <span>Manual <?= format_number((float) ($row['manual_price_usd'] ?? 0.0), 6) ?> USD</span>
                  <?php endif; ?>
                </div>
              </td>
              <td><?= h(format_datetime_display((string) ($row['updated_at'] ?? ''))) ?></td>
              <td>
                <form method="post" action="<?= h((string) ($nexoFormAction ?? '')) ?>" class="inline-form-tight">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="nexo_market_remove" />
                  <input type="hidden" name="token" value="<?= h((string) ($row['token'] ?? '')) ?>" />
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
