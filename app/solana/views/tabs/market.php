<?php
/** @var array $assetPrices */
/** @var array $coingeckoMap */
$tokenCount = count($assetPrices);
$mappedInPrices = 0;
foreach ($assetPrices as $token => $_price) {
    if (isset($coingeckoMap[(string) $token])) {
        $mappedInPrices++;
    }
}
$manualCount = max(0, $tokenCount - $mappedInPrices);
$coveragePct = $tokenCount > 0 ? ($mappedInPrices / $tokenCount) * 100 : 0.0;
?>
<section class="panel market-hero">
  <div class="table-head">
    <h3>Market Overview</h3>
    <p class="muted">Resumo rapido de tokens e origem dos precos.</p>
  </div>
  <div class="stats-grid market-kpis">
    <article class="panel stat">
      <p>Tracked tokens</p>
      <h2><?= $tokenCount ?></h2>
    </article>
    <article class="panel stat">
      <p>Mapped tokens</p>
      <h2><?= $mappedInPrices ?></h2>
    </article>
    <article class="panel stat">
      <p>Unmapped tokens</p>
      <h2><?= $manualCount ?></h2>
    </article>
    <article class="panel stat">
      <p>Coverage</p>
      <h2><?= format_number((float) $coveragePct, 1) ?>%</h2>
    </article>
  </div>
</section>

<section class="panel controls" data-coingecko-map='<?= h((string) json_encode($coingeckoMap, JSON_UNESCAPED_SLASHES)) ?>'>
  <div class="control-head market-actions">
    <div>
      <h3>Market (Assets Prices)</h3>
      <p class="hint">Update dynamic prices and recalculate pools.</p>
    </div>
    <form method="post" id="marketRefreshForm" class="inline-form-tight">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="batch_update_prices" />
      <input type="hidden" id="marketPricesJson" name="market_prices_json" value="" />
      <button type="button" id="refreshMarketBtn" class="market-refresh">Refresh from market</button>
    </form>
  </div>

  <div class="market-forms">
    <form method="post" class="form-inline market-add-form" data-market-auto-add>
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="add_price" />
      <input type="hidden" name="price" value="" />
      <input name="token" type="text" placeholder="Token (SOL)" required />
      <input name="coingecko_id" type="text" placeholder="Coingecko id (optional)" />
      <button type="submit">Add token (auto)</button>
    </form>
    <label class="market-search">
      <span>Search token</span>
      <input type="text" id="marketFilterInput" placeholder="eth, usdc, btc..." />
    </label>
  </div>

  <div class="table-wrap">
    <table class="market-table">
      <thead><tr><th>Token</th><th>Price</th><th>Source</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if (count($assetPrices) === 0): ?>
          <tr><td colspan="4" class="empty">No prices yet.</td></tr>
        <?php else: ?>
          <?php foreach ($assetPrices as $token => $price): ?>
            <?php
              $tokenUpper = strtoupper((string) $token);
              $tokenShort = $tokenUpper !== '' ? substr($tokenUpper, 0, 3) : '';
              $iconMap = [
                  'ZRO' => 'zrx',
                  'WLD' => 'worldcoin',
              ];
              $tokenIcon = strtolower($iconMap[$tokenUpper] ?? $tokenUpper);
              $isMapped = isset($coingeckoMap[(string) $token]);
            ?>
            <tr data-market-token="<?= h((string) $token) ?>">
              <td>
                <div class="market-token">
                  <span class="token-icon" data-token="<?= h($tokenUpper) ?>">
                    <img
                      src="https://cdn.jsdelivr.net/gh/spothq/cryptocurrency-icons@master/128/color/<?= h($tokenIcon) ?>.png"
                      alt="<?= h($tokenUpper) ?>"
                      loading="lazy"
                      onerror="this.style.display='none'; this.parentNode.classList.add('token-icon-fallback');"
                    />
                    <span class="token-icon-text"><?= h($tokenShort) ?></span>
                  </span>
                  <b><?= h((string) $token) ?></b>
                </div>
              </td>
              <td><span class="market-price"><?= format_number((float) $price, 4) ?> $</span></td>
              <td>
                <span class="chip market-chip <?= $isMapped ? 'ok' : '' ?>">
                  <?= $isMapped ? 'Mapped' : 'Manual' ?>
                </span>
              </td>
              <td>
                <form method="post">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="remove_price" />
                  <input type="hidden" name="token" value="<?= h((string) $token) ?>" />
                  <button type="submit" class="small danger">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
