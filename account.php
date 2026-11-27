<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$stmt = db()->prepare('SELECT p.*, l.name AS level_name FROM players p LEFT JOIN levels l ON l.id = p.level_id WHERE p.user_id = ?');
$stmt->execute([$user['id']]);
$player = $stmt->fetch() ?: ['wizard_step' => 2];

$stmt = db()->prepare('SELECT r.*, c.name, c.start_date, c.end_date, c.schedule, c.location, o.order_number
                       FROM registrations r JOIN camps c ON c.id = r.camp_id JOIN orders o ON o.id = r.order_id
                       WHERE r.user_id = ? AND r.status = "active" ORDER BY c.start_date');
$stmt->execute([$user['id']]);
$regs = $stmt->fetchAll();

$complete  = (int)$player['wizard_step'] >= 4;
$pageTitle = 'My Account';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title">Welcome, <?= e($user['first_name']) ?></h1>

<?php if (($player['verification_status'] ?? '') === 'rejected'): ?>
  <div class="alert alert-error">Your registration was not accepted after review<?= !empty($player['verification_note']) ? ': ' . e($player['verification_note']) : '.' ?> Please contact <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a> with any questions.</div>
<?php elseif (!$complete): ?>
  <div class="alert alert-info">Your registration isn't finished yet. <a href="<?= e(url('register.php')) ?>">Continue where you left off &rarr;</a></div>
<?php endif; ?>

<div class="grid-2 cards">
  <div class="card">
    <div class="card-head"><h2>My camps</h2>
      <?php if ($complete && ($player['verification_status'] ?? '') !== 'rejected'): ?><a class="btn btn-primary btn-sm" href="<?= e(url('register.php?step=5')) ?>">+ Add camps</a><?php endif; ?>
    </div>
    <?php if (!$regs): ?>
      <p class="muted">You aren't registered for any camps yet.</p>
    <?php else: ?>
      <ul class="reg-list">
        <?php foreach ($regs as $r): ?>
          <li><strong><?= e($r['name']) ?></strong><br>
            <span class="muted"><?= e(date_range($r['start_date'], $r['end_date'])) ?> &middot; <?= e($r['schedule']) ?><br><?= e($r['location']) ?> &middot; Order <?= e($r['order_number']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-head"><h2>Player profile</h2>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('register.php?step=2')) ?>">Edit</a>
    </div>
    <dl class="details">
      <dt>Player</dt><dd><?= e($user['first_name'] . ' ' . $user['last_name']) ?></dd>
      <dt>Birthdate</dt><dd><?= e(fmt_date($user['birthdate'])) ?> (age <?= (int)age_from_birthdate($user['birthdate']) ?>)</dd>
      <dt>Email</dt><dd><?= e($user['email']) ?></dd>
      <?php if (!empty($player['parent_first_name'])): ?>
        <dt>Parent/guardian</dt><dd><?= e($player['parent_first_name'] . ' ' . $player['parent_last_name']) ?><br><?= e($player['parent_email']) ?> &middot; <?= e($player['parent_phone']) ?></dd>
      <?php endif; ?>
      <dt>Level of play</dt><dd><?= e($player['level_name'] ?? '—') ?><?= !empty($player['league_name']) ? '<br>' . e($player['league_name']) . ($player['team_name'] ? ' – ' . e($player['team_name']) : '') . ' (' . e($player['last_season']) . ')' : '' ?></dd>
      <dt>Profile link</dt><dd><?= !empty($player['profile_url']) ? '<a href="' . e($player['profile_url']) . '" target="_blank" rel="noopener noreferrer">' . e(profile_source_label($player['profile_source'])) . '</a>' : e(profile_source_label($player['profile_source'] ?? null)) ?></dd>
      <dt>Level verification</dt><dd><?= verification_badge($player['verification_status'] ?? 'pending') ?></dd>
      <?php $w = latest_waiver((int)$user['id']); ?>
      <dt>Waiver</dt><dd><?php if ($w): ?>Signed by <?= e($w['signer_name']) ?> on <?= e(fmt_date($w['signed_at'])) ?>
        <?php if ((int)$w['waiver_version'] < waiver_version()): ?><br><a href="<?= e(url('register.php?step=4')) ?>">Updated waiver – please sign</a><?php endif; ?>
        <?php else: ?><a href="<?= e(url('register.php?step=4')) ?>">Not signed yet</a><?php endif; ?></dd>
    </dl>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
