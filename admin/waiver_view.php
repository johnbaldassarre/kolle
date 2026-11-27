<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$stmt = db()->prepare('SELECT w.*, u.first_name, u.last_name, u.birthdate FROM waivers w JOIN users u ON u.id = w.user_id WHERE w.id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$w = $stmt->fetch();
if (!$w) {
    redirect('admin/players.php');
}
$pageTitle = 'Signed Waiver';
$adminPage = 'players';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head no-print">
  <h1 class="page-title">Signed waiver</h1>
  <div class="head-actions">
    <button class="btn btn-primary" onclick="window.print()">Print</button>
    <a class="btn btn-ghost" href="<?= e(url('admin/player.php?id=' . $w['user_id'])) ?>">&larr; Player</a>
  </div>
</div>
<div class="card narrow-wide">
  <div class="print-only roster-brand"><?= e(site_name()) ?></div>
  <h2>Waiver &amp; release – <?= e($w['first_name'] . ' ' . $w['last_name']) ?></h2>
  <div class="waiver-text printable"><?= nl2br(e($w['waiver_text'])) ?></div>
  <dl class="details">
    <dt>Participant</dt><dd><?= e($w['first_name'] . ' ' . $w['last_name']) ?> (born <?= e(fmt_date($w['birthdate'])) ?>)</dd>
    <dt>Electronic signature</dt><dd><span class="signature-display"><?= e($w['signer_name']) ?></span></dd>
    <dt>Signed as</dt><dd><?= e($w['signer_relationship']) ?></dd>
    <dt>Date &amp; time</dt><dd><?= e(fmt_date($w['signed_at'], 'F j, Y g:i:s a T')) ?></dd>
    <dt>Waiver version</dt><dd><?= (int)$w['waiver_version'] ?></dd>
    <dt>IP address</dt><dd><?= e($w['ip'] ?: '—') ?></dd>
    <dt>Browser</dt><dd><small><?= e($w['user_agent'] ?: '—') ?></small></dd>
  </dl>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
