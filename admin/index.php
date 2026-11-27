<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$pdo = db();
$stats = [
    'players'  => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role = "user"')->fetchColumn(),
    'regs'     => (int)$pdo->query('SELECT COUNT(*) FROM registrations WHERE status = "active"')->fetchColumn(),
    'revenue'  => (float)$pdo->query('SELECT COALESCE(SUM(price),0) FROM registrations WHERE status = "active"')->fetchColumn(),
    'pending'  => (int)$pdo->query('SELECT COUNT(*) FROM players p WHERE p.verification_status = "pending" AND p.wizard_step >= 4
                                     AND EXISTS (SELECT 1 FROM registrations r WHERE r.user_id = p.user_id AND r.status = "active")')->fetchColumn(),
    'flagged'  => (int)$pdo->query('SELECT COUNT(*) FROM players WHERE verification_status IN ("flagged","rejected")')->fetchColumn(),
    'incomplete' => (int)$pdo->query('SELECT COUNT(*) FROM users u WHERE u.role = "user" AND NOT EXISTS (SELECT 1 FROM registrations r WHERE r.user_id = u.id)')->fetchColumn(),
];
$camps = camps_with_counts(false);
$recent = $pdo->query('SELECT o.order_number, o.total, o.created_at, u.first_name, u.last_name, u.id AS user_id,
                              (SELECT COUNT(*) FROM registrations r WHERE r.order_id = o.id) AS items
                       FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.created_at DESC LIMIT 8')->fetchAll();

$pageTitle = 'Admin Dashboard';
$adminPage = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="page-title">Dashboard</h1>
<div class="stat-grid">
  <div class="stat"><span class="stat-num"><?= $stats['players'] ?></span><span class="stat-label">Player accounts</span></div>
  <div class="stat"><span class="stat-num"><?= $stats['regs'] ?></span><span class="stat-label">Camp registrations</span></div>
  <div class="stat"><span class="stat-num"><?= money($stats['revenue']) ?></span><span class="stat-label">Registered revenue</span></div>
  <a class="stat stat-link" href="<?= e(url('admin/players.php?status=pending&registered=1')) ?>"><span class="stat-num"><?= $stats['pending'] ?></span><span class="stat-label">Awaiting verification</span></a>
  <a class="stat stat-link" href="<?= e(url('admin/players.php?status=flagged')) ?>"><span class="stat-num"><?= $stats['flagged'] ?></span><span class="stat-label">Flagged / rejected</span></a>
  <a class="stat stat-link" href="<?= e(url('admin/players.php?registered=0')) ?>"><span class="stat-num"><?= $stats['incomplete'] ?></span><span class="stat-label">Accounts with no camps</span></a>
</div>

<div class="grid-2 cards">
  <div class="card">
    <div class="card-head"><h2>Camp enrolment</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/camps.php')) ?>">Manage camps</a></div>
    <table class="table">
      <thead><tr><th>Camp</th><th>Dates</th><th class="num">Filled</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($camps as $c):
          $pct = $c['capacity'] ? min(100, round($c['registered'] / max(1, $c['capacity']) * 100)) : null; ?>
        <tr<?= $c['is_active'] ? '' : ' class="inactive"' ?>>
          <td><?= e($c['name']) ?><?= $c['is_active'] ? '' : ' <span class="tag">Hidden</span>' ?></td>
          <td><?= e(date_range($c['start_date'], $c['end_date'])) ?></td>
          <td class="num"><?= (int)$c['registered'] ?><?= $c['capacity'] ? ' / ' . (int)$c['capacity'] : '' ?>
            <?php if ($pct !== null): ?><div class="meter"><span style="width: <?= $pct ?>%"></span></div><?php endif; ?></td>
          <td><a href="<?= e(url('admin/roster.php?camp=' . $c['id'])) ?>">Roster</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card">
    <div class="card-head"><h2>Recent orders</h2></div>
    <?php if (!$recent): ?><p class="muted">No orders yet.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Order</th><th>Player</th><th class="num">Camps</th><th class="num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $o): ?>
        <tr><td><?= e($o['order_number']) ?><br><small class="muted"><?= e(fmt_date($o['created_at'], 'M j, g:i a')) ?></small></td>
          <td><a href="<?= e(url('admin/player.php?id=' . $o['user_id'])) ?>"><?= e($o['first_name'] . ' ' . $o['last_name']) ?></a></td>
          <td class="num"><?= (int)$o['items'] ?></td><td class="num"><?= money($o['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
