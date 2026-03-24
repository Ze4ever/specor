<?php
function app_pdo(): PDO
{
    static $pdo = null;
    static $initialized = false;

    if ($pdo instanceof PDO) {
        if (!$initialized) {
            db_ensure_schema($pdo);
            $initialized = true;
        }
        return $pdo;
    }

    $dbPath = defined('APP_DB_PATH') && is_string(APP_DB_PATH) && APP_DB_PATH !== ''
        ? APP_DB_PATH
        : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'app.sqlite';
    $dbDir = dirname($dbPath);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException(
            'The pdo_sqlite extension is not enabled. Enable in C:\\php\\php.ini: ' .
            'extension_dir="ext", extension=php_sqlite3.dll and extension=php_pdo_sqlite.dll; then restart the PHP server.'
        );
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    db_ensure_schema($pdo);
    $initialized = true;
    return $pdo;
}

function db_now(): string
{
    return date('Y-m-d H:i:s');
}

function db_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS asset_prices (
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            price REAL NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, token)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            wallet TEXT NOT NULL,
            chain_name TEXT NOT NULL,
            transaction_url TEXT NOT NULL,
            uniswap_url TEXT NOT NULL,
            pool_id TEXT NOT NULL,
            action_name TEXT NOT NULL,
            tx_date TEXT NOT NULL,
            asset_1 TEXT NOT NULL,
            asset_2 TEXT NOT NULL,
            deposit_1 REAL NOT NULL,
            deposit_2 REAL NOT NULL,
            deposit_1_usd REAL NOT NULL,
            deposit_2_usd REAL NOT NULL,
            total REAL NOT NULL,
            fees REAL NOT NULL DEFAULT 0.0,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS pool_overrides (
            user_id INTEGER NOT NULL,
            pool_id TEXT NOT NULL,
            current_1 REAL NOT NULL DEFAULT 0.0,
            current_2 REAL NOT NULL DEFAULT 0.0,
            unclaimed REAL NOT NULL DEFAULT 0.0,
            total_usd_override REAL NOT NULL DEFAULT 0.0,
            last_sync_at TEXT NOT NULL DEFAULT \'\' ,
            PRIMARY KEY (user_id, pool_id)
        )'
    );
    db_ensure_column_exists($pdo, 'pool_overrides', 'total_usd_override', 'REAL NOT NULL DEFAULT 0.0');
    db_ensure_column_exists($pdo, 'pool_overrides', 'last_sync_at', 'TEXT NOT NULL DEFAULT \'\'');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS pool_order (
            user_id INTEGER NOT NULL,
            pool_id TEXT NOT NULL,
            ord INTEGER NOT NULL,
            PRIMARY KEY (user_id, pool_id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS fee_snapshots (
            user_id INTEGER NOT NULL,
            pool_id TEXT NOT NULL,
            snapshot_date TEXT NOT NULL,
            unclaimed_usd REAL NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, pool_id, snapshot_date)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS closed_pools (
            user_id INTEGER NOT NULL,
            pool_id TEXT NOT NULL,
            asset_1 TEXT NOT NULL,
            asset_2 TEXT NOT NULL,
            chain_name TEXT NOT NULL,
            wallet TEXT NOT NULL,
            initial_total REAL NOT NULL,
            total_now REAL NOT NULL,
            unclaimed REAL NOT NULL,
            claimed REAL NOT NULL,
            roi REAL NOT NULL,
            apr REAL NOT NULL,
            days_open REAL NOT NULL,
            hodl_at_close REAL NOT NULL DEFAULT 0.0,
            closed_at TEXT NOT NULL,
            PRIMARY KEY (user_id, pool_id)
        )'
    );
    db_ensure_column_exists($pdo, 'closed_pools', 'hodl_at_close', 'REAL NOT NULL DEFAULT 0.0');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS token_targets (
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            target_pct REAL NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, token)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sol_asset_prices (
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            price REAL NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, token)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sol_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            wallet TEXT NOT NULL,
            chain_name TEXT NOT NULL,
            transaction_url TEXT NOT NULL,
            uniswap_url TEXT NOT NULL,
            pool_id TEXT NOT NULL,
            action_name TEXT NOT NULL,
            tx_date TEXT NOT NULL,
            asset_1 TEXT NOT NULL,
            asset_2 TEXT NOT NULL,
            deposit_1 REAL NOT NULL,
            deposit_2 REAL NOT NULL,
            deposit_1_usd REAL NOT NULL,
            deposit_2_usd REAL NOT NULL,
            total REAL NOT NULL,
            fees REAL NOT NULL DEFAULT 0.0,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sol_pool_overrides (
            user_id INTEGER NOT NULL,
            pool_id TEXT NOT NULL,
            current_1 REAL NOT NULL DEFAULT 0.0,
            current_2 REAL NOT NULL DEFAULT 0.0,
            unclaimed REAL NOT NULL DEFAULT 0.0,
            total_usd_override REAL NOT NULL DEFAULT 0.0,
            last_sync_at TEXT NOT NULL DEFAULT \'\' ,
            PRIMARY KEY (user_id, pool_id)
        )'
    );
    db_ensure_column_exists($pdo, 'sol_pool_overrides', 'total_usd_override', 'REAL NOT NULL DEFAULT 0.0');
    db_ensure_column_exists($pdo, 'sol_pool_overrides', 'last_sync_at', 'TEXT NOT NULL DEFAULT \'\'');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sol_pool_order (
            user_id INTEGER NOT NULL,
            pool_id TEXT NOT NULL,
            ord INTEGER NOT NULL,
            PRIMARY KEY (user_id, pool_id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sol_fee_snapshots (
            user_id INTEGER NOT NULL,
            pool_id TEXT NOT NULL,
            snapshot_date TEXT NOT NULL,
            unclaimed_usd REAL NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, pool_id, snapshot_date)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sol_closed_pools (
            user_id INTEGER NOT NULL,
            pool_id TEXT NOT NULL,
            asset_1 TEXT NOT NULL,
            asset_2 TEXT NOT NULL,
            chain_name TEXT NOT NULL,
            wallet TEXT NOT NULL,
            initial_total REAL NOT NULL,
            total_now REAL NOT NULL,
            unclaimed REAL NOT NULL,
            claimed REAL NOT NULL,
            roi REAL NOT NULL,
            apr REAL NOT NULL,
            days_open REAL NOT NULL,
            closed_at TEXT NOT NULL,
            PRIMARY KEY (user_id, pool_id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sol_token_targets (
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            target_pct REAL NOT NULL,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, token)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sol_stakes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            wallet TEXT NOT NULL,
            validator TEXT NOT NULL,
            token TEXT NOT NULL,
            amount_tokens REAL NOT NULL,
            amount_usd REAL NOT NULL,
            apy REAL NOT NULL,
            rewards_usd REAL NOT NULL DEFAULT 0.0,
            start_date TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'active\',
            notes TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_user_pool ON transactions(user_id, pool_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_user_date ON transactions(user_id, tx_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pool_order_user_ord ON pool_order(user_id, ord)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_fee_snapshots_user_date ON fee_snapshots(user_id, snapshot_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_closed_pools_user_closed_at ON closed_pools(user_id, closed_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_token_targets_user_token ON token_targets(user_id, token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sol_transactions_user_pool ON sol_transactions(user_id, pool_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sol_transactions_user_date ON sol_transactions(user_id, tx_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sol_pool_order_user_ord ON sol_pool_order(user_id, ord)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sol_fee_snapshots_user_date ON sol_fee_snapshots(user_id, snapshot_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sol_closed_pools_user_closed_at ON sol_closed_pools(user_id, closed_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sol_token_targets_user_token ON sol_token_targets(user_id, token)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sol_stakes_user_status ON sol_stakes(user_id, status)');
    if (function_exists('db_ensure_nexo_schema')) {
        db_ensure_nexo_schema($pdo);
    }

    db_ensure_default_user($pdo);
    db_migrate_nexo_to_app_if_needed($pdo);
}

function db_ensure_nexo_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_wallet_state (
            user_id INTEGER PRIMARY KEY,
            eurx_eur REAL NOT NULL DEFAULT 0.0,
            nexo_tokens REAL NOT NULL DEFAULT 0.0,
            eur_usd_rate REAL NOT NULL DEFAULT 1.0,
            nexo_usd_price REAL NOT NULL DEFAULT 1.0,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_flexible_terms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            coingecko_id TEXT NOT NULL DEFAULT \'\',
            principal REAL NOT NULL DEFAULT 0.0,
            principal_usd REAL NOT NULL DEFAULT 0.0,
            currency TEXT NOT NULL DEFAULT \'USD\',
            apy REAL NOT NULL DEFAULT 0.0,
            started_at TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    db_ensure_column_exists($pdo, 'nexo_flexible_terms', 'coingecko_id', 'TEXT NOT NULL DEFAULT \'\'');
    db_ensure_column_exists($pdo, 'nexo_flexible_terms', 'principal_usd', 'REAL NOT NULL DEFAULT 0.0');
    db_ensure_column_exists($pdo, 'nexo_market_tokens', 'manual_price_usd', 'REAL NOT NULL DEFAULT 0.0');
    db_ensure_column_exists($pdo, 'nexo_market_tokens', 'use_manual', 'INTEGER NOT NULL DEFAULT 0');
    db_ensure_column_exists($pdo, 'nexo_market_tokens', 'active', 'INTEGER NOT NULL DEFAULT 1');
    db_ensure_column_exists($pdo, 'nexo_market_tokens', 'updated_at', 'TEXT NOT NULL DEFAULT \'\'');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_fixed_terms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            principal_tokens REAL NOT NULL DEFAULT 0.0,
            apy REAL NOT NULL DEFAULT 0.0,
            term_months INTEGER NOT NULL DEFAULT 12,
            started_at TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            tx_date TEXT NOT NULL,
            bucket TEXT NOT NULL,
            action_name TEXT NOT NULL,
            token TEXT NOT NULL,
            amount REAL NOT NULL,
            currency TEXT NOT NULL DEFAULT \'USD\',
            apy REAL NOT NULL DEFAULT 0.0,
            term_months INTEGER NOT NULL DEFAULT 0,
            notes TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_price_cache (
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            coingecko_id TEXT NOT NULL,
            price_usd REAL NOT NULL,
            fetched_at TEXT NOT NULL,
            PRIMARY KEY (user_id, token)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_price_history (
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            price_date TEXT NOT NULL,
            price_usd REAL NOT NULL,
            created_at TEXT NOT NULL,
            PRIMARY KEY (user_id, token, price_date)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_market_tokens (
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            coingecko_id TEXT NOT NULL,
            manual_price_usd REAL NOT NULL DEFAULT 0.0,
            use_manual INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            updated_at TEXT NOT NULL,
            PRIMARY KEY (user_id, token)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_flexible_daily_rewards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            flexible_term_id INTEGER NOT NULL,
            reward_date TEXT NOT NULL,
            reward_usd REAL NOT NULL,
            reward_nexo REAL NOT NULL,
            nexo_price_usd REAL NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_fixed_daily_rewards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            fixed_term_id INTEGER NOT NULL,
            reward_date TEXT NOT NULL,
            reward_usd REAL NOT NULL,
            reward_nexo REAL NOT NULL,
            nexo_price_usd REAL NOT NULL,
            created_at TEXT NOT NULL,
            UNIQUE(user_id, fixed_term_id, reward_date)
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_flexible_finalized (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            term_id INTEGER NOT NULL,
            token TEXT NOT NULL,
            principal REAL NOT NULL,
            apy REAL NOT NULL,
            started_at TEXT NOT NULL,
            finalized_at TEXT NOT NULL,
            days_count INTEGER NOT NULL,
            total_usd REAL NOT NULL,
            total_nexo REAL NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_flexible_finalized_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            term_id INTEGER NOT NULL,
            reward_date TEXT NOT NULL,
            reward_usd REAL NOT NULL,
            reward_nexo REAL NOT NULL,
            nexo_price_usd REAL NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS nexo_log_state (
            user_id INTEGER PRIMARY KEY,
            pause_logs INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_nexo_flexible_day ON nexo_flexible_daily_rewards(user_id, flexible_term_id, reward_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nexo_fixed_rewards_user_date ON nexo_fixed_daily_rewards(user_id, reward_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nexo_fixed_rewards_user_term ON nexo_fixed_daily_rewards(user_id, fixed_term_id)');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nexo_flexible_user_active ON nexo_flexible_terms(user_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nexo_fixed_user_active ON nexo_fixed_terms(user_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nexo_tx_user_date ON nexo_transactions(user_id, tx_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nexo_rewards_user_date ON nexo_flexible_daily_rewards(user_id, reward_date)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nexo_market_user_active ON nexo_market_tokens(user_id, active)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_nexo_price_history_user_date ON nexo_price_history(user_id, token, price_date)');
}

function db_migrate_nexo_to_app_if_needed(PDO $pdo): void
{
    static $did = false;
    if ($did) {
        return;
    }
    $did = true;

    $targetPath = app_db_path();
    $targetLower = strtolower(str_replace('\\', '/', $targetPath));
    if (!str_ends_with($targetLower, '/app.sqlite')) {
        return;
    }

    $sourcePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'nexo.sqlite';
    if (!is_file($sourcePath)) {
        return;
    }

    try {
        $hasData = (int) $pdo->query('SELECT COUNT(*) FROM nexo_wallet_state')->fetchColumn();
        if ($hasData > 0) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }

    try {
        $src = new PDO('sqlite:' . $sourcePath);
        $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;
    }

    $userMap = [];
    $srcUsers = $src->query('SELECT id, username, password_hash, created_at FROM users')->fetchAll();
    foreach ($srcUsers as $row) {
        $username = strtolower(trim((string) ($row['username'] ?? '')));
        if ($username === '') {
            continue;
        }
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $existing = $stmt->fetchColumn();
        if (is_numeric($existing)) {
            $userMap[(int) ($row['id'] ?? 0)] = (int) $existing;
            continue;
        }
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
        $ins->execute([$username, (string) ($row['password_hash'] ?? ''), (string) ($row['created_at'] ?? db_now())]);
        $newId = (int) $pdo->lastInsertId();
        if ($newId > 0) {
            $userMap[(int) ($row['id'] ?? 0)] = $newId;
        }
    }

    $tables = $src->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'nexo_%'")->fetchAll();
    foreach ($tables as $tRow) {
        $table = (string) ($tRow['name'] ?? '');
        if ($table === '') {
            continue;
        }
        $cols = $src->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        if (count($cols) === 0) {
            continue;
        }
        $colNames = array_map(static fn($c) => (string) ($c['name'] ?? ''), $cols);
        $colNames = array_values(array_filter($colNames, static fn($c) => $c !== ''));
        if (count($colNames) === 0) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($colNames), '?'));
        $insertSql = 'INSERT OR IGNORE INTO ' . $table . ' (' . implode(',', $colNames) . ') VALUES (' . $placeholders . ')';
        $ins = $pdo->prepare($insertSql);

        $rows = $src->query('SELECT * FROM ' . $table)->fetchAll();
        foreach ($rows as $row) {
            $vals = [];
            foreach ($colNames as $col) {
                if ($col === 'user_id') {
                    $srcId = (int) ($row[$col] ?? 0);
                    $vals[] = $userMap[$srcId] ?? $srcId;
                } else {
                    $vals[] = $row[$col] ?? null;
                }
            }
            $ins->execute($vals);
        }
    }
}

function db_ensure_column_exists(PDO $pdo, string $tableName, string $columnName, string $definitionSql): void
{
    $stmt = $pdo->query('PRAGMA table_info(' . $tableName . ')');
    $rows = $stmt ? $stmt->fetchAll() : [];
    foreach ($rows as $row) {
        if (strcasecmp((string) ($row['name'] ?? ''), $columnName) === 0) {
            return;
        }
    }
    $pdo->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $columnName . ' ' . $definitionSql);
}

function db_ensure_default_user(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $defaultUser = trim((string) getenv('APP_DEFAULT_USER'));
    $defaultPass = (string) getenv('APP_DEFAULT_PASS');
    if ($defaultUser === '') {
        $defaultUser = 'admin';
    }
    if ($defaultPass === '') {
        $defaultPass = 'admin123';
    }

    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
    $stmt->execute([
        strtolower($defaultUser),
        password_hash($defaultPass, PASSWORD_DEFAULT),
        db_now(),
    ]);
}
