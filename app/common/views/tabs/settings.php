<?php
/** @var string $currentUsername */
?>
<section class="panel controls">
  <div class="control-head">
    <h3>Account Settings</h3>
  </div>
  <p class="hint">Account: <b><?= h($currentUsername) ?></b> (shared across all apps)</p>

  <article class="panel settings-card">
    <h4>Security</h4>
    <form method="post" class="account-form-grid">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="change_password" />
      <label>Current password<input type="password" name="current_password" required /></label>
      <label>New password<input type="password" name="new_password" required minlength="8" /></label>
      <label>Confirm new password<input type="password" name="new_password_confirm" required minlength="8" /></label>
      <button type="submit" class="small">Change password</button>
    </form>
    <form method="post" class="inline-form-tight" style="margin-top:8px;">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="logout" />
      <button type="submit" class="small">Log out</button>
    </form>
  </article>
</section>
