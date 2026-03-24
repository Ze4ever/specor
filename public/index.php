<?php
declare(strict_types=1);

define('APP_CONTEXT', 'geral');
define('APP_DISPLAY_NAME', 'Global Dashboard');
define('APP_DB_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'app.sqlite');
define('APP_IMPORT_JSON_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'excel_import.json');

$importJsonPath = APP_IMPORT_JSON_PATH;
$nexoDbPath = APP_DB_PATH;

require dirname(__DIR__) . '/app/common/config/bootstrap.php';
require dirname(__DIR__) . '/app/common/lib/helpers.php';
require dirname(__DIR__) . '/app/common/lib/db.php';
require dirname(__DIR__) . '/app/common/lib/auth.php';
require dirname(__DIR__) . '/app/common/lib/overview.php';
require dirname(__DIR__) . '/app/uniswap/lib/store.php';
require dirname(__DIR__) . '/app/uniswap/lib/pools.php';
require dirname(__DIR__) . '/app/uniswap/lib/view_state.php';
require dirname(__DIR__) . '/app/nexo/lib/store.php';

try {
    [$authFeedback, $authFeedbackType] = handle_auth_actions();
} catch (Throwable $e) {
    http_response_code(500);
    $msg = h($e->getMonthsage());
    echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuration error</title></head><body style="font-family:Arial,sans-serif;padding:20px;"><h1>PHP configuration error</h1><p>' . $msg . '</p></body></html>';
    exit;
}

if (!is_logged_in()) {
    $feedback = $authFeedback;
    $feedbackType = $authFeedbackType;
    require dirname(__DIR__) . '/app/common/views/login.php';
    exit;
}

if ($authFeedback !== '') {
    $feedback = $authFeedback;
    $feedbackType = $authFeedbackType;
} else {
    $feedback = '';
    $feedbackType = 'ok';
}

$state = build_view_state($importJsonPath);
extract($state, EXTR_SKIP);

$overview = overview_build_dashboard($state, $nexoDbPath, current_username());
$nexoDashboard = overview_build_nexo_dashboard_full($nexoDbPath, current_username());

$activeTab = strtolower((string) ($_GET['tab'] ?? 'dashboard'));
if (!in_array($activeTab, ['dashboard', 'settings'], true)) {
    $activeTab = 'dashboard';
}

require dirname(__DIR__) . '/app/common/views/layout_geral.php';
