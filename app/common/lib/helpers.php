<?php
function app_context(): string
{
    if (defined('APP_CONTEXT') && is_string(APP_CONTEXT) && APP_CONTEXT !== '') {
        return strtolower(APP_CONTEXT);
    }
    return 'uniswap';
}

function app_display_name(): string
{
    if (defined('APP_DISPLAY_NAME') && is_string(APP_DISPLAY_NAME) && APP_DISPLAY_NAME !== '') {
        return APP_DISPLAY_NAME;
    }
    return app_context() === 'nexo' ? 'NEXO Wallet' : 'Uniswap Tracker';
}

function app_db_path(): string
{
    if (defined('APP_DB_PATH') && is_string(APP_DB_PATH) && APP_DB_PATH !== '') {
        return APP_DB_PATH;
    }
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'app.sqlite';
}

function app_import_json_path(): string
{
    if (defined('APP_IMPORT_JSON_PATH') && is_string(APP_IMPORT_JSON_PATH) && APP_IMPORT_JSON_PATH !== '') {
        return APP_IMPORT_JSON_PATH;
    }
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'excel_import.json';
}

function app_path_label(string $absolutePath): string
{
    $normalized = str_replace('\\', '/', $absolutePath);
    $root = str_replace('\\', '/', dirname(__DIR__, 2));
    if (strpos($normalized, $root . '/') === 0) {
        return substr($normalized, strlen($root) + 1);
    }
    return $normalized;
}

function to_float(string $key): float
{
    $raw = isset($_POST[$key]) ? (string) $_POST[$key] : '0';
    return (float) str_replace(',', '.', $raw);
}

function to_text(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '" />';
}

function verify_csrf(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    $known = (string) ($_SESSION['csrf_token'] ?? '');
    if ($token === '' || $known === '') {
        return false;
    }
    return hash_equals($known, $token);
}

function format_number(float $value, int $dec = 2): string
{
    return number_format($value, $dec, ',', '.');
}

function format_money(float $value): string
{
    return '$' . number_format($value, 2, ',', '.');
}

function calc_median(array $numbers): float
{
    $vals = array_values(array_filter($numbers, static fn($n) => is_numeric($n)));
    $count = count($vals);
    if ($count === 0) {
        return 0.0;
    }
    sort($vals);
    $mid = intdiv($count, 2);
    if ($count % 2 === 0) {
        return ((float) $vals[$mid - 1] + (float) $vals[$mid]) / 2;
    }
    return (float) $vals[$mid];
}

function normalize_datetime_input(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return db_now();
    }

    $raw = str_replace('T', ' ', $raw);
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $parsed = strtotime($raw);
    if ($parsed === false) {
        return db_now();
    }
    return date('Y-m-d H:i:s', $parsed);
}

function is_valid_http_url(string $value): bool
{
    $url = trim($value);
    if ($url === '') {
        return true;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function is_valid_tx_reference(string $value): bool
{
    $raw = trim($value);
    if ($raw === '') {
        return true;
    }
    if (is_valid_http_url($raw)) {
        return true;
    }
    return preg_match('/^(internal|manual):/i', $raw) === 1;
}

function is_valid_symbol(string $value): bool
{
    return (bool) preg_match('/^[A-Z0-9_.-]{2,20}$/', strtoupper(trim($value)));
}

function normalize_pool_id(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }

    if (preg_match('/^-?\d+\.0+$/', $raw)) {
        return preg_replace('/\.0+$/', '', $raw) ?? $raw;
    }
    return $raw;
}

function normalize_wallet_label(string $value): string
{
    return strtoupper(trim($value));
}

function normalize_chain_label(string $value): string
{
    return strtoupper(trim($value));
}

function format_percent_css(float $value): string
{
    $v = max(0.0, min(100.0, $value));
    return number_format($v, 2, '.', '');
}

function format_date_display(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }
    $datePairt = substr($raw, 0, 10);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $datePairt);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->format('d-m-Y');
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }
    return date('d-m-Y', $ts);
}

function format_datetime_display(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }
    return date('d-m-Y H:i', $ts);
}
