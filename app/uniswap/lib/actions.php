<?php
function build_user_backup_payload(int $userId, string $username): array
{
    $overrides = store_get_pool_overrides($userId);
    $tokenTargets = store_get_token_targets($userId);
    return [
        'meta' => [
            'app' => app_context() . '-tracker',
            'version' => 1,
            'username' => $username,
            'exported_at' => db_now(),
        ],
        'asset_prices' => store_get_asset_prices($userId),
        'dados_uniswap' => store_get_transactions($userId),
        'pool_overrides' => array_map(
            static fn(string $poolId, array $row): array => [
                'pool_id' => $poolId,
                'current_1' => (float) ($row['current_1'] ?? 0.0),
                'current_2' => (float) ($row['current_2'] ?? 0.0),
                'unclaimed' => (float) ($row['unclaimed'] ?? 0.0),
                'total_usd_override' => (float) ($row['total_usd_override'] ?? 0.0),
                'last_sync_at' => (string) ($row['last_sync_at'] ?? ''),
            ],
            array_keys($overrides),
            array_values($overrides)
        ),
        'pool_order' => store_get_pool_order($userId),
        'token_targets' => $tokenTargets,
        'fee_snapshots' => store_get_fee_snapshots($userId),
        'closed_pools' => store_get_closed_pools($userId),
    ];
}

function normalize_backup_payload(array $payload): array
{
    $prices = $payload['asset_prices'] ?? [];
    $dados = $payload['dados_uniswap'] ?? [];
    if (!is_array($prices) || !is_array($dados)) {
        throw new InvalidArgumentException('JSON de backup sem estrutura valida.');
    }

    $normalizedPrices = [];
    foreach ($prices as $token => $price) {
        $tk = strtoupper(trim((string) $token));
        if ($tk !== '' && is_valid_symbol($tk) && is_numeric($price)) {
            $normalizedPrices[$tk] = (float) $price;
        }
    }

    $normalizedRows = [];
    foreach ($dados as $row) {
        $pool = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        $actionName = strtolower(trim((string) ($row['action'] ?? '')));
        $asset1 = strtoupper(trim((string) ($row['asset_1'] ?? '')));
        $asset2 = strtoupper(trim((string) ($row['asset_2'] ?? '')));
        if (
            $pool === '' ||
            !in_array($actionName, ['create', 'compound', 'remove', 'fees'], true) ||
            !is_valid_symbol($asset1) ||
            !is_valid_symbol($asset2)
        ) {
            continue;
        }

        $normalizedRows[] = [
            'wallet' => normalize_wallet_label((string) ($row['wallet'] ?? '')),
            'chain' => normalize_chain_label((string) ($row['chain'] ?? '')),
            'transaction' => (string) ($row['transaction'] ?? ''),
            'uniswap' => (string) ($row['uniswap'] ?? ''),
            'pool_id' => $pool,
            'action' => $actionName,
            'date' => normalize_datetime_input((string) ($row['date'] ?? '')),
            'asset_1' => $asset1,
            'asset_2' => $asset2,
            'deposit_1' => max(0.0, (float) ($row['deposit_1'] ?? 0)),
            'deposit_2' => max(0.0, (float) ($row['deposit_2'] ?? 0)),
            'deposit_1_usd' => max(0.0, (float) ($row['deposit_1_usd'] ?? 0)),
            'deposit_2_usd' => max(0.0, (float) ($row['deposit_2_usd'] ?? 0)),
            'total' => (float) ($row['total'] ?? ((float) ($row['deposit_1_usd'] ?? 0) + (float) ($row['deposit_2_usd'] ?? 0))),
            'fees' => (float) ($row['fees'] ?? ($actionName === 'fees' ? (float) ($row['total'] ?? 0) : 0.0)),
        ];
    }

    $poolOverrides = $payload['pool_overrides'] ?? [];
    if (!is_array($poolOverrides)) {
        $poolOverrides = [];
    }
    $normalizedOverrides = [];
    foreach ($poolOverrides as $row) {
        $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        if ($poolId === '') {
            continue;
        }
        $normalizedOverrides[] = [
            'pool_id' => $poolId,
            'current_1' => max(0.0, (float) ($row['current_1'] ?? 0.0)),
            'current_2' => max(0.0, (float) ($row['current_2'] ?? 0.0)),
            'unclaimed' => max(0.0, (float) ($row['unclaimed'] ?? 0.0)),
            'total_usd_override' => max(0.0, (float) ($row['total_usd_override'] ?? 0.0)),
            'last_sync_at' => (string) ($row['last_sync_at'] ?? ''),
        ];
    }

    $poolOrderer = $payload['pool_order'] ?? [];
    if (!is_array($poolOrderer)) {
        $poolOrderer = [];
    }
    $normalizedOrderer = array_values(array_unique(array_filter(array_map(
        static fn($v) => normalize_pool_id((string) $v),
        $poolOrderer
    ), static fn($v) => $v !== '')));

    $tokenTargets = $payload['token_targets'] ?? [];
    if (!is_array($tokenTargets)) {
        $tokenTargets = [];
    }
    $normalizedTokenTargets = [];
    foreach ($tokenTargets as $token => $targetPct) {
        $tk = strtoupper(trim((string) $token));
        if (!is_valid_symbol($tk) || !is_numeric($targetPct)) {
            continue;
        }
        $pct = (float) $targetPct;
        $normalizedTokenTargets[$tk] = max(0.0, min(100.0, $pct));
    }

    $feeSnapshots = $payload['fee_snapshots'] ?? [];
    if (!is_array($feeSnapshots)) {
        $feeSnapshots = [];
    }
    $normalizedSnapshots = [];
    foreach ($feeSnapshots as $row) {
        $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        $snapshotDate = trim((string) ($row['snapshot_date'] ?? ''));
        if ($poolId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) {
            continue;
        }
        $normalizedSnapshots[] = [
            'pool_id' => $poolId,
            'snapshot_date' => $snapshotDate,
            'unclaimed_usd' => max(0.0, (float) ($row['unclaimed_usd'] ?? 0.0)),
        ];
    }

    $closedPools = $payload['closed_pools'] ?? [];
    if (!is_array($closedPools)) {
        $closedPools = [];
    }
    $normalizedClosed = [];
    foreach ($closedPools as $row) {
        $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        $asset1 = strtoupper(trim((string) ($row['asset_1'] ?? '')));
        $asset2 = strtoupper(trim((string) ($row['asset_2'] ?? '')));
        if ($poolId === '' || !is_valid_symbol($asset1) || !is_valid_symbol($asset2)) {
            continue;
        }
        $normalizedClosed[] = [
            'pool_id' => $poolId,
            'asset_1' => $asset1,
            'asset_2' => $asset2,
            'chain' => normalize_chain_label((string) ($row['chain'] ?? '')),
            'wallet' => normalize_wallet_label((string) ($row['wallet'] ?? '')),
            'initial_total' => (float) ($row['initial_total'] ?? 0.0),
            'total_now' => (float) ($row['total_now'] ?? 0.0),
            'unclaimed' => max(0.0, (float) ($row['unclaimed'] ?? 0.0)),
            'claimed' => max(0.0, (float) ($row['claimed'] ?? 0.0)),
            'roi' => (float) ($row['roi'] ?? 0.0),
            'apr' => (float) ($row['apr'] ?? 0.0),
            'days_open' => max(0.0, (float) ($row['days_open'] ?? 0.0)),
            'hodl_at_close' => max(0.0, (float) ($row['hodl_at_close'] ?? 0.0)),
            'closed_at' => normalize_datetime_input((string) ($row['closed_at'] ?? db_now())),
        ];
    }

    return [
        'asset_prices' => $normalizedPrices,
        'dados_uniswap' => $normalizedRows,
        'pool_overrides' => $normalizedOverrides,
        'pool_order' => $normalizedOrderer,
        'token_targets' => $normalizedTokenTargets,
        'fee_snapshots' => $normalizedSnapshots,
        'closed_pools' => $normalizedClosed,
    ];
}

function stream_csv_download(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    if (!is_resource($out)) {
        exit;
    }
    fputcsv($out, $header, ';');
    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

function safe_current_username(): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', current_username()) ?? '';
    return $safe !== '' ? $safe : 'user';
}

function collect_uniswap_pool_urls(array $transactions): array
{
    $map = [];
    foreach ($transactions as $row) {
        $poolId = normalize_pool_id((string) ($row['pool_id'] ?? ''));
        if ($poolId === '') {
            continue;
        }
        $url = trim((string) ($row['uniswap'] ?? ''));
        if ($url === '' || !is_valid_http_url($url)) {
            continue;
        }
        if (stripos($url, 'uniswap.org/positions') === false) {
            continue;
        }
        $map[$poolId] = $url;
    }
    return $map;
}

function handle_post_actions(string $importJsonPath): array
{
    $feedback = '';
    $feedbackType = 'ok';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return [$feedback, $feedbackType];
    }

    $action = (string) ($_POST['action'] ?? '');
    $knownActions = [
        'change_password',
        'export_user_data',
        'export_transactions_csv',
        'export_asset_prices_csv',
        'import_user_data_file',
        'import_from_json',
        'add_price',
        'batch_update_prices',
        'remove_price',
        'add_tx',
        'update_tx',
        'create_pool',
        'remove_tx',
        'save_pool_override',
        'save_pool_order',
        'save_token_target',
        'save_token_targets_batch',
        'save_daily_fees',
        'delete_fee_snapshot',
        'delete_fee_snapshot_day',
        'claim_pool_fees',
        'remove_pool_fees',
        'compound_pool',
        'close_pool',
        'restore_pool',
        'clear_all',
    ];
    if (!in_array($action, $knownActions, true)) {
        return [$feedback, $feedbackType];
    }

    if (!verify_csrf()) {
        return ['Invalid request (CSRF).', 'error'];
    }

    $userId = current_user_id();
    if ($userId === null) {
        return ['Sessao invalida.', 'error'];
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password_confirm'] ?? '');
        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            return ['Fill current password, new password, and confirmation.', 'error'];
        }
        if ($newPassword !== $confirmPassword) {
            return ['New password confirmation does not match.', 'error'];
        }

        [$ok, $msg] = change_current_user_password($userId, $currentPassword, $newPassword);
        return [$msg, $ok ? 'ok' : 'error'];
    }

    if ($action === 'export_user_data') {
        $payload = build_user_backup_payload($userId, current_username());
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return ['Error generating JSON backup.', 'error'];
        }

        $safeUser = safe_current_username();
        $filename = 'backup-' . $safeUser . '-' . date('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    if ($action === 'export_transactions_csv') {
        $rows = store_get_transactions($userId);
        $csvRows = [];
        foreach ($rows as $row) {
            $csvRows[] = [
                (int) ($row['id'] ?? 0),
                (string) ($row['wallet'] ?? ''),
                (string) ($row['chain'] ?? ''),
                (string) ($row['pool_id'] ?? ''),
                (string) ($row['action'] ?? ''),
                (string) ($row['date'] ?? ''),
                (string) ($row['asset_1'] ?? ''),
                (string) ($row['asset_2'] ?? ''),
                (float) ($row['deposit_1'] ?? 0.0),
                (float) ($row['deposit_2'] ?? 0.0),
                (float) ($row['deposit_1_usd'] ?? 0.0),
                (float) ($row['deposit_2_usd'] ?? 0.0),
                (float) ($row['total'] ?? 0.0),
                (float) ($row['fees'] ?? 0.0),
                (string) ($row['transaction'] ?? ''),
                (string) ($row['uniswap'] ?? ''),
            ];
        }
        $safeUser = safe_current_username();
        stream_csv_download(
            'transactions-' . $safeUser . '-' . date('Ymd-His') . '.csv',
            ['id', 'wallet', 'chain', 'pool_id', 'action', 'date', 'asset_1', 'asset_2', 'deposit_1', 'deposit_2', 'deposit_1_usd', 'deposit_2_usd', 'total', 'fees', 'transaction_url', 'uniswap_url'],
            $csvRows
        );
    }

    if ($action === 'export_asset_prices_csv') {
        $rows = [];
        foreach (store_get_asset_prices($userId) as $token => $price) {
            $rows[] = [(string) $token, (float) $price];
        }
        $safeUser = safe_current_username();
        stream_csv_download(
            'asset-prices-' . $safeUser . '-' . date('Ymd-His') . '.csv',
            ['token', 'price'],
            $rows
        );
    }

    if ($action === 'import_user_data_file') {
        $upload = $_FILES['backup_file'] ?? null;
        if (!is_array($upload) || ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE)) !== UPLOAD_ERR_OK) {
            return ['Select a valid JSON file to import.', 'error'];
        }
        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['Invalid upload.', 'error'];
        }

        $payload = json_decode((string) file_get_contents($tmpPath), true);
        if (!is_array($payload)) {
            return ['Invalid JSON file.', 'error'];
        }

        try {
            $normalized = normalize_backup_payload($payload);
        } catch (Throwable $e) {
            return ['Invalid backup: ' . $e->getMonthsage(), 'error'];
        }

        store_replace_asset_prices($userId, $normalized['asset_prices']);
        store_replace_transactions($userId, $normalized['dados_uniswap']);
        store_replace_pool_overrides($userId, $normalized['pool_overrides']);
        store_replace_pool_order($userId, $normalized['pool_order']);
        store_replace_token_targets($userId, $normalized['token_targets']);
        store_replace_fee_snapshots($userId, $normalized['fee_snapshots']);
        store_replace_closed_pools($userId, $normalized['closed_pools']);

        return ['Backup importado: ' . count($normalized['dados_uniswap']) . ' transactions e ' . count($normalized['asset_prices']) . ' tokens.', 'ok'];
    }

    if ($action === 'import_from_json') {
        if (!is_file($importJsonPath)) {
            return ['Import file not found at ' . app_path_label($importJsonPath) . '.', 'error'];
        }

        $payload = json_decode((string) file_get_contents($importJsonPath), true);
        if (!is_array($payload)) {
            return ['Invalid import JSON.', 'error'];
        }
        try {
            $normalized = normalize_backup_payload($payload);
        } catch (Throwable $e) {
            return ['Invalid import JSON: ' . $e->getMonthsage(), 'error'];
        }

        store_replace_asset_prices($userId, $normalized['asset_prices']);
        store_replace_transactions($userId, $normalized['dados_uniswap']);
        store_replace_pool_overrides($userId, $normalized['pool_overrides']);
        store_replace_pool_order($userId, $normalized['pool_order']);
        store_replace_token_targets($userId, $normalized['token_targets']);
        store_replace_fee_snapshots($userId, $normalized['fee_snapshots']);
        store_replace_closed_pools($userId, $normalized['closed_pools']);

        return ['Import concluido: ' . count($normalized['dados_uniswap']) . ' linhas carregadas.', 'ok'];
    }

    if ($action === 'add_price') {
        $token = strtoupper(to_text('token'));
        $price = to_float('price');
        if (!is_valid_symbol($token)) {
            return ['Invalid token.', 'error'];
        }
        if ($price < 0) {
            return ['Invalid price.', 'error'];
        }

        store_upsert_asset_price($userId, $token, $price);
        return ['Preco atualizado.', 'ok'];
    }

    if ($action === 'batch_update_prices') {
        $decoded = json_decode((string) ($_POST['market_prices_json'] ?? ''), true);
        if (!is_array($decoded)) {
            return ['Resposta de mercado invalida.', 'error'];
        }

        $updated = 0;
        foreach ($decoded as $token => $price) {
            $tk = strtoupper(trim((string) $token));
            if (!is_valid_symbol($tk) || !is_numeric($price) || (float) $price < 0) {
                continue;
            }
            store_upsert_asset_price($userId, $tk, (float) $price);
            $updated++;
        }
        return ['Precos de mercado atualizados: ' . $updated . ' token(s).', 'ok'];
    }

    if ($action === 'remove_price') {
        $token = strtoupper(to_text('token'));
        if (!is_valid_symbol($token)) {
            return ['Invalid token.', 'error'];
        }
        store_remove_asset_price($userId, $token);
        return ['Token removido.', 'ok'];
    }

    if ($action === 'add_tx') {
        $poolId = normalize_pool_id(to_text('pool_id'));
        $txAction = strtolower(to_text('tx_action'));
        if ($poolId === '') {
            return ['Provide a valid Pool ID.', 'error'];
        }
        if (!in_array($txAction, ['compound', 'fees'], true)) {
            return ['On the Transactions tab, manual action is only compound or fees.', 'error'];
        }

        $assetPrices = store_get_asset_prices($userId);
        $dados = store_get_transactions($userId);
        $overrides = store_get_pool_overrides($userId);
        $feeStats = build_fee_stats(store_get_fee_snapshots($userId));
        $latestByPool = store_get_latest_unclaimed_by_pool($userId);
        [$snapshotPools] = build_pool_snapshot($dados, $assetPrices, $overrides, $feeStats['by_pool'], $latestByPool);
        if (!isset($snapshotPools[$poolId])) {
            return ['Pool ID not found in existing pools.', 'error'];
        }
        $pool = $snapshotPools[$poolId];

        $transaction = to_text('transaction');
        $uniswap = to_text('uniswap');
        if (($transaction !== '' && !is_valid_http_url($transaction)) || ($uniswap !== '' && !is_valid_http_url($uniswap))) {
            return ['URLs invalidas. Usa http:// ou https://', 'error'];
        }

        $d1 = to_float('deposit_1');
        $d2 = to_float('deposit_2');
        $u1 = to_float('deposit_1_usd');
        $u2 = to_float('deposit_2_usd');
        if ($d1 < 0 || $d2 < 0 || $u1 < 0 || $u2 < 0) {
            return ['Negative values are not allowed.', 'error'];
        }

        store_insert_transaction($userId, [
            'wallet' => (string) ($pool['wallet'] ?? ''),
            'chain' => (string) ($pool['chain'] ?? ''),
            'transaction' => $transaction !== '' ? $transaction : 'manual:tx',
            'uniswap' => $uniswap !== '' ? $uniswap : (string) ($pool['uniswap'] ?? 'manual:uniswap'),
            'pool_id' => $poolId,
            'action' => $txAction,
            'date' => normalize_datetime_input(to_text('tx_date')),
            'asset_1' => (string) ($pool['asset_1'] ?? ''),
            'asset_2' => (string) ($pool['asset_2'] ?? ''),
            'deposit_1' => $d1,
            'deposit_2' => $d2,
            'deposit_1_usd' => $u1,
            'deposit_2_usd' => $u2,
            'total' => $u1 + $u2,
            'fees' => $txAction === 'fees' ? ($u1 + $u2) : 0.0,
        ]);
        return ['Linha adicionada em Dados_Uniswap.', 'ok'];
    }

    if ($action === 'update_tx') {
        $txId = (int) ($_POST['tx_id'] ?? 0);
        if ($txId <= 0) {
            return ['Linha invalida para editar.', 'error'];
        }
        $existing = store_get_transaction_by_id($userId, $txId);
        if ($existing === null) {
            return ['Row not found.', 'error'];
        }

        $poolId = normalize_pool_id((string) ($_POST['pool_id'] ?? ''));
        $txAction = strtolower((string) ($_POST['tx_action'] ?? ''));
        if ($poolId === '' || !in_array($txAction, ['create', 'compound', 'remove', 'fees'], true)) {
            return ['Pool/Action invalidos na edicao.', 'error'];
        }

        $transaction = trim((string) ($_POST['transaction'] ?? ''));
        $uniswap = trim((string) ($_POST['uniswap'] ?? ''));
        if (!is_valid_tx_reference($transaction) || !is_valid_tx_reference($uniswap)) {
            return ['Referencias invalidas na edicao. Usa https:// ou internal:/manual:.', 'error'];
        }

        $d1 = (float) str_replace(',', '.', (string) ($_POST['deposit_1'] ?? '0'));
        $d2 = (float) str_replace(',', '.', (string) ($_POST['deposit_2'] ?? '0'));
        $u1 = (float) str_replace(',', '.', (string) ($_POST['deposit_1_usd'] ?? '0'));
        $u2 = (float) str_replace(',', '.', (string) ($_POST['deposit_2_usd'] ?? '0'));
        if ($d1 < 0 || $d2 < 0 || $u1 < 0 || $u2 < 0) {
            return ['Negative values are not allowed.', 'error'];
        }

        $wallet = normalize_wallet_label((string) ($_POST['wallet'] ?? (string) ($existing['wallet'] ?? '')));
        $chain = normalize_chain_label((string) ($_POST['chain'] ?? (string) ($existing['chain'] ?? '')));
        $asset1 = strtoupper(trim((string) ($_POST['asset_1'] ?? (string) ($existing['asset_1'] ?? ''))));
        $asset2 = strtoupper(trim((string) ($_POST['asset_2'] ?? (string) ($existing['asset_2'] ?? ''))));

        if (in_array($txAction, ['compound', 'fees'], true)) {
            $assetPrices = store_get_asset_prices($userId);
            $dados = store_get_transactions($userId);
            $overrides = store_get_pool_overrides($userId);
            $feeStats = build_fee_stats(store_get_fee_snapshots($userId));
            $latestByPool = store_get_latest_unclaimed_by_pool($userId);
            [$snapshotPools] = build_pool_snapshot($dados, $assetPrices, $overrides, $feeStats['by_pool'], $latestByPool);
            if (!isset($snapshotPools[$poolId])) {
                return ['Pool ID not found for compound/fees.', 'error'];
            }
            $pool = $snapshotPools[$poolId];
            $wallet = (string) ($pool['wallet'] ?? $wallet);
            $chain = (string) ($pool['chain'] ?? $chain);
            $asset1 = (string) ($pool['asset_1'] ?? $asset1);
            $asset2 = (string) ($pool['asset_2'] ?? $asset2);
            if ($uniswap === '') {
                $uniswap = (string) ($pool['uniswap'] ?? $uniswap);
            }
        }

        if (!is_valid_symbol($asset1) || !is_valid_symbol($asset2)) {
            return ['Assets invalidos na edicao.', 'error'];
        }

        $total = $u1 + $u2;
        $fees = $txAction === 'fees' ? $total : 0.0;
        $ok = store_update_transaction($userId, $txId, [
            'wallet' => $wallet,
            'chain' => $chain,
            'transaction' => $transaction !== '' ? $transaction : 'manual:tx',
            'uniswap' => $uniswap !== '' ? $uniswap : 'manual:uniswap',
            'pool_id' => $poolId,
            'action' => $txAction,
            'date' => normalize_datetime_input((string) ($_POST['tx_date'] ?? '')),
            'asset_1' => $asset1,
            'asset_2' => $asset2,
            'deposit_1' => $d1,
            'deposit_2' => $d2,
            'deposit_1_usd' => $u1,
            'deposit_2_usd' => $u2,
            'total' => $total,
            'fees' => $fees,
        ]);
        if (!$ok) {
            return ['No changes in this row.', 'error'];
        }
        return ['Transacao atualizada.', 'ok'];
    }

    if ($action === 'create_pool') {
        $poolId = normalize_pool_id(to_text('pool_id'));
        $asset1 = strtoupper(to_text('asset_1'));
        $asset2 = strtoupper(to_text('asset_2'));
        if ($poolId === '' || !is_valid_symbol($asset1) || !is_valid_symbol($asset2)) {
            return ['Preenche pool e assets validos.', 'error'];
        }

        $transaction = to_text('transaction');
        $uniswap = to_text('uniswap');
        if (!is_valid_http_url($transaction) || !is_valid_http_url($uniswap)) {
            return ['URLs invalidas. Usa http:// ou https://', 'error'];
        }

        $d1 = to_float('deposit_1');
        $d2 = to_float('deposit_2');
        $u1 = to_float('deposit_1_usd');
        $u2 = to_float('deposit_2_usd');
        if ($d1 < 0 || $d2 < 0 || $u1 < 0 || $u2 < 0) {
            return ['Negative values are not allowed.', 'error'];
        }

        store_insert_transaction($userId, [
            'wallet' => normalize_wallet_label(to_text('wallet')),
            'chain' => normalize_chain_label(to_text('chain')),
            'transaction' => $transaction,
            'uniswap' => $uniswap,
            'pool_id' => $poolId,
            'action' => 'create',
            'date' => normalize_datetime_input(to_text('tx_date')),
            'asset_1' => $asset1,
            'asset_2' => $asset2,
            'deposit_1' => $d1,
            'deposit_2' => $d2,
            'deposit_1_usd' => $u1,
            'deposit_2_usd' => $u2,
            'total' => $u1 + $u2,
            'fees' => 0.0,
        ]);
        return ['Pool criada e registada em transactions.', 'ok'];
    }

    if ($action === 'remove_tx') {
        $txId = (int) ($_POST['tx_id'] ?? 0);
        if ($txId <= 0) {
            return ['Linha invalida.', 'error'];
        }
        $txRow = store_get_transaction_by_id($userId, $txId);
        if ($txRow === null) {
            return ['Row not found.', 'error'];
        }
        store_delete_transaction($userId, $txId);

        $txAction = strtolower((string) ($txRow['action'] ?? ''));
        $poolId = normalize_pool_id((string) ($txRow['pool_id'] ?? ''));
        if ($txAction === 'fees' && $poolId !== '') {
            $feesValue = (float) ($txRow['fees'] ?? 0.0);
            $totalValue = (float) ($txRow['total'] ?? 0.0);
            $amount = abs($feesValue) > 0.0000001 ? $feesValue : $totalValue;
            if (abs($amount) > 0.0000001) {
                $latestByPool = store_get_latest_unclaimed_by_pool($userId);
                $baseUnclaimed = (float) ($latestByPool[$poolId] ?? 0.0);
                $targetDate = store_get_latest_fee_snapshot_date($userId, $poolId) ?? date('Y-m-d');
                $newUnclaimed = max(0.0, $baseUnclaimed + $amount);
                store_upsert_fee_snapshot($userId, $poolId, $targetDate, $newUnclaimed);
            }
        }
        return ['Linha removida.', 'ok'];
    }

    if ($action === 'save_pool_override') {
        $poolId = normalize_pool_id(to_text('pool_id'));
        if ($poolId === '') {
            return ['Pool invalida.', 'error'];
        }
        $current1 = to_float('current_1');
        $current2 = to_float('current_2');
        if ($current1 < 0 || $current2 < 0) {
            return ['Current cannot be negative.', 'error'];
        }

        $currentOverrides = store_get_pool_overrides($userId);
        $keepUnclaimed = isset($currentOverrides[$poolId]) ? (float) ($currentOverrides[$poolId]['unclaimed'] ?? 0.0) : 0.0;
        store_upsert_pool_override($userId, $poolId, $current1, $current2, $keepUnclaimed);
        return ['Current guardado.', 'ok'];
    }

    if ($action === 'save_pool_order') {
        $order = json_decode((string) ($_POST['pool_order_json'] ?? '[]'), true);
        if (!is_array($order)) {
            return ['Orderem invalida.', 'error'];
        }
        $normalized = array_values(array_filter(array_map(static fn($v) => normalize_pool_id(trim((string) $v)), $order), static fn($v) => $v !== ''));
        store_replace_pool_order($userId, array_values(array_unique($normalized)));
        return ['Orderem das pools atualizada.', 'ok'];
    }

    if ($action === 'save_token_target') {
        $token = strtoupper(trim((string) ($_POST['token'] ?? '')));
        if (!is_valid_symbol($token)) {
            return ['Invalid token for target.', 'error'];
        }
        $targetRaw = str_replace(',', '.', (string) ($_POST['target_pct'] ?? ''));
        if (!preg_match('/^\d+$/', $targetRaw)) {
            return ['Invalid target %. Use integers only.', 'error'];
        }
        $targetPct = (int) $targetRaw;
        if ($targetPct < 0 || $targetPct > 100) {
            return ['Alvo % deve ficar entre 0 e 100.', 'error'];
        }
        $currentMap = store_get_token_targets($userId);
        $currentMap[$token] = $targetPct;
        if (array_sum(array_map(static fn($v) => (int) $v, $currentMap)) > 100) {
            return ['Sum of targets cannot exceed 100%.', 'error'];
        }
        store_upsert_token_target($userId, $token, $targetPct);
        return ['Alvo atualizado para ' . $token . ': ' . $targetPct . '%.', 'ok'];
    }

    if ($action === 'save_token_targets_batch') {
        $rows = $_POST['target_pct'] ?? [];
        if (!is_array($rows)) {
            return ['Invalid target data.', 'error'];
        }

        $validated = [];
        $sumTargets = 0;
        foreach ($rows as $tokenRaw => $targetRaw) {
            $token = strtoupper(trim((string) $tokenRaw));
            if (!is_valid_symbol($token)) {
                continue;
            }
            $valueRaw = str_replace(',', '.', (string) $targetRaw);
            if (!preg_match('/^\d+$/', $valueRaw)) {
                return ['Alvos invalidos. Usa apenas numeros inteiros.', 'error'];
            }
            $targetPct = (int) $valueRaw;
            if ($targetPct < 0 || $targetPct > 100) {
                return ['Cada alvo deve ficar entre 0 e 100.', 'error'];
            }
            $validated[$token] = $targetPct;
        }
        if (count($validated) === 0) {
            return ['No valid tokens to update.', 'error'];
        }
        foreach ($validated as $targetPct) {
            $sumTargets += (int) $targetPct;
        }
        if ($sumTargets > 100) {
            return ['Sum of targets cannot exceed 100%.', 'error'];
        }

        $currentMap = store_get_token_targets($userId);
        $updated = 0;
        foreach ($validated as $token => $targetPct) {
            $existing = isset($currentMap[$token]) ? (float) $currentMap[$token] : null;
            if ($existing !== null && ((int) round($existing)) === $targetPct) {
                continue;
            }
            store_upsert_token_target($userId, $token, $targetPct);
            $updated++;
        }

        if ($updated === 0) {
            return ['No changes to targets.', 'ok'];
        }
        return ['Alvos atualizados: ' . $updated . ' token(s).', 'ok'];
    }

    if ($action === 'save_daily_fees') {
        $snapshotDate = trim((string) ($_POST['snapshot_date'] ?? ''));
        $poolIds = $_POST['pool_id'] ?? [];
        $unclaimedRows = $_POST['unclaimed'] ?? [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) {
            return ['Date de fees invalida.', 'error'];
        }
        if (!is_array($poolIds) || !is_array($unclaimedRows) || count($poolIds) !== count($unclaimedRows)) {
            return ['Linhas de fees invalidas.', 'error'];
        }

        $saved = 0;
        foreach ($poolIds as $idx => $poolIdRaw) {
            $poolId = normalize_pool_id((string) $poolIdRaw);
            if ($poolId === '') {
                continue;
            }
            $value = (float) str_replace(',', '.', (string) ($unclaimedRows[$idx] ?? '0'));
            if ($value < 0) {
                $value = 0;
            }
            store_upsert_fee_snapshot($userId, $poolId, $snapshotDate, $value);
            $saved++;
        }
        return ['Registos de fees guardados: ' . $saved . '.', 'ok'];
    }

    if ($action === 'delete_fee_snapshot') {
        $poolId = normalize_pool_id((string) ($_POST['pool_id'] ?? ''));
        $snapshotDate = trim((string) ($_POST['snapshot_date'] ?? ''));
        if ($poolId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) {
            return ['Invalid snapshot.', 'error'];
        }
        $deleted = store_delete_fee_snapshot($userId, $poolId, $snapshotDate);
        if (!$deleted) {
            return ['Snapshot not found.', 'error'];
        }
        return ['Snapshot removido: ' . $poolId . ' em ' . $snapshotDate . '.', 'ok'];
    }

    if ($action === 'delete_fee_snapshot_day') {
        $snapshotDate = trim((string) ($_POST['snapshot_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) {
            return ['Date de snapshot invalida.', 'error'];
        }

        $allSnaps = store_get_fee_snapshots($userId);
        $deleted = 0;
        foreach ($allSnaps as $snapRow) {
            $rowDate = (string) ($snapRow['snapshot_date'] ?? '');
            if ($rowDate !== $snapshotDate) {
                continue;
            }
            $poolId = normalize_pool_id((string) ($snapRow['pool_id'] ?? ''));
            if ($poolId === '') {
                continue;
            }
            if (store_delete_fee_snapshot($userId, $poolId, $snapshotDate)) {
                $deleted++;
            }
        }
        if ($deleted === 0) {
            return ['No snapshots to remove for that date.', 'error'];
        }
        return ['Snapshots removidos no dia ' . $snapshotDate . ': ' . $deleted . '.', 'ok'];
    }

    if ($action === 'claim_pool_fees') {
        $poolId = normalize_pool_id((string) ($_POST['claim_pool_id'] ?? ''));
        if ($poolId === '') {
            return ['Pool invalida para claim.', 'error'];
        }

        $latestByPool = store_get_latest_unclaimed_by_pool($userId);
        $toClaim = (float) ($latestByPool[$poolId] ?? 0.0);
        if ($toClaim <= 0.0) {
            return ['No fees to claim for this pool.', 'error'];
        }

        $assetPrices = store_get_asset_prices($userId);
        $dados = store_get_transactions($userId);
        $overrides = store_get_pool_overrides($userId);
        $feeStats = build_fee_stats(store_get_fee_snapshots($userId));
        [$snapshotPools] = build_pool_snapshot($dados, $assetPrices, $overrides, $feeStats['by_pool'], $latestByPool);
        if (!isset($snapshotPools[$poolId])) {
            return ['Pool not found for claim.', 'error'];
        }
        $pool = $snapshotPools[$poolId];

        store_insert_transaction($userId, [
            'wallet' => (string) $pool['wallet'],
            'chain' => (string) $pool['chain'],
            'transaction' => 'internal:claim',
            'uniswap' => (string) $pool['uniswap'],
            'pool_id' => $poolId,
            'action' => 'fees',
            'date' => db_now(),
            'asset_1' => (string) $pool['asset_1'],
            'asset_2' => (string) $pool['asset_2'],
            'deposit_1' => 0.0,
            'deposit_2' => 0.0,
            'deposit_1_usd' => 0.0,
            'deposit_2_usd' => 0.0,
            'total' => $toClaim,
            'fees' => $toClaim,
        ]);

        $latestSnapshotDate = store_get_latest_fee_snapshot_date($userId, $poolId);
        if ($latestSnapshotDate !== null) {
            store_upsert_fee_snapshot($userId, $poolId, $latestSnapshotDate, 0.0);
        }
        store_upsert_fee_snapshot($userId, $poolId, date('Y-m-d'), 0.0);
        return ['Claim efetuado: ' . format_money($toClaim) . ' na pool ' . $poolId . '.', 'ok'];
    }

    if ($action === 'remove_pool_fees') {
        $poolId = normalize_pool_id((string) ($_POST['remove_pool_id'] ?? ''));
        if ($poolId === '') {
            return ['Pool invalida para remover fees.', 'error'];
        }

        $latestClaim = store_get_latest_unreverted_claim_fee_tx($userId, $poolId);
        if ($latestClaim === null || (int) ($latestClaim['id'] ?? 0) <= 0) {
            return ['No automatic claim to remove for this pool.', 'error'];
        }

        $amount = max(0.0, (float) ($latestClaim['total'] ?? 0.0));
        if ($amount <= 0.0) {
            return ['No valid value to remove.', 'error'];
        }

        $assetPrices = store_get_asset_prices($userId);
        $dados = store_get_transactions($userId);
        $overrides = store_get_pool_overrides($userId);
        $feeStats = build_fee_stats(store_get_fee_snapshots($userId));
        $latestByPool = store_get_latest_unclaimed_by_pool($userId);
        [$snapshotPools] = build_pool_snapshot($dados, $assetPrices, $overrides, $feeStats['by_pool'], $latestByPool);
        if (!isset($snapshotPools[$poolId])) {
            return ['Pool not found to remove fees.', 'error'];
        }
        $pool = $snapshotPools[$poolId];

        store_insert_transaction($userId, [
            'wallet' => (string) $pool['wallet'],
            'chain' => (string) $pool['chain'],
            'transaction' => 'internal:remove-fees',
            'uniswap' => (string) $pool['uniswap'],
            'pool_id' => $poolId,
            'action' => 'fees',
            'date' => db_now(),
            'asset_1' => (string) $pool['asset_1'],
            'asset_2' => (string) $pool['asset_2'],
            'deposit_1' => 0.0,
            'deposit_2' => 0.0,
            'deposit_1_usd' => 0.0,
            'deposit_2_usd' => 0.0,
            'total' => -$amount,
            'fees' => -$amount,
        ]);

        $latestSnapshotDate = store_get_latest_fee_snapshot_date($userId, $poolId);
        $baseUnclaimed = (float) ($latestByPool[$poolId] ?? 0.0);
        $restoredUnclaimed = $baseUnclaimed + $amount;
        $targetDate = $latestSnapshotDate ?? date('Y-m-d');
        store_upsert_fee_snapshot($userId, $poolId, $targetDate, $restoredUnclaimed);

        return ['Claim removido: ' . format_money($amount) . ' devolvido para unclaimed na pool ' . $poolId . '.', 'ok'];
    }

    if ($action === 'compound_pool') {
        $poolId = normalize_pool_id((string) ($_POST['compound_pool_id'] ?? ''));
        if ($poolId === '') {
            return ['Pool invalida para compound.', 'error'];
        }

        $d1 = (float) str_replace(',', '.', (string) ($_POST['compound_deposit_1'] ?? '0'));
        $d2 = (float) str_replace(',', '.', (string) ($_POST['compound_deposit_2'] ?? '0'));
        $u1 = (float) str_replace(',', '.', (string) ($_POST['compound_deposit_1_usd'] ?? '0'));
        $u2 = (float) str_replace(',', '.', (string) ($_POST['compound_deposit_2_usd'] ?? '0'));
        if ($d1 < 0 || $d2 < 0 || $u1 < 0 || $u2 < 0) {
            return ['Negative values are not allowed in compound.', 'error'];
        }
        if (($d1 + $d2 + $u1 + $u2) <= 0.0) {
            return ['Preenche valores de compound antes de guardar.', 'error'];
        }

        $assetPrices = store_get_asset_prices($userId);
        $dados = store_get_transactions($userId);
        $overrides = store_get_pool_overrides($userId);
        $feeStats = build_fee_stats(store_get_fee_snapshots($userId));
        $latestByPool = store_get_latest_unclaimed_by_pool($userId);
        [$snapshotPools] = build_pool_snapshot($dados, $assetPrices, $overrides, $feeStats['by_pool'], $latestByPool);
        if (!isset($snapshotPools[$poolId])) {
            return ['Pool not found for compound.', 'error'];
        }
        $pool = $snapshotPools[$poolId];

        store_insert_transaction($userId, [
            'wallet' => (string) $pool['wallet'],
            'chain' => (string) $pool['chain'],
            'transaction' => 'internal:compound',
            'uniswap' => (string) $pool['uniswap'],
            'pool_id' => $poolId,
            'action' => 'compound',
            'date' => db_now(),
            'asset_1' => (string) $pool['asset_1'],
            'asset_2' => (string) $pool['asset_2'],
            'deposit_1' => $d1,
            'deposit_2' => $d2,
            'deposit_1_usd' => $u1,
            'deposit_2_usd' => $u2,
            'total' => $u1 + $u2,
            'fees' => 0.0,
        ]);

        if (isset($overrides[$poolId])) {
            $ov = $overrides[$poolId];
            store_upsert_pool_override(
                $userId,
                $poolId,
                (float) ($ov['current_1'] ?? 0.0) + $d1,
                (float) ($ov['current_2'] ?? 0.0) + $d2,
                (float) ($ov['unclaimed'] ?? 0.0)
            );
        }

        return ['Compound registado na pool ' . $poolId . ': ' . format_money($u1 + $u2) . '.', 'ok'];
    }

    if ($action === 'close_pool') {
        $poolId = normalize_pool_id(to_text('pool_id'));
        $assetPrices = store_get_asset_prices($userId);
        $dados = store_get_transactions($userId);
        $overrides = store_get_pool_overrides($userId);
        $feeStats = build_fee_stats(store_get_fee_snapshots($userId));
        $latestUnclaimedByPool = [];
        foreach ($feeStats['by_pool'] as $feePoolId => $stats) {
            $latestUnclaimedByPool[$feePoolId] = (float) ($stats['latest_unclaimed'] ?? 0.0);
        }
        [$snapshotPools] = build_pool_snapshot($dados, $assetPrices, $overrides, $feeStats['by_pool'], $latestUnclaimedByPool);

        if ($poolId === '' || !isset($snapshotPools[$poolId])) {
            return ['Pool not found.', 'error'];
        }

        $pool = $snapshotPools[$poolId];
        $closedAt = db_now();
        $hodlAtClose = ((float) ($pool['initial_token1_qty'] ?? 0.0) * (float) ($pool['price_1'] ?? 0.0)) +
            ((float) ($pool['initial_token2_qty'] ?? 0.0) * (float) ($pool['price_2'] ?? 0.0));
        store_upsert_closed_pool($userId, [
            'pool_id' => $pool['pool_id'],
            'asset_1' => $pool['asset_1'],
            'asset_2' => $pool['asset_2'],
            'chain' => $pool['chain'],
            'wallet' => $pool['wallet'],
            'initial_total' => $pool['initial_total'],
            'total_now' => $pool['total_now'],
            'unclaimed' => $pool['unclaimed'],
            'claimed' => $pool['claimed'],
            'roi' => $pool['roi'],
            'apr' => $pool['apr'],
            'days_open' => $pool['days_open'],
            'hodl_at_close' => $hodlAtClose,
            'closed_at' => $closedAt,
        ]);

        store_replace_pool_order(
            $userId,
            array_values(array_filter(store_get_pool_order($userId), static fn($id) => $id !== $poolId))
        );

        store_insert_transaction($userId, [
            'wallet' => (string) $pool['wallet'],
            'chain' => (string) $pool['chain'],
            'transaction' => 'internal:close',
            'uniswap' => (string) $pool['uniswap'],
            'pool_id' => $poolId,
            'action' => 'remove',
            'date' => $closedAt,
            'asset_1' => (string) $pool['asset_1'],
            'asset_2' => (string) $pool['asset_2'],
            'deposit_1' => (float) ($pool['current_1'] ?? 0.0),
            'deposit_2' => (float) ($pool['current_2'] ?? 0.0),
            'deposit_1_usd' => (float) ($pool['atual_1'] ?? 0.0),
            'deposit_2_usd' => (float) ($pool['atual_2'] ?? 0.0),
            'total' => (float) ($pool['total_now'] ?? 0.0),
            'fees' => 0.0,
        ]);

        return ['Pool encerrada e movida para "Closed Pools".', 'ok'];
    }

    if ($action === 'restore_pool') {
        $poolId = normalize_pool_id(to_text('pool_id'));
        if ($poolId === '') {
            return ['Pool invalida.', 'error'];
        }

        $restored = store_delete_closed_pool($userId, $poolId);
        if (!$restored) {
            return ['Pool not found in closed pools.', 'error'];
        }

        $order = store_get_pool_order($userId);
        if (!in_array($poolId, $order, true)) {
            $order[] = $poolId;
            store_replace_pool_order($userId, $order);
        }

        return ['Pool restaurada para as pools ativas.', 'ok'];
    }

    if ($action === 'clear_all') {
        store_clear_all($userId);
        return ['Data cleared (transactions, pools, fees, and closed).', 'ok'];
    }

    return [$feedback, $feedbackType];
}
