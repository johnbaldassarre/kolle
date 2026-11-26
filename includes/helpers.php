<?php
/**
 * General helpers: URLs, escaping, CSRF, flash messages, settings, money,
 * dates and the camp/registration queries shared by several pages.
 */
declare(strict_types=1);

// ---- URLs ----------------------------------------------------------------

/** Base URL of the application, always ending in "/". */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $configured = $GLOBALS['config']['base_url'] ?? '';
    if ($configured !== '') {
        return $base = rtrim($configured, '/') . '/';
    }
    // Work out the URL prefix from where the current script sits inside APP_ROOT.
    $root   = str_replace('\\', '/', realpath(APP_ROOT) ?: APP_ROOT);
    $file   = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $rel    = ($file !== '' && str_starts_with($file, $root)) ? ltrim(substr($file, strlen($root)), '/') : basename($script);
    $prefix = str_ends_with($script, $rel) ? substr($script, 0, -strlen($rel)) : dirname($script) . '/';
    return $base = rtrim($prefix, '/') . '/';
}

function url(string $path = ''): string
{
    return base_url() . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

// ---- Output --------------------------------------------------------------

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function old(string $key, $default = ''): string
{
    return e((string)($_POST[$key] ?? $default));
}

function selected($a, $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function checked(bool $cond): string
{
    return $cond ? ' checked' : '';
}

// ---- CSRF ----------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Your session expired or the form was submitted twice. Please go back, refresh the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// ---- Flash messages ------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---- Settings ------------------------------------------------------------

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
                $cache[$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (Throwable $e) {
            // settings table missing: fall back to defaults
        }
    }
    return $cache[$key] ?? $default;
}

function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function site_name(): string
{
    return setting('site_name', 'Ty Kolle Hockey Development');
}

// ---- Formatting ----------------------------------------------------------

function money($amount): string
{
    return setting('currency_symbol', '$') . number_format((float)$amount, 2);
}

function fmt_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '';
}

function date_range(?string $start, ?string $end): string
{
    if (!$start) {
        return 'Dates TBA';
    }
    if (!$end || $end === $start) {
        return fmt_date($start);
    }
    if (substr($start, 0, 7) === substr($end, 0, 7)) {
        return fmt_date($start, 'M j') . '–' . fmt_date($end, 'j, Y');
    }
    return fmt_date($start, 'M j') . ' – ' . fmt_date($end, 'M j, Y');
}

function age_from_birthdate(?string $birthdate, ?string $asOf = null): ?int
{
    if (!$birthdate) {
        return null;
    }
    try {
        $b = new DateTime($birthdate);
        $n = new DateTime($asOf ?? 'today');
        return (int)$b->diff($n)->y;
    } catch (Throwable $e) {
        return null;
    }
}

function valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

function birth_years_label(?int $min, ?int $max): string
{
    if ($min && $max) {
        return $min === $max ? "Born $min" : "Born {$min}–{$max}";
    }
    if ($min) {
        return "Born $min or later";
    }
    if ($max) {
        return "Born $max or earlier";
    }
    return 'All ages';
}

// ---- Camps & registrations -----------------------------------------------

/** Active camps with live registration counts. */
function camps_with_counts(bool $activeOnly = true): array
{
    $sql = 'SELECT c.*,
                   (SELECT COUNT(*) FROM registrations r WHERE r.camp_id = c.id AND r.status = "active") AS registered,
                   (SELECT COALESCE(SUM(r.price), 0) FROM registrations r WHERE r.camp_id = c.id AND r.status = "active") AS revenue
            FROM camps c'
         . ($activeOnly ? ' WHERE c.is_active = 1' : '')
         . ' ORDER BY c.sort_order, c.start_date, c.name';
    return db()->query($sql)->fetchAll();
}

/**
 * Why a player cannot register for a camp, or null if they can.
 * $alreadyIds = camp ids the player is already registered for.
 */
function camp_block_reason(array $camp, ?string $birthdate, array $alreadyIds = []): ?string
{
    if (in_array((int)$camp['id'], $alreadyIds, true)) {
        return 'Already registered';
    }
    $today = date('Y-m-d');
    if (!empty($camp['registration_opens']) && $today < $camp['registration_opens']) {
        return 'Opens ' . fmt_date($camp['registration_opens']);
    }
    if (!empty($camp['registration_closes']) && $today > $camp['registration_closes']) {
        return 'Registration closed';
    }
    if ($camp['capacity'] !== null && (int)$camp['registered'] >= (int)$camp['capacity']) {
        return 'Camp full';
    }
    if ($birthdate) {
        $year = (int)substr($birthdate, 0, 4);
        if (!empty($camp['min_birth_year']) && $year < (int)$camp['min_birth_year']) {
            return 'Outside age group';
        }
        if (!empty($camp['max_birth_year']) && $year > (int)$camp['max_birth_year']) {
            return 'Outside age group';
        }
    }
    return null;
}

function user_camp_ids(int $userId): array
{
    $stmt = db()->prepare('SELECT camp_id FROM registrations WHERE user_id = ? AND status = "active"');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function levels(bool $activeOnly = true): array
{
    return db()->query('SELECT * FROM levels' . ($activeOnly ? ' WHERE is_active = 1' : '')
        . ' ORDER BY sort_order, rank_value DESC')->fetchAll();
}

function verification_badge(?string $status): string
{
    $labels = [
        'pending'  => 'Pending',
        'verified' => 'Verified',
        'flagged'  => 'Flagged',
        'rejected' => 'Rejected',
    ];
    $status = $status ?: 'pending';
    return '<span class="badge badge-' . e($status) . '">' . e($labels[$status] ?? ucfirst($status)) . '</span>';
}

function profile_source_label(?string $source): string
{
    return [
        'eliteprospects' => 'Elite Prospects',
        'league'         => 'League profile',
        'other'          => 'Other link',
        'none'           => 'No profile',
    ][$source ?? ''] ?? '—';
}
