<?php
/** @var string $feedback */
/** @var string $feedbackType */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= h(app_display_name()) ?> - Sign in</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./styles-manual.css" />
</head>
<body>
  <div class="bg-glow"></div>
  <main class="app-shell" style="max-width:900px;">
    <header class="topbar">
      <div>
        <p class="eyebrow"><?= h(app_display_name()) ?> Control</p>
        <h1>Protected Access</h1>
      </div>
      <span class="badge">DB: <?= h(app_path_label(app_db_path())) ?></span>
    </header>

    <?php if ($feedback !== ''): ?>
      <p class="flash <?= $feedbackType === 'error' ? 'error' : 'ok' ?>"><?= h($feedback) ?></p>
    <?php endif; ?>

    <section class="stats-grid" style="grid-template-columns:1fr 1fr;">
      <article class="panel">
        <h3>Sign in</h3>
        <form method="post" class="form-grid">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="login" />
          <label>Username<input name="username" type="text" required /></label>
          <label>Password<input name="password" type="password" required /></label>
          <button type="submit">Sign in</button>
        </form>
      </article>

      <article class="panel">
        <h3>Create account</h3>
        <form method="post" class="form-grid">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="register" />
          <label>Username<input name="username" type="text" required /></label>
          <label>Password<input name="password" type="password" required /></label>
          <button type="submit">Sign up</button>
        </form>
        <p class="hint">Default first user: <code>admin / admin123</code> (change it right after).</p>
      </article>
    </section>
  </main>
</body>
</html>
