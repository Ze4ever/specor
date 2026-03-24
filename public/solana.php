<?php
define('APP_CONTEXT', 'solana');
define('APP_DISPLAY_NAME', 'Solana Pools');
define('APP_DB_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'app.sqlite');
define('APP_IMPORT_JSON_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'excel_import.json');

$importJsonPath = APP_IMPORT_JSON_PATH;

require dirname(__DIR__) . '/app/common/config/bootstrap.php';
require dirname(__DIR__) . '/app/common/lib/helpers.php';
require dirname(__DIR__) . '/app/common/lib/db.php';
require dirname(__DIR__) . '/app/common/lib/auth.php';
require dirname(__DIR__) . '/app/solana/lib/store.php';
require dirname(__DIR__) . '/app/solana/lib/pools.php';
require dirname(__DIR__) . '/app/solana/lib/actions.php';
require dirname(__DIR__) . '/app/solana/lib/view_state.php';

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

[$feedback, $feedbackType] = handle_post_actions($importJsonPath);
if ($authFeedback !== '' && $feedback === '') {
    $feedback = $authFeedback;
    $feedbackType = $authFeedbackType;
}
$state = build_view_state($importJsonPath);
extract($state, EXTR_SKIP);

require dirname(__DIR__) . '/app/solana/views/layout.php';
