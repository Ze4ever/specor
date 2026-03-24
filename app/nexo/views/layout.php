<?php
/** @var string $feedback */
/** @var string $feedbackType */
/** @var string $currentUsername */
/** @var string $activeTab */
/** @var array $nexoDashboard */
$d = (array) ($nexoDashboard ?? []);
$asOf = (string) ($d['as_of'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>NEXO Wallet</title>
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
        <p class="eyebrow">NEXO Wallet Control</p>
        <h1>NEXO Wallet</h1>
        <p class="status-line">Consolidated status <?= $asOf !== '' ? 'as of ' . h($asOf) : '' ?> (UTC)</p>
      </div>
      <a href="./index.php?tab=settings" class="badge">Account: <?= h($currentUsername) ?></a>
    </header>

    <nav class="tabs">
      <a href="./index.php" class="tab-link">Global Dashboard</a>
      <a href="./uniswap.php" class="tab-link">Uniswap App</a>
      <a href="./solana.php" class="tab-link">Solana App</a>
      <a href="./nexo.php" class="tab-link active">NEXO Wallet App</a>
    </nav>

    <nav class="tabs">
      <a href="?tab=dashboard" class="tab-link <?= $activeTab === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <a href="?tab=mercado" class="tab-link <?= $activeTab === 'mercado' ? 'active' : '' ?>">Market</a>
      <a href="?tab=flexible" class="tab-link <?= $activeTab === 'flexible' ? 'active' : '' ?>">Flexible</a>
      <a href="?tab=finalizados" class="tab-link <?= $activeTab === 'finalizados' ? 'active' : '' ?>">Closed</a>
      <a href="?tab=fixed" class="tab-link <?= $activeTab === 'fixed' ? 'active' : '' ?>">Fixed</a>
      <a href="?tab=transactions" class="tab-link <?= $activeTab === 'transactions' ? 'active' : '' ?>">Transactions</a>
    </nav>

    <?php if ($feedback !== ''): ?>
      <p class="flash <?= $feedbackType === 'error' ? 'error' : 'ok' ?>"><?= h($feedback) ?></p>
    <?php endif; ?>

    <?php
      if ($activeTab === 'mercado') {
          require __DIR__ . '/tabs/mercado.php';
      } elseif ($activeTab === 'flexible') {
          require __DIR__ . '/tabs/flexible.php';
      } elseif ($activeTab === 'finalizados') {
          require __DIR__ . '/tabs/finalizados.php';
      } elseif ($activeTab === 'fixed') {
          require __DIR__ . '/tabs/fixed.php';
      } elseif ($activeTab === 'transactions') {
          require __DIR__ . '/tabs/transactions.php';
      } elseif ($activeTab === 'settings') {
          require dirname(__DIR__, 2) . '/common/views/tabs/settings.php';
      } else {
          require __DIR__ . '/tabs/dashboard.php';
      }
    ?>
  </main>
</body>
</html>
