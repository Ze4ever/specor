<?php

function current_user_id(): ?int
{
    $value = $_SESSION['user_id'] ?? null;
    if (!is_int($value) && !ctype_digit((string) $value)) {
        return null;
    }
    return (int) $value;
}

function current_username(): string
{
    return (string) ($_SESSION['username'] ?? '');
}

function is_logged_in(): bool
{
    return current_user_id() !== null;
}

function login_user(string $username, string $password): bool
{
    $user = find_user_by_username($username);
    if (!$user) {
        return false;
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = (string) $user['username'];
    auth_sync_user_to_other_db((string) $user['username'], (string) $user['password_hash']);
    return true;
}

function logout_user(): void
{
    unset($_SESSION['user_id'], $_SESSION['username']);
}

function find_user_by_username(string $username): ?array
{
    $pdo = app_pdo();
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([strtolower(trim($username))]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function find_user_by_id(int $userId): ?array
{
    $stmt = app_pdo()->prepare('SELECT id, username, password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function change_current_user_password(int $userId, string $currentPassword, string $newPassword): array
{
    $user = find_user_by_id($userId);
    if (!$user) {
        return [false, 'User not found.'];
    }

    if (!password_verify($currentPassword, (string) $user['password_hash'])) {
        return [false, 'Current password is invalid.'];
    }
    if (strlen($newPassword) < 8) {
        return [false, 'New password is too short (minimum 8).'];
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = app_pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$newHash, $userId]);
    auth_sync_user_to_other_db((string) ($user['username'] ?? ''), $newHash);
    return [true, 'Password updated successfully.'];
}

function create_user(string $username, string $password): array
{
    $username = strtolower(trim($username));
    if (!preg_match('/^[a-z0-9_.-]{3,32}$/', $username)) {
        return [false, 'Invalid username. Use 3-32 chars: a-z, 0-9, _, ., -'];
    }
    if (strlen($password) < 8) {
        return [false, 'Password too short (minimum 8).'];
    }
    if (find_user_by_username($username)) {
        return [false, 'Username already exists.'];
    }

    $pdo = app_pdo();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$username, $hash, db_now()]);
    auth_sync_user_to_other_db($username, $hash);
    return [true, 'Account created. Please log in.'];
}

function auth_sync_user_to_other_db(string $username, string $passwordHash): void
{
    $username = strtolower(trim($username));
    if ($username === '' || $passwordHash === '') {
        return;
    }

    $otherDb = auth_other_db_path();
    if ($otherDb === '') {
        return;
    }

    try {
        $pdo = new PDO('sqlite:' . $otherDb);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        db_ensure_schema($pdo);
    } catch (Throwable $e) {
        return;
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $existing = $stmt->fetchColumn();
    if (is_numeric($existing)) {
        return;
    }

    $ins = $pdo->prepare('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)');
    $ins->execute([$username, $passwordHash, db_now()]);
}

function auth_other_db_path(): string
{
    return '';
}

function handle_auth_actions(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return ['', 'ok'];
    }

    $action = (string) ($_POST['action'] ?? '');
    if (!in_array($action, ['login', 'register', 'logout'], true)) {
        return ['', 'ok'];
    }

    if (!verify_csrf()) {
        return ['Invalid request (CSRF).', 'error'];
    }

    if ($action === 'logout') {
        logout_user();
        return ['Session ended.', 'ok'];
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $password === '') {
        return ['Please fill in username and password.', 'error'];
    }

    if ($action === 'register') {
        [$ok, $msg] = create_user($username, $password);
        return [$msg, $ok ? 'ok' : 'error'];
    }

    if (!login_user($username, $password)) {
        return ['Invalid login.', 'error'];
    }
    return ['Login successful.', 'ok'];
}
