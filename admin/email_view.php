<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$stmt = db()->prepare('SELECT l.*, u.first_name, u.last_name FROM email_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.id = ?');
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$mail = $stmt->fetch();
if (!$mail) {
    redirect('admin/emails.php?tab=log');
}
$pageTitle = 'Email';
$adminPage = 'emails';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title">Email</h1>
  <a class="btn btn-ghost" href="<?= e(url('admin/emails.php?tab=log')) ?>">&larr; Sent log</a>
</div>
<div class="card narrow-wide">
  <dl class="details">
    <dt>To</dt><dd><?= e($mail['to_email']) ?><?= $mail['first_name'] ? ' (' . e($mail['first_name'] . ' ' . $mail['last_name']) . ')' : '' ?></dd>
    <?php if ($mail['cc_email']): ?><dt>Cc</dt><dd><?= e($mail['cc_email']) ?></dd><?php endif; ?>
    <dt>Subject</dt><dd><strong><?= e($mail['subject']) ?></strong></dd>
    <dt>Date</dt><dd><?= e(fmt_date($mail['created_at'], 'M j, Y g:i a')) ?></dd>
    <dt>Status</dt><dd><?= email_status_badge($mail['status']) ?><?= $mail['error'] ? ' <span class="field-error">' . e($mail['error']) . '</span>' : '' ?></dd>
  </dl>
  <iframe class="email-frame" sandbox title="Email preview" srcdoc="<?= e(email_html($mail['subject'], $mail['body'])) ?>"></iframe>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
