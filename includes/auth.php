<?php
/**
 * Authentication: login, logout, current user, access guards.
 */
declare(strict_types=1);

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch() ?: null;
    if (!$user) {
        unset($_SESSION['user_id']);
    }
    return $user;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
}

function logout_user(): void
{
    // Wipe the session and move to a fresh session ID (so a flash message can still be shown).
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Returns the user row on success, null on failure. */
function attempt_login(string $email, string $password): ?array
{
    // Simple throttle: max 8 failures per 10 minutes per session.
    $now = time();
    $_SESSION['login_failures'] = array_filter($_SESSION['login_failures'] ?? [], fn($t) => $t > $now - 600);
    if (count($_SESSION['login_failures']) >= 8) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        login_user((int)$user['id']);
        $_SESSION['login_failures'] = [];
        return $user;
    }
    $_SESSION['login_failures'][] = $now;
    return null;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        flash('info', 'Please log in to continue.');
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Administrator access required.');
    }
    return $u;
}

function password_problem(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null;
}
