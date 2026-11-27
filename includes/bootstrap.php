<?php
/**
 * Bootstrap: loads configuration, opens the database, starts the session
 * and pulls in the helper libraries. Every page includes this first.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.2.0');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    // Not installed yet: send the visitor to the installer.
    header('Location: ' . url('install/'));
    exit;
}

$GLOBALS['config'] = require $configFile;
$config = $GLOBALS['config'];

date_default_timezone_set($config['timezone'] ?? 'UTC');

if (!empty($config['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// ---- Schema upgrades (runs once after an update) ------------------------
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/mailer.php';

// ---- Session -------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('tkhd_reg');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---- Security headers ----------------------------------------------------
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ---- Database ------------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c   = $GLOBALS['config']['db'];
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'], (int)($c['port'] ?? 3306), $c['name'], $c['charset'] ?? 'utf8mb4'
        );
        try {
            $pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            // Keep MySQL NOW() in the same timezone as PHP.
            $pdo->exec("SET time_zone = '" . date('P') . "'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo '<h1>Database connection failed</h1><p>Check the database settings in config.php.</p>';
            if (!empty($GLOBALS['config']['debug'])) {
                echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            }
            exit;
        }
    }
    return $pdo;
}

if ((int)setting('schema_version', '1') < SCHEMA_VERSION) {
    run_migrations(db());
}
