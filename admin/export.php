<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/roster_query.php';
require_admin();

$type = $_GET['type'] ?? 'registrations';

function csv_out(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
    fputcsv($out, $header);
    foreach ($rows as $r) {
        // Prevent spreadsheet formula injection
        fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $r));
    }
    fclose($out);
    exit;
}

function contact_cols(array $r): array
{
    return [
        $r['email'], $r['phone'],
        trim(($r['parent_first_name'] ?? '') . ' ' . ($r['parent_last_name'] ?? '')), $r['parent_relationship'], $r['parent_email'], $r['parent_phone'],
    ];
}

$header = ['Last name', 'First name', 'Birthdate', 'Age', 'Position', 'Shoots', 'Level', 'League', 'Team', 'Season', 'Verification', 'Auto-check score',
           'Email', 'Phone', 'Parent/guardian', 'Relationship', 'Parent email', 'Parent phone', 'Profile URL'];

if ($type === 'roster') {
    $stmt = db()->prepare('SELECT name FROM camps WHERE id = ?');
    $stmt->execute([(int)($_GET['camp'] ?? 0)]);
    $name = $stmt->fetchColumn();
    if (!$name) exit('Camp not found');
    $rows = array_map(fn($r) => array_merge([
        $r['last_name'], $r['first_name'], $r['birthdate'], age_from_birthdate($r['birthdate']), $r['position'], $r['shoots'],
        $r['level_name'], $r['league_name'], $r['team_name'], $r['last_season'], $r['verification_status'], $r['auto_check_score'],
    ], contact_cols($r), [$r['profile_url'], $r['order_number'], $r['price'], $r['waiver_signed_at']]), roster_rows((int)$_GET['camp']));
    csv_out('roster-' . $name . '-' . date('Ymd') . '.csv', array_merge($header, ['Order', 'Price', 'Waiver signed']), $rows);
}

if ($type === 'players') {
    $rows = db()->query('SELECT u.*, p.*, l.name AS level_name,
                           (SELECT GROUP_CONCAT(c.name SEPARATOR "; ") FROM registrations r JOIN camps c ON c.id = r.camp_id WHERE r.user_id = u.id AND r.status = "active") AS camps
                         FROM users u LEFT JOIN players p ON p.user_id = u.id LEFT JOIN levels l ON l.id = p.level_id
                         WHERE u.role = "user" ORDER BY u.last_name, u.first_name')->fetchAll();
    csv_out('players-' . date('Ymd') . '.csv', array_merge($header, ['Camps']), array_map(fn($r) => array_merge([
        $r['last_name'], $r['first_name'], $r['birthdate'], age_from_birthdate($r['birthdate']), $r['position'], $r['shoots'],
        $r['level_name'], $r['league_name'], $r['team_name'], $r['last_season'], $r['verification_status'], $r['auto_check_score'],
    ], contact_cols($r), [$r['profile_url'], $r['camps']]), $rows));
}

// All registrations, one row per player per camp
$rows = db()->query('SELECT c.name AS camp, u.*, p.*, l.name AS level_name, o.order_number, r.price, r.created_at AS reg_at
                     FROM registrations r JOIN camps c ON c.id = r.camp_id JOIN users u ON u.id = r.user_id JOIN orders o ON o.id = r.order_id
                     LEFT JOIN players p ON p.user_id = u.id LEFT JOIN levels l ON l.id = p.level_id
                     WHERE r.status = "active" ORDER BY c.sort_order, c.name, u.last_name')->fetchAll();
csv_out('registrations-' . date('Ymd') . '.csv', array_merge(['Camp'], $header, ['Order', 'Price', 'Registered at']), array_map(fn($r) => array_merge([
    $r['camp'], $r['last_name'], $r['first_name'], $r['birthdate'], age_from_birthdate($r['birthdate']), $r['position'], $r['shoots'],
    $r['level_name'], $r['league_name'], $r['team_name'], $r['last_season'], $r['verification_status'], $r['auto_check_score'],
], contact_cols($r), [$r['profile_url'], $r['order_number'], $r['price'], $r['reg_at']]), $rows));
