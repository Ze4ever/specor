<?php
/** @var string $feedback */
/** @var string $feedbackType */
/** @var string $currentUsername */
/** @var array $overview */
/** @var string $activeTab */
$currentUsername = (string) ($currentUsername ?? current_username());
$activeTab = (string) ($activeTab ?? 'dashboard');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Global Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="./styles-manual.css" />
  <script defer src="./app.js"></script>
</head>
<body>
  <div class="bg-glow"></div>
  <main class="app-shell">
    <header class="topbar">
      <div>
        <p class="eyebrow">Portfolio Control</p>
        <h1>Global Dashboard</h1>
        <p class="status-line">Consolidated view of your investments</p>
      </div>
      <a href="./index.php?tab=settings" class="badge">Account: <?= h($currentUsername) ?></a>
    </header>

    <nav class="tabs">
      <a href="./index.php" class="tab-link <?= $activeTab === 'dashboard' ? 'active' : '' ?>">Global Dashboard</a>
      <a href="./uniswap.php" class="tab-link">Uniswap App</a>
      <a href="./solana.php" class="tab-link">Solana App</a>
      <a href="./nexo.php" class="tab-link">NEXO Wallet App</a>
    </nav>

    <?php if (($feedback ?? '') !== ''): ?>
      <p class="flash <?= ($feedbackType ?? 'ok') === 'error' ? 'error' : 'ok' ?>"><?= h((string) $feedback) ?></p>
    <?php endif; ?>

    <?php
      if ($activeTab === 'settings') {
          require __DIR__ . '/tabs/settings.php';
      } else {
          require __DIR__ . '/dashboard_geral.php';
      }
    ?>
  </main>
</body>
</html>
