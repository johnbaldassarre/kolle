<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/roster_query.php';
require_admin();

$campParam = $_GET['camp'] ?? '';
$sort      = $_GET['sort'] ?? 'name';
$contacts  = ($_GET['contacts'] ?? '1') === '1';

if ($campParam === 'all') {
    $camps = array_values(array_filter(camps_with_counts(false), fn($c) => (int)$c['registered'] > 0));
} else {
    $stmt = db()->prepare('SELECT * FROM camps WHERE id = ?');
    $stmt->execute([(int)$campParam]);
    $camps = $stmt->fetchAll();
}
if (!$camps && $campParam !== 'all') {
    flash('error', 'Camp not found.');
    redirect('admin/reports.php');
}

$pageTitle = 'Roster';
$adminPage = 'reports';
require __DIR__ . '/../includes/header.php';
$qs = fn(array $over) => e(url('admin/roster.php?' . http_build_query(array_merge(['camp' => $campParam, 'sort' => $sort, 'contacts' => $contacts ? '1' : '0'], $over))));
?>
<div class="page-head no-print">
  <h1 class="page-title">Roster<?= $campParam === 'all' ? 's – all camps' : '' ?></h1>
  <div class="head-actions">
    <span class="muted">Sort:</span>
    <?php foreach (['name' => 'Name', 'level' => 'Level', 'position' => 'Position', 'age' => 'Age', 'status' => 'Verification'] as $k => $v): ?>
      <a href="<?= $qs(['sort' => $k]) ?>" class="pill<?= $sort === $k ? ' active' : '' ?>"><?= $v ?></a>
    <?php endforeach; ?>
    <a href="<?= $qs(['contacts' => $contacts ? '0' : '1']) ?>" class="pill"><?= $contacts ? 'Hide' : 'Show' ?> contacts</a>
    <button class="btn btn-primary" onclick="window.print()">Print</button>
    <?php if ($campParam !== 'all'): ?><a class="btn btn-ghost" href="<?= e(url('admin/export.php?type=roster&camp=' . (int)$campParam)) ?>">CSV</a><?php endif; ?>
    <?php if ($campParam !== 'all'): ?><a class="btn btn-ghost" href="<?= e(url('admin/email_compose.php?camp=' . (int)$campParam)) ?>">Email camp</a><?php endif; ?>
  </div>
</div>

<?php if (!$camps): ?><div class="card"><p class="muted">No camps have registrations yet.</p></div><?php endif; ?>

<?php foreach ($camps as $c):
    $rows = roster_rows((int)$c['id'], $sort); ?>
  <section class="roster card">
    <header class="roster-head">
      <div>
        <div class="print-only roster-brand"><?= e(site_name()) ?></div>
        <h2><?= e($c['name']) ?></h2>
        <div class="muted"><?= e(date_range($c['start_date'], $c['end_date'])) ?><?= $c['schedule'] ? ' · ' . e($c['schedule']) : '' ?><?= $c['location'] ? ' · ' . e($c['location']) : '' ?></div>
      </div>
      <div class="roster-count"><strong><?= count($rows) ?></strong><?= $c['capacity'] ? ' / ' . (int)$c['capacity'] : '' ?> players<br><small class="muted">Printed <?= date('M j, Y') ?></small></div>
    </header>
    <?php if (!$rows): ?><p class="muted">No players registered.</p><?php else: ?>
    <table class="table roster-table">
      <thead><tr>
        <th class="num">#</th><th>Player</th><th>Born</th><th>Pos</th><th>Level / team</th><th>Verified</th>
        <?php if ($contacts): ?><th>Contact</th><?php endif; ?>
        <th class="print-only att">Att.</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="num"><?= $i + 1 ?></td>
          <td><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong></td>
          <td><?= e(fmt_date($r['birthdate'], 'Y')) ?> <small class="muted">(<?= (int)age_from_birthdate($r['birthdate']) ?>)</small></td>
          <td><?= e(substr((string)$r['position'], 0, 1)) ?><?= $r['shoots'] ? '/' . e($r['shoots']) : '' ?></td>
          <td><?= e($r['level_name'] ?? '—') ?><br><small class="muted"><?= e(trim($r['league_name'] . ($r['team_name'] ? ' · ' . $r['team_name'] : ''))) ?></small></td>
          <td><?= verification_badge($r['verification_status']) ?></td>
          <?php if ($contacts): ?>
            <td><small><?php if (!$r['is_adult'] && $r['parent_first_name']): ?>
              <?= e($r['parent_first_name'] . ' ' . $r['parent_last_name']) ?> (<?= e($r['parent_relationship']) ?>)<br><?= e($r['parent_phone']) ?> · <?= e($r['parent_email']) ?>
            <?php else: ?><?= e($r['phone'] ?: '') ?><?= $r['phone'] ? ' · ' : '' ?><?= e($r['email']) ?><?php endif; ?></small></td>
          <?php endif; ?>
          <td class="print-only att"><span class="box"></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
