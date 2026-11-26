<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/verify.php';
require_admin();

// ---- Bulk auto-check -----------------------------------------------------
if (is_post() && ($_POST['action'] ?? '') === 'bulk_check') {
    csrf_check();
    @set_time_limit(180);
    $rows = db()->query('SELECT p.*, u.first_name, u.last_name, u.birthdate FROM players p JOIN users u ON u.id = p.user_id
                         WHERE p.verification_status = "pending" AND p.profile_url IS NOT NULL AND p.auto_checked_at IS NULL
                         ORDER BY p.updated_at LIMIT 10')->fetchAll();
    foreach ($rows as $r) {
        verify_store((int)$r['user_id'], verify_player($r));
    }
    flash('success', count($rows) ? 'Auto-checked ' . count($rows) . ' player(s). Run again to continue with the next batch.' : 'No unchecked pending players with a profile link.');
    redirect('admin/players.php?' . http_build_query(array_intersect_key($_GET, array_flip(['q', 'status', 'camp', 'level', 'registered']))));
}

// ---- Filters -------------------------------------------------------------
$q        = trim($_GET['q'] ?? '');
$status   = $_GET['status'] ?? '';
$campId   = (int)($_GET['camp'] ?? 0);
$levelId  = (int)($_GET['level'] ?? 0);
$registered = $_GET['registered'] ?? '';

$where  = ['u.role = "user"'];
$params = [];
if ($q !== '') {
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR p.parent_last_name LIKE ? OR p.team_name LIKE ? OR p.league_name LIKE ?
                 OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
    array_push($params, ...array_fill(0, 7, '%' . $q . '%'));
}
if (in_array($status, ['pending', 'verified', 'flagged', 'rejected'], true)) {
    $where[] = $status === 'flagged' ? 'p.verification_status IN ("flagged","rejected")' : 'p.verification_status = ?';
    if ($status !== 'flagged') $params[] = $status;
}
if ($campId) {
    $where[] = 'EXISTS (SELECT 1 FROM registrations r WHERE r.user_id = u.id AND r.camp_id = ? AND r.status = "active")';
    $params[] = $campId;
}
if ($levelId) {
    $where[] = 'p.level_id = ?';
    $params[] = $levelId;
}
if ($registered === '1') {
    $where[] = 'EXISTS (SELECT 1 FROM registrations r WHERE r.user_id = u.id AND r.status = "active")';
} elseif ($registered === '0') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM registrations r WHERE r.user_id = u.id AND r.status = "active")';
}

$sql = 'SELECT u.id, u.first_name, u.last_name, u.email, u.birthdate, u.created_at,
               p.is_adult, p.league_name, p.team_name, p.last_season, p.position, p.profile_url, p.profile_source,
               p.verification_status, p.auto_check_score, p.auto_checked_at, p.wizard_step, l.name AS level_name,
               (SELECT GROUP_CONCAT(c.name ORDER BY c.start_date SEPARATOR "||") FROM registrations r JOIN camps c ON c.id = r.camp_id
                 WHERE r.user_id = u.id AND r.status = "active") AS camp_names
        FROM users u LEFT JOIN players p ON p.user_id = u.id LEFT JOIN levels l ON l.id = p.level_id
        WHERE ' . implode(' AND ', $where) . ' ORDER BY u.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$players = $stmt->fetchAll();

$camps = camps_with_counts(false);
$pageTitle = 'Players & Verification';
$adminPage = 'players';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title">Players &amp; Verification</h1>
  <div class="head-actions">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_check">
      <button class="btn btn-ghost" title="Runs the automatic level check on up to 10 pending players that have not been checked yet">Auto-check pending (10)</button></form>
    <a class="btn btn-ghost" href="<?= e(url('admin/export.php?type=players&' . http_build_query($_GET))) ?>">Export CSV</a>
  </div>
</div>

<form method="get" class="card filters">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, email, team, league…">
  <select name="status">
    <option value="">Any verification status</option>
    <?php foreach (['pending' => 'Pending', 'verified' => 'Verified', 'flagged' => 'Flagged / rejected', 'rejected' => 'Rejected only'] as $k => $v): ?>
      <option value="<?= $k ?>"<?= selected($status, $k) ?>><?= $v ?></option>
    <?php endforeach; ?>
  </select>
  <select name="camp">
    <option value="">Any camp</option>
    <?php foreach ($camps as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($campId, $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
  </select>
  <select name="level">
    <option value="">Any level</option>
    <?php foreach (levels(false) as $l): ?><option value="<?= (int)$l['id'] ?>"<?= selected($levelId, $l['id']) ?>><?= e($l['name']) ?></option><?php endforeach; ?>
  </select>
  <select name="registered">
    <option value="">All accounts</option>
    <option value="1"<?= selected($registered, '1') ?>>Registered for a camp</option>
    <option value="0"<?= selected($registered, '0') ?>>No camps yet</option>
  </select>
  <button class="btn btn-primary">Filter</button>
  <?php if ($_GET): ?><a href="<?= e(url('admin/players.php')) ?>">Clear</a><?php endif; ?>
</form>

<div class="card">
  <p class="muted"><?= count($players) ?> player(s)</p>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Player</th><th>Age</th><th>Claimed level</th><th>Profile</th><th>Auto-check</th><th>Status</th><th>Camps</th></tr></thead>
    <tbody>
    <?php if (!$players): ?><tr><td colspan="7" class="muted">No players match.</td></tr><?php endif; ?>
    <?php foreach ($players as $p):
        $age = age_from_birthdate($p['birthdate']); ?>
      <tr>
        <td><a href="<?= e(url('admin/player.php?id=' . $p['id'])) ?>"><strong><?= e($p['last_name'] . ', ' . $p['first_name']) ?></strong></a><br><small class="muted"><?= e($p['email']) ?></small></td>
        <td><?= $age ?><?= $age !== null && $age < 18 ? ' <span class="tag">Minor</span>' : '' ?></td>
        <td><?= e($p['level_name'] ?? '—') ?><br><small class="muted"><?= e(trim(($p['league_name'] ?? '') . ' ' . ($p['team_name'] ? '· ' . $p['team_name'] : ''))) ?></small></td>
        <td><?php if ($p['profile_url']): ?><a href="<?= e($p['profile_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(profile_source_label($p['profile_source'])) ?> &#8599;</a><?php else: ?><?= e(profile_source_label($p['profile_source'])) ?><?php endif; ?></td>
        <td><?php if ($p['auto_checked_at']): ?><span class="score score-<?= $p['auto_check_score'] >= 70 ? 'hi' : ($p['auto_check_score'] >= 40 ? 'mid' : 'lo') ?>"><?= (int)$p['auto_check_score'] ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= verification_badge($p['verification_status']) ?></td>
        <td><?php if ($p['camp_names']): foreach (explode('||', $p['camp_names']) as $cn): ?><span class="tag"><?= e($cn) ?></span> <?php endforeach; else: ?><span class="muted"><?= (int)$p['wizard_step'] < 5 ? 'Incomplete (step ' . (int)$p['wizard_step'] . ')' : 'None' ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
