<?php
/** Shared query for camp rosters (used by roster.php and export.php). */
function roster_rows(int $campId, string $sort = 'name'): array
{
    $order = [
        'name'     => 'u.last_name, u.first_name',
        'level'    => 'l.rank_value DESC, u.last_name',
        'position' => 'FIELD(p.position, "Goalie", "Defence", "Forward"), u.last_name',
        'age'      => 'u.birthdate DESC, u.last_name',
        'status'   => 'FIELD(p.verification_status, "rejected", "flagged", "pending", "verified"), u.last_name',
    ][$sort] ?? 'u.last_name, u.first_name';

    $stmt = db()->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.birthdate,
                                  p.is_adult, p.parent_first_name, p.parent_last_name, p.parent_relationship, p.parent_email, p.parent_phone,
                                  p.position, p.shoots, p.league_name, p.team_name, p.last_season, p.profile_url,
                                  p.verification_status, p.auto_check_score, l.name AS level_name,
                                  r.created_at AS registered_at, r.price, o.order_number
                           FROM registrations r
                           JOIN users u ON u.id = r.user_id
                           JOIN orders o ON o.id = r.order_id
                           LEFT JOIN players p ON p.user_id = u.id
                           LEFT JOIN levels l ON l.id = p.level_id
                           WHERE r.camp_id = ? AND r.status = 'active'
                           ORDER BY $order");
    $stmt->execute([$campId]);
    return $stmt->fetchAll();
}
