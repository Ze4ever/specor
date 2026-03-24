<?php
function nexo_handle_post_actions(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['', 'ok'];
    }

    $action = (string) ($_POST['action'] ?? '');
    $knownActions = [
        'change_password',
        'logout',
        'nexo_save_wallet_state',
        'nexo_add_flexible_term',
        'nexo_remove_flexible_term',
        'nexo_finalize_flexible_term',
        'nexo_delete_flexible_reward',
        'nexo_delete_fixed_reward',
        'nexo_delete_all_flexible_rewards',
        'nexo_delete_finalized_term',
        'nexo_add_fixed_term',
        'nexo_remove_fixed_term',
        'nexo_add_transaction',
        'nexo_delete_transaction',
        'nexo_market_save',
        'nexo_market_remove',
        'nexo_market_refresh',
        'nexo_market_bulk_update',
        'nexo_backfill_prices',
        'nexo_regen_flexible_logs',
        'nexo_refresh_flexible_logs',
    ];
    if (!in_array($action, $knownActions, true)) {
        return ['', 'ok'];
    }

    if ($action === 'logout') {
        return handle_auth_actions();
    }

    if (!verify_csrf()) {
        return ['Invalid request (CSRF).', 'error'];
    }

    $userId = current_user_id();
    if ($userId === null) {
        return ['Sessao invalida.', 'error'];
    }
    $logAction = static function (string $bucket, string $actionName, string $notes = '') use ($userId): void {
        nexo_add_transaction($userId, db_now(), $bucket, $actionName, 'N/A', 0.0, 'N/A', 0.0, 0, $notes);
    };

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

    if ($action === 'nexo_save_wallet_state') {
        $eurxEur = max(0.0, to_float('eurx_eur'));
        $nexoTokens = max(0.0, to_float('nexo_tokens'));
        $eurUsdRate = max(0.000001, to_float('eur_usd_rate'));
        $nexoUsdPrice = max(0.0, to_float('nexo_usd_price'));
        nexo_save_wallet_state($userId, $eurxEur, $nexoTokens, $eurUsdRate, $nexoUsdPrice);
        $logAction('wallet', 'adjust', 'nexo_save_wallet_state');
        return ['Wallet atualizada.', 'ok'];
    }

    if ($action === 'nexo_add_flexible_term') {
        $token = strtoupper(trim(to_text('token')));
        $coingeckoId = trim(to_text('coingecko_id'));
        $principal = max(0.0, to_float('principal'));
        $apyInput = to_text('apy');
        $apy = $apyInput === '' ? nexo_default_apy_for_token($token) : max(0.0, (float) str_replace(',', '.', $apyInput));
        $startedAt = normalize_datetime_input(to_text('started_at'));
        if (!is_valid_symbol($token)) {
            return ['Invalid token for flexible term.', 'error'];
        }
        if ($principal <= 0.0) {
            return ['Principal deve ser maior que zero.', 'error'];
        }
        $id = nexo_add_flexible_term($userId, $token, $principal, $apy, $startedAt, $coingeckoId);
        nexo_add_transaction($userId, $startedAt, 'flexible', 'add', $token, $principal, 'TOKEN', $apy, 0, 'flexible_term_id:' . $id);
        return ['Flexible term adicionado.', 'ok'];
    }

    if ($action === 'nexo_market_save') {
        $token = strtoupper(trim(to_text('token')));
        $coingeckoId = trim(to_text('coingecko_id'));
        $manualPrice = max(0.0, to_float('manual_price_usd'));
        $useManual = ((string) ($_POST['use_manual'] ?? '') === '1');
        if (!is_valid_symbol($token)) {
            return ['Invalid token.', 'error'];
        }
        if ($coingeckoId === '' && !$useManual) {
            return ['Define Coingecko ID ou ativa modo manual.', 'error'];
        }
        nexo_save_market_token($userId, $token, $coingeckoId, $manualPrice, $useManual);
        $logAction('wallet', 'adjust', 'nexo_market_save:' . $token);
        return ['Token de mercado guardado.', 'ok'];
    }

    if ($action === 'nexo_market_remove') {
        $token = strtoupper(trim(to_text('token')));
        if (!is_valid_symbol($token)) {
            return ['Invalid token.', 'error'];
        }
        $removed = nexo_deactivate_market_token($userId, $token);
        if (!$removed) {
            $removed = nexo_delete_market_token($userId, $token);
        }
        if (!$removed) {
            return ['Token not found in market cache.', 'error'];
        }
        $logAction('wallet', 'adjust', 'nexo_market_remove:' . $token);
        return ['Token removido do mercado.', 'ok'];
    }

    if ($action === 'nexo_market_refresh') {
        $updated = nexo_refresh_market_prices($userId);
        $logAction('wallet', 'adjust', 'nexo_market_refresh:' . $updated);
        return ['Prices updated for ' . $updated . ' token(s).', 'ok'];
    }

    if ($action === 'nexo_market_bulk_update') {
        $decoded = json_decode((string) ($_POST['market_prices_json'] ?? ''), true);
        if (!is_array($decoded)) {
            return ['Invalid market payload.', 'error'];
        }
        $updated = 0;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($decoded as $token => $priceRaw) {
            $price = is_numeric($priceRaw) ? (float) $priceRaw : 0.0;
            if ($price <= 0.0) {
                continue;
            }
            $market = nexo_get_market_token($userId, (string) $token);
            if (!is_array($market)) {
                continue;
            }
            if ((int) ($market['use_manual'] ?? 0) === 1) {
                continue;
            }
            $cgId = (string) ($market['coingecko_id'] ?? '');
            if ($cgId === '') {
                continue;
            }
            nexo_upsert_price_cache($userId, (string) $token, $cgId, $price, $now);
            $updated++;
        }
        $logAction('wallet', 'adjust', 'nexo_market_bulk_update:' . $updated);
        return ['Prices updated for ' . $updated . ' token(s).', 'ok'];
    }

    if ($action === 'nexo_backfill_prices') {
        $decoded = json_decode((string) ($_POST['price_history_json'] ?? ''), true);
        if (!is_array($decoded)) {
            return ['Invalid history payload.', 'error'];
        }
        $inserted = 0;
        foreach ($decoded as $token => $byDate) {
            if (!is_array($byDate)) {
                continue;
            }
            foreach ($byDate as $date => $priceRaw) {
                $price = is_numeric($priceRaw) ? (float) $priceRaw : 0.0;
                if ($price <= 0.0) {
                    continue;
                }
                nexo_record_price_history($userId, (string) $token, (string) $date, $price);
                $inserted++;
            }
        }
        $logAction('wallet', 'adjust', 'nexo_backfill_prices:' . $inserted);
        return ['Historico importado: ' . $inserted . ' pontos.', 'ok'];
    }

    if ($action === 'nexo_regen_flexible_logs') {
        $start = substr(trim((string) ($_POST['start_date'] ?? '')), 0, 10);
        $end = substr(trim((string) ($_POST['end_date'] ?? '')), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
            return ['Invalid range.', 'error'];
        }
        $deleted = nexo_delete_flexible_rewards_range($userId, $start, $end);
        $inserted = nexo_generate_flexible_rewards_range($userId, $start, $end);
        $logAction('flexible', 'adjust', 'nexo_regen_flexible_logs:' . $deleted . ':' . $inserted);
        return ['Logs regenerados. Removidos: ' . $deleted . ', Inseridos: ' . $inserted . '.', 'ok'];
    }

    if ($action === 'nexo_refresh_flexible_logs') {
        $decoded = json_decode((string) ($_POST['price_history_json'] ?? ''), true);
        if (is_array($decoded)) {
            foreach ($decoded as $token => $byDate) {
                if (!is_array($byDate)) {
                    continue;
                }
                foreach ($byDate as $date => $priceRaw) {
                    $price = is_numeric($priceRaw) ? (float) $priceRaw : 0.0;
                    if ($price <= 0.0) {
                        continue;
                    }
                    nexo_record_price_history($userId, (string) $token, (string) $date, $price);
                }
            }
        }

        $start = substr(trim((string) ($_POST['start_date'] ?? '')), 0, 10);
        $end = substr(trim((string) ($_POST['end_date'] ?? '')), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1) {
            $start = (string) (nexo_get_flexible_min_start_date($userId) ?? '');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
            $end = gmdate('Y-m-d');
        }
        if ($start === '' || $end === '') {
            return ['No valid dates to regenerate.', 'error'];
        }
        $deleted = nexo_delete_flexible_rewards_range($userId, $start, $end);
        $inserted = nexo_generate_flexible_rewards_range($userId, $start, $end);
        nexo_set_logs_paused($userId, false);
        $logAction('flexible', 'adjust', 'nexo_refresh_flexible_logs:' . $deleted . ':' . $inserted);
        return ['Logs atualizados. Removidos: ' . $deleted . ', Inseridos: ' . $inserted . '.', 'ok'];
    }

    if ($action === 'nexo_remove_flexible_term') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            return ['Invalid ID.', 'error'];
        }
        if (!nexo_deactivate_flexible_term($userId, $id)) {
            return ['Flexible term not found.', 'error'];
        }
        nexo_add_transaction($userId, db_now(), 'flexible', 'remove', 'N/A', 0.0, 'N/A', 0.0, 0, 'flexible_term_id:' . $id);
        return ['Flexible term removido.', 'ok'];
    }

    if ($action === 'nexo_finalize_flexible_term') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            return ['Invalid ID.', 'error'];
        }
        if (!nexo_finalize_flexible_term_full($userId, $id)) {
            return ['Flexible term not found.', 'error'];
        }
        nexo_add_transaction($userId, db_now(), 'flexible', 'finalize', 'N/A', 0.0, 'N/A', 0.0, 0, 'flexible_term_id:' . $id);
        return ['Flexible term finalizado.', 'ok'];
    }

    if ($action === 'nexo_delete_flexible_reward') {
        $rewardId = (int) ($_POST['reward_id'] ?? 0);
        if ($rewardId <= 0) {
            return ['Invalid log ID.', 'error'];
        }
        if (!nexo_delete_flexible_reward($userId, $rewardId)) {
            return ['Daily log not found.', 'error'];
        }
        $logAction('flexible', 'adjust', 'nexo_delete_flexible_reward:' . $rewardId);
        return ['Log diario removido.', 'ok'];
    }

    if ($action === 'nexo_delete_fixed_reward') {
        $rewardId = (int) ($_POST['reward_id'] ?? 0);
        if ($rewardId <= 0) {
            return ['Invalid log ID.', 'error'];
        }
        if (!nexo_delete_fixed_reward($userId, $rewardId)) {
            return ['Daily log not found.', 'error'];
        }
        $logAction('fixed', 'adjust', 'nexo_delete_fixed_reward:' . $rewardId);
        return ['Log diario removido.', 'ok'];
    }

    if ($action === 'nexo_delete_all_flexible_rewards') {
        $deleted = nexo_delete_all_flexible_rewards($userId);
        nexo_set_logs_paused($userId, true);
        $logAction('flexible', 'adjust', 'nexo_delete_all_flexible_rewards:' . $deleted);
        return ['Logs diarios removidos: ' . $deleted . '.', 'ok'];
    }

    if ($action === 'nexo_delete_finalized_term') {
        $id = (int) ($_POST['term_id'] ?? 0);
        if ($id <= 0) {
            return ['Invalid ID.', 'error'];
        }
        if (!nexo_delete_finalized_term($userId, $id)) {
            return ['Closed term not found.', 'error'];
        }
        nexo_add_transaction($userId, db_now(), 'flexible', 'remove', 'N/A', 0.0, 'N/A', 0.0, 0, 'finalized_term_id:' . $id);
        return ['Closed removido.', 'ok'];
    }

    if ($action === 'nexo_add_fixed_term') {
        $token = strtoupper(trim(to_text('token')));
        $principalTokens = max(0.0, to_float('principal_tokens'));
        $apy = max(0.0, to_float('apy'));
        $termMonths = max(1, (int) to_float('term_months'));
        $startedAt = normalize_datetime_input(to_text('started_at'));
        if (!is_valid_symbol($token)) {
            return ['Invalid token for fixed term.', 'error'];
        }
        if ($principalTokens <= 0.0) {
            return ['Principal do fixed term deve ser maior que zero.', 'error'];
        }
        $id = nexo_add_fixed_term($userId, $token, $principalTokens, $apy, $termMonths, $startedAt);
        nexo_add_transaction($userId, $startedAt, 'fixed', 'add', $token, $principalTokens, 'TOKEN', $apy, $termMonths, 'fixed_term_id:' . $id);
        return ['Fixed term adicionado.', 'ok'];
    }

    if ($action === 'nexo_remove_fixed_term') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            return ['Invalid ID.', 'error'];
        }
        if (!nexo_deactivate_fixed_term($userId, $id)) {
            return ['Fixed term not found.', 'error'];
        }
        nexo_add_transaction($userId, db_now(), 'fixed', 'remove', 'N/A', 0.0, 'N/A', 0.0, 0, 'fixed_term_id:' . $id);
        return ['Fixed term removido.', 'ok'];
    }

    if ($action === 'nexo_add_transaction') {
        $txDate = normalize_datetime_input(to_text('tx_date'));
        $bucket = strtolower(trim(to_text('bucket')));
        $actionName = strtolower(trim(to_text('tx_action')));
        $token = strtoupper(trim(to_text('token')));
        $amount = to_float('amount');
        $currency = strtoupper(trim(to_text('currency')));
        $apy = max(0.0, to_float('apy'));
        $termMonths = max(0, (int) to_float('term_months'));
        $notes = to_text('notes');

        if (!in_array($bucket, ['wallet', 'flexible', 'fixed'], true)) {
            return ['Invalid bucket.', 'error'];
        }
        if (!in_array($actionName, ['add', 'remove', 'adjust', 'finalize'], true)) {
            return ['Action invalida.', 'error'];
        }
        if ($token !== 'N/A' && !is_valid_symbol($token)) {
            return ['Invalid token.', 'error'];
        }
        if (!in_array($currency, ['EUR', 'USD', 'NEXO', 'TOKEN', 'N/A'], true)) {
            return ['Currency invalida.', 'error'];
        }

        nexo_add_transaction($userId, $txDate, $bucket, $actionName, $token, $amount, $currency, $apy, $termMonths, $notes);
        return ['Transacao NEXO adicionada.', 'ok'];
    }

    if ($action === 'nexo_delete_transaction') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            return ['Invalid ID.', 'error'];
        }
        if (!nexo_delete_transaction($userId, $id)) {
            return ['Transaction not found.', 'error'];
        }
        return ['Transacao removida.', 'ok'];
    }

    return ['', 'ok'];
}
