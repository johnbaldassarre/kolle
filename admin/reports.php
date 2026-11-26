<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$camps = camps_with_counts(false);

// Level-of-play mix per camp
$mix = db()->query('SELECT r.camp_id, COALESCE(l.category, "Unknown") AS category, COUNT(*) AS n
                    FROM registrations r LEFT JOIN players p ON p.user_id = r.user_id LEFT JOIN levels l ON l.id = p.level_id
                    WHERE r.status = "active" GROUP BY r.camp_id, category')->fetchAll();
$verif = db()->query('SELECT r.camp_id, p.verification_status AS s, COUNT(*) AS n
                      FROM registrations r JOIN players p ON p.user_id = r.user_id
                      WHERE r.status = "active" GROUP BY r.camp_id, p.verification_status')->fetchAll();
$cats = [];
$mixBy = [];
foreach ($mix as $m) { $cats[$m['category']] = true; $mixBy[$m['camp_id']][$m['category']] = (int)$m['n']; }
$verBy = [];
foreach ($verif as $v) { $verBy[$v['camp_id']][$v['s']] = (int)$v['n']; }
$cats = array_keys($cats);

$pageTitle = 'Reports & Rosters';
$adminPage = 'reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title">Reports &amp; Rosters</h1>
  <div class="head-actions">
    <a class="btn btn-primary" href="<?= e(url('admin/roster.php?camp=all')) ?>">Print all rosters</a>
    <a class="btn btn-ghost" href="<?= e(url('admin/export.php?type=registrations')) ?>">Export all registrations (CSV)</a>
  </div>
</div>

<div class="card">
  <h2>Camp rosters</h2>
  <table class="table">
    <thead><tr><th>Camp</th><th>Dates</th><th class="num">Players</th><th class="num">Revenue</th><th>Verification</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($camps as $c):
        $v = $verBy[$c['id']] ?? []; ?>
      <tr<?= $c['is_active'] ? '' : ' class="inactive"' ?>>
        <td><strong><?= e($c['name']) ?></strong></td>
        <td><?= e(date_range($c['start_date'], $c['end_date'])) ?></td>
        <td class="num"><?= (int)$c['registered'] ?><?= $c['capacity'] ? ' / ' . (int)$c['capacity'] : '' ?></td>
        <td class="num"><?= money($c['revenue']) ?></td>
        <td>
          <?php foreach (['verified', 'pending', 'flagged', 'rejected'] as $s): if (!empty($v[$s])): ?>
            <span class="badge badge-<?= $s ?>"><?= $v[$s] ?> <?= $s ?></span>
          <?php endif; endforeach; ?>
        </td>
        <td class="actions">
          <a href="<?= e(url('admin/roster.php?camp=' . $c['id'])) ?>">View / print</a>
          <a href="<?= e(url('admin/export.php?type=roster&camp=' . $c['id'])) ?>">CSV</a>
          <a href="<?= e(url('admin/players.php?camp=' . $c['id'])) ?>">Verify</a>
          <a href="<?= e(url('admin/email_compose.php?camp=' . $c['id'])) ?>">Email</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($cats): ?>
<div class="card">
  <h2>Level-of-play mix by camp</h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Camp</th><?php foreach ($cats as $cat): ?><th class="num"><?= e($cat) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($camps as $c): if (empty($mixBy[$c['id']])) continue; ?>
      <tr><td><?= e($c['name']) ?></td><?php foreach ($cats as $cat): ?><td class="num"><?= $mixBy[$c['id']][$cat] ?? '' ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
