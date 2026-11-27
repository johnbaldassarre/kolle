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
    if (!isset($GLOBALS['__settings'])) {
        $GLOBALS['__settings'] = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
                $GLOBALS['__settings'][$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (Throwable $e) {
            // settings table missing: fall back to defaults
        }
    }
    return $GLOBALS['__settings'][$key] ?? $default;
}

function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
    $GLOBALS['__settings'][$key] = $value;
}

/**
 * Absolute URL for links in emails. Uses the saved Site URL (Admin > Settings)
 * rather than the request's Host header, so reset links cannot be spoofed.
 */
function absolute_url(string $path = ''): string
{
    $base = $GLOBALS['config']['base_url'] ?? '';
    if ($base === '' || !preg_match('#^https?://#', $base)) {
        $base = setting('site_url', '');
    }
    if ($base === '') {
        $base = request_origin() . base_url();
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function request_origin(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host;
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
                   (SELECT COALESCE(SUM(r.price), 0) FROM registrations r WHERE r.camp_id = c.id AND r.status = "active") AS revenue,
                   ml.name AS min_level_name, ml.rank_value AS min_level_rank
            FROM camps c LEFT JOIN levels ml ON ml.id = c.min_level_id'
         . ($activeOnly ? ' WHERE c.is_active = 1' : '')
         . ' ORDER BY c.sort_order, c.start_date, c.name';
    return db()->query($sql)->fetchAll();
}

/**
 * Why a player cannot register for a camp, or null if they can.
 * $alreadyIds  = camp ids the player is already registered for.
 * $playerRank  = rank of the player's level of play (see Levels of Play); camps
 *                with a minimum level only accept players at that rank or higher.
 * The camp row must include min_level_rank / min_level_name (camps_with_counts() does).
 */
function camp_block_reason(array $camp, ?string $birthdate, array $alreadyIds = [], ?int $playerRank = null): ?string
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
    if (isset($camp['min_level_rank']) && $camp['min_level_rank'] !== null && ($playerRank === null || $playerRank < (int)$camp['min_level_rank'])) {
        return 'Requires ' . short_level_name($camp['min_level_name']) . ' or higher';
    }
    return null;
}

/** Rank of the player's (claimed or admin-confirmed) level of play, or null. */
function player_level_rank(int $userId): ?int
{
    $stmt = db()->prepare('SELECT l.rank_value FROM players p JOIN levels l ON l.id = p.level_id WHERE p.user_id = ?');
    $stmt->execute([$userId]);
    $r = $stmt->fetchColumn();
    return $r === false ? null : (int)$r;
}

function camp_level_label(array $camp): string
{
    return !empty($camp['min_level_name']) ? short_level_name($camp['min_level_name']) . ' and above' : '';
}

/** "Junior B / Tier III (GOJHL, KIJHL, ...)" -> "Junior B / Tier III" for compact labels. */
function short_level_name(string $name): string
{
    return trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $name)) ?: $name;
}

// ---- Level changes & camp moves ------------------------------------------

/** Active registrations whose camp requires a higher level than $rank. */
function level_conflicts(int $userId, ?int $rank): array
{
    $stmt = db()->prepare('SELECT r.id, r.camp_id, r.price, c.name, c.start_date, c.end_date, ml.name AS min_level_name, ml.rank_value AS min_level_rank
                           FROM registrations r JOIN camps c ON c.id = r.camp_id JOIN levels ml ON ml.id = c.min_level_id
                           WHERE r.user_id = ? AND r.status = "active" AND ml.rank_value > ? ORDER BY c.start_date');
    $stmt->execute([$userId, $rank ?? -1]);
    return $stmt->fetchAll();
}

/** Camps a player could be moved into (eligible at $rank, not full, not already registered). */
function move_targets(int $userId, ?string $birthdate, ?int $rank, bool $activeOnly = true): array
{
    $already = user_camp_ids($userId);
    return array_values(array_filter(camps_with_counts($activeOnly),
        fn($c) => camp_block_reason($c, $birthdate, $already, $rank) === null));
}

/**
 * Move a registration to another camp. The old registration is kept as cancelled
 * (for history) and a new one is created on the same order at the new camp's price.
 * Returns ['diff' => price difference (positive = player owes more, negative = refund due), 'camp' => new camp name].
 * Throws RuntimeException if the move isn't allowed.
 */
function move_registration(int $regId, int $userId, int $newCampId, ?int $rank): array
{
    $pdo = db();
    $own = $pdo->inTransaction() === false;
    if ($own) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT r.*, c.name AS camp_name FROM registrations r JOIN camps c ON c.id = r.camp_id
                               WHERE r.id = ? AND r.user_id = ? AND r.status = "active" FOR UPDATE');
        $stmt->execute([$regId, $userId]);
        $reg = $stmt->fetch();
        if (!$reg) {
            throw new RuntimeException('Registration not found or no longer active.');
        }
        $stmt = $pdo->prepare('SELECT c.*, (SELECT COUNT(*) FROM registrations x WHERE x.camp_id = c.id AND x.status = "active") AS registered,
                                      ml.name AS min_level_name, ml.rank_value AS min_level_rank
                               FROM camps c LEFT JOIN levels ml ON ml.id = c.min_level_id WHERE c.id = ? FOR UPDATE');
        $stmt->execute([$newCampId]);
        $camp = $stmt->fetch();
        if (!$camp) {
            throw new RuntimeException('Camp not found.');
        }
        $u = $pdo->prepare('SELECT birthdate FROM users WHERE id = ?');
        $u->execute([$userId]);
        if ($why = camp_block_reason($camp, $u->fetchColumn() ?: null, user_camp_ids($userId), $rank)) {
            throw new RuntimeException($camp['name'] . ': ' . $why . '.');
        }
        $pdo->prepare('UPDATE registrations SET status = "cancelled", note = ? WHERE id = ?')
            ->execute([mb_substr('Moved to ' . $camp['name'] . ' on ' . date('M j, Y'), 0, 255), $regId]);
        $pdo->prepare('INSERT INTO registrations (order_id, user_id, camp_id, price, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$reg['order_id'], $userId, $newCampId, $camp['price'], mb_substr('Moved from ' . $reg['camp_name'] . ' (paid ' . money($reg['price']) . ')', 0, 255)]);
        if ($own) $pdo->commit();
        return ['diff' => round((float)$camp['price'] - (float)$reg['price'], 2), 'camp' => $camp['name']];
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
}

function price_difference_note(float $diff): string
{
    if (abs($diff) < 0.005) return 'Same price.';
    return $diff > 0 ? 'Player owes ' . money($diff) . ' more.' : 'Refund due: ' . money(-$diff) . '.';
}

// ---- Waivers -------------------------------------------------------------

function waiver_version(): int
{
    return max(1, (int)setting('waiver_version', '1'));
}

/** Most recent waiver the user signed, or null. */
function latest_waiver(int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM waivers WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/** True if the user has signed the current version of the waiver. */
function has_current_waiver(int $userId): bool
{
    $w = latest_waiver($userId);
    return $w !== null && (int)$w['waiver_version'] >= waiver_version();
}

/** Lowercase, accent-free, single-spaced name for signature comparisons. */
function normalize_name(string $name): string
{
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
        if ($t !== false) {
            $name = $t;
        }
    } elseif (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        if ($t !== false) {
            $name = $t;
        }
    }
    $name = strtolower(preg_replace('/[^A-Za-z\s\-\']/', '', $name));
    return trim(preg_replace('/[\s\-]+/', ' ', str_replace("'", '', $name)));
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
