<?php
/** @var string $activeTab */
/** @var string $feedback */
/** @var string $feedbackType */
/** @var string $currentUsername */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Solana Pools</title>
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
        <p class="eyebrow">Solana Control</p>
        <h1>Pools & Staking</h1>
      </div>
      <a href="./index.php?tab=settings" class="badge">Account: <?= h($currentUsername) ?></a>
    </header>

    <nav class="tabs">
      <a href="./index.php" class="tab-link">Global Dashboard</a>
      <a href="./uniswap.php" class="tab-link">Uniswap App</a>
      <a href="./solana.php" class="tab-link active">Solana App</a>
      <a href="./nexo.php" class="tab-link">NEXO Wallet App</a>
    </nav>

    <nav class="tabs">
      <a href="?tab=dashboard" class="tab-link <?= $activeTab === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <a href="?tab=pools" class="tab-link <?= $activeTab === 'pools' ? 'active' : '' ?>">Pools</a>
      <a href="?tab=fees" class="tab-link <?= $activeTab === 'fees' ? 'active' : '' ?>">Fees</a>
      <a href="?tab=closed" class="tab-link <?= $activeTab === 'closed' ? 'active' : '' ?>">Closed Pools</a>
      <a href="?tab=staking" class="tab-link <?= $activeTab === 'staking' ? 'active' : '' ?>">Staking</a>
      <a href="?tab=transactions" class="tab-link <?= $activeTab === 'transactions' ? 'active' : '' ?>">Transactions</a>
      <a href="?tab=market" class="tab-link <?= $activeTab === 'market' ? 'active' : '' ?>">Market</a>
      <a href="?tab=create_pool" class="tab-link tab-link-create <?= $activeTab === 'create_pool' ? 'active' : '' ?>">Create Pool</a>
    </nav>

    <?php if ($feedback !== ''): ?>
      <p class="flash <?= $feedbackType === 'error' ? 'error' : 'ok' ?>"><?= h($feedback) ?></p>
    <?php endif; ?>

    <?php
      if ($activeTab === 'dashboard') {
          require __DIR__ . '/tabs/dashboard.php';
      } elseif ($activeTab === 'pools') {
          require __DIR__ . '/tabs/pools.php';
      } elseif ($activeTab === 'create_pool') {
          require __DIR__ . '/tabs/create_pool.php';
      } elseif ($activeTab === 'fees') {
          require __DIR__ . '/tabs/fees.php';
      } elseif ($activeTab === 'closed') {
          require __DIR__ . '/tabs/closed.php';
      } elseif ($activeTab === 'staking') {
          require __DIR__ . '/tabs/staking.php';
      } elseif ($activeTab === 'transactions') {
          require __DIR__ . '/tabs/transactions.php';
      } elseif ($activeTab === 'settings') {
          require dirname(__DIR__, 2) . '/common/views/tabs/settings.php';
      } else {
          require __DIR__ . '/tabs/market.php';
      }
    ?>
  </main>
</body>
</html>
