<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$tab = ($_GET['tab'] ?? '') === 'log' ? 'log' : 'templates';
$templates = db()->query('SELECT t.*, (SELECT COUNT(*) FROM email_log l WHERE l.template_id = t.id) AS times_sent
                          FROM email_templates t ORDER BY t.is_system DESC, t.name')->fetchAll();

$q = trim($_GET['q'] ?? '');
$params = [];
$where = '';
if ($q !== '') {
    $where = 'WHERE l.to_email LIKE ? OR l.subject LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?';
    $params = array_fill(0, 3, '%' . $q . '%');
}
$stmt = db()->prepare("SELECT l.id, l.to_email, l.cc_email, l.subject, l.status, l.error, l.created_at, l.user_id, u.first_name, u.last_name,
                              t.name AS template_name, a.first_name AS by_first
                       FROM email_log l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN email_templates t ON t.id = l.template_id
                       LEFT JOIN users a ON a.id = l.sent_by $where ORDER BY l.id DESC LIMIT 200");
$stmt->execute($params);
$log = $stmt->fetchAll();
$transport = mail_config()['transport'];

$pageTitle = 'Emails';
$adminPage = 'emails';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title">Emails</h1>
  <div class="head-actions">
    <a class="btn btn-ghost" href="<?= e(url('admin/email_compose.php')) ?>">Send an email</a>
    <a class="btn btn-primary" href="<?= e(url('admin/email_template.php')) ?>">+ New template</a>
  </div>
</div>

<?php if ($transport === 'log'): ?>
  <div class="alert alert-info"><strong>Log-only mode:</strong> emails are recorded below but not actually sent. Set <code>'transport'</code> to <code>'mail'</code> or <code>'smtp'</code> in config.php to deliver them.</div>
<?php endif; ?>

<div class="tabs">
  <a href="<?= e(url('admin/emails.php')) ?>" class="<?= $tab === 'templates' ? 'active' : '' ?>">Templates (<?= count($templates) ?>)</a>
  <a href="<?= e(url('admin/emails.php?tab=log')) ?>" class="<?= $tab === 'log' ? 'active' : '' ?>">Sent log</a>
</div>

<?php if ($tab === 'templates'): ?>
<div class="card">
  <table class="table">
    <thead><tr><th>Template</th><th>Subject</th><th>Type</th><th class="num">Sent</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($templates as $t): ?>
      <tr>
        <td><a href="<?= e(url('admin/email_template.php?id=' . $t['id'])) ?>"><strong><?= e($t['name']) ?></strong></a></td>
        <td><?= e($t['subject']) ?></td>
        <td><?php if ($t['is_system']): ?><span class="tag">Built-in</span>
            <?php if ($t['template_key'] === 'password_reset'): ?><br><small class="muted">Sent automatically on "Forgot password"</small>
            <?php else: ?><br><small class="muted">Offered when you verify / reject a player</small><?php endif; ?>
          <?php else: ?><span class="tag tag-hot">Custom</span><?php endif; ?></td>
        <td class="num"><?= (int)$t['times_sent'] ?></td>
        <td class="actions">
          <a href="<?= e(url('admin/email_template.php?id=' . $t['id'])) ?>">Edit</a>
          <?php if ($t['template_key'] !== 'password_reset'): ?><a href="<?= e(url('admin/email_compose.php?template=' . $t['id'])) ?>">Send</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<form method="get" class="card filters">
  <input type="hidden" name="tab" value="log">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search recipient, player or subject…">
  <button class="btn btn-primary">Search</button>
</form>
<div class="card">
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Template</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$log): ?><tr><td colspan="6" class="muted">No emails yet.</td></tr><?php endif; ?>
    <?php foreach ($log as $l): ?>
      <tr>
        <td><small><?= e(fmt_date($l['created_at'], 'M j, g:i a')) ?></small></td>
        <td><?php if ($l['user_id']): ?><a href="<?= e(url('admin/player.php?id=' . $l['user_id'])) ?>"><?= e($l['first_name'] . ' ' . $l['last_name']) ?></a><br><?php endif; ?>
          <small class="muted"><?= e($l['to_email']) ?><?= $l['cc_email'] ? ' (cc ' . e($l['cc_email']) . ')' : '' ?></small></td>
        <td><?= e($l['subject']) ?></td>
        <td><small><?= e($l['template_name'] ?? '—') ?><?= $l['by_first'] ? '<br>by ' . e($l['by_first']) : '' ?></small></td>
        <td><?= email_status_badge($l['status']) ?><?= $l['error'] ? '<br><small class="field-error">' . e($l['error']) . '</small>' : '' ?></td>
        <td><a href="<?= e(url('admin/email_view.php?id=' . $l['id'])) ?>">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
