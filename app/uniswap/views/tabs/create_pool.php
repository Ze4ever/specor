<?php
?>
<section class="panel controls">
  <div class="control-head">
    <h3>Create Pool</h3>
    <p class="muted">Cria a pool e regista automaticamente uma transacao `create`.</p>
  </div>
  <form method="post" class="form-grid form-grid-tx">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="create_pool" />
    <label>Wallet<input name="wallet" type="text" placeholder="553c" /></label>
    <label>Chain<input name="chain" type="text" placeholder="Arbitrum" /></label>
    <label>Pool ID<input name="pool_id" type="text" required /></label>
    <label>Date<input name="tx_date" type="datetime-local" /></label>
    <label>Asset 1<input name="asset_1" type="text" placeholder="ETH" required /></label>
    <label>Asset 2<input name="asset_2" type="text" placeholder="USDC" required /></label>
    <label>Deposit 1<input name="deposit_1" type="number" step="0.00000001" required /></label>
    <label>Deposit 2<input name="deposit_2" type="number" step="0.00000001" required /></label>
    <label>Deposit 1 $<input name="deposit_1_usd" type="number" step="0.01" required /></label>
    <label>Deposit 2 $<input name="deposit_2_usd" type="number" step="0.01" required /></label>
    <label>Transaction URL<input name="transaction" type="text" placeholder="https://..." /></label>
    <label>Uniswap URL<input name="uniswap" type="text" placeholder="https://..." /></label>
    <button type="submit">Create pool</button>
  </form>
</section>
