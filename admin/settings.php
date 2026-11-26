<?php
require __DIR__ . '/../includes/bootstrap.php';
$me = require_admin();

$keys = ['site_name', 'site_url', 'contact_email', 'main_site_url', 'logo_url', 'currency_symbol', 'currency_code', 'waiver_text'];

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'settings') {
        foreach ($keys as $k) {
            save_setting($k, trim((string)($_POST[$k] ?? '')));
        }
        save_setting('registration_open', !empty($_POST['registration_open']) ? '1' : '0');
        flash('success', 'Settings saved.');
    } elseif ($action === 'test_email') {
        $res = mail_deliver($me['email'], $me['first_name'], null, 'Test email from ' . site_name(),
            "This is a test email from your registration system.\n\nIf you can read this, email delivery is working.\n\n" . absolute_url('admin/'));
        db()->prepare('INSERT INTO email_log (user_id, to_email, subject, body, status, error, sent_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$me['id'], $me['email'], 'Test email from ' . site_name(), 'Test email', $res['status'], $res['error'], $me['id']]);
        flash($res['ok'] ? 'success' : 'error', $res['ok']
            ? ($res['status'] === 'logged' ? 'Log-only mode: the test was recorded in Emails > Sent log but not sent.' : 'Test email sent to ' . $me['email'] . '. Check your inbox (and spam).')
            : 'Test email failed: ' . $res['error']);
    } elseif ($action === 'add_admin') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        $stmt = db()->prepare('SELECT id, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email.');
        } elseif ($existing) {
            flash('error', 'That email already has an account. Use a different email for administrator access.');
        } elseif ($prob = password_problem($pw)) {
            flash('error', $prob);
        } else {
            db()->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash) VALUES ("admin", ?, ?, ?, ?)')
                ->execute([trim($_POST['first_name'] ?? '') ?: 'Admin', trim($_POST['last_name'] ?? ''), $email, password_hash($pw, PASSWORD_DEFAULT)]);
            flash('success', 'Administrator added.');
        }
    } elseif ($action === 'remove_admin') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$me['id']) {
            flash('error', 'You cannot remove your own administrator account.');
        } else {
            db()->prepare('DELETE FROM users WHERE id = ? AND role = "admin"')->execute([$id]);
            flash('success', 'Administrator removed.');
        }
    } elseif ($action === 'my_password') {
        $cur = (string)($_POST['current'] ?? '');
        $new = (string)($_POST['new'] ?? '');
        if (!password_verify($cur, $me['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif ($prob = password_problem($new)) {
            flash('error', $prob);
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            flash('success', 'Your password was changed.');
        }
    }
    redirect('admin/settings.php');
}

$admins = db()->query('SELECT id, first_name, last_name, email, last_login_at FROM users WHERE role = "admin" ORDER BY first_name')->fetchAll();
$pageTitle = 'Settings';
$adminPage = 'settings';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="page-title">Settings</h1>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <h2>General</h2>
  <div class="grid-2">
    <label>Organization name<input name="site_name" value="<?= e(setting('site_name')) ?>"></label>
    <label>Contact email<input type="email" name="contact_email" value="<?= e(setting('contact_email')) ?>"></label>
    <label>Site URL (for links in emails)<input type="url" name="site_url" value="<?= e(setting('site_url')) ?>" placeholder="https://register.example.com/"><span class="hint">The address of this registration system. Used for password reset and login links.</span></label>
    <label>Main website URL<input type="url" name="main_site_url" value="<?= e(setting('main_site_url')) ?>" placeholder="https://www.tykollehockeydevelopment.com/"></label>
    <label>Logo image URL<input name="logo_url" value="<?= e(setting('logo_url')) ?>" placeholder="Leave blank to use assets/img/logo.svg"><span class="hint">A wide logo works best (about 200×60 px).</span></label>
    <label>Currency symbol<input name="currency_symbol" value="<?= e(setting('currency_symbol', '$')) ?>" class="w-sm"></label>
    <label>Currency code<input name="currency_code" value="<?= e(setting('currency_code', 'CAD')) ?>" class="w-sm"></label>
    <label class="span-2">Waiver text shown at checkout<textarea name="waiver_text" rows="3"><?= e(setting('waiver_text')) ?></textarea></label>
  </div>
  <label class="checkbox"><input type="checkbox" name="registration_open" value="1"<?= checked(setting('registration_open', '1') === '1') ?>><span>Registration is open to the public</span></label>
  <div class="form-actions"><span class="muted">Demo mode is <?= !empty($GLOBALS['config']['demo_mode']) ? '<strong>on</strong>' : 'off' ?> (set in config.php).</span><button class="btn btn-primary">Save settings</button></div>
</form>

<?php $mc = mail_config(); ?>
<div class="card">
  <div class="card-head"><h2>Email delivery</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_email"><button class="btn btn-ghost btn-sm">Send test email to me</button></form></div>
  <dl class="details">
    <dt>Method</dt><dd><?= e(['mail' => 'PHP mail() (server default)', 'smtp' => 'SMTP', 'log' => 'Log only – emails are recorded, not sent'][$mc['transport']] ?? $mc['transport']) ?></dd>
    <?php if ($mc['transport'] === 'smtp'): ?><dt>SMTP server</dt><dd><?= e($mc['smtp_host'] . ':' . $mc['smtp_port'] . ' (' . ($mc['smtp_secure'] ?: 'no encryption') . ')') ?></dd><?php endif; ?>
    <dt>From</dt><dd><?= e(($mc['from_name'] ?: site_name()) . ' <' . ($mc['from_email'] ?: setting('contact_email')) . '>') ?></dd>
  </dl>
  <p class="muted"><small>Change these in the <code>'mail'</code> section of config.php. Email templates are under <a href="<?= e(url('admin/emails.php')) ?>">Emails</a>.</small></p>
</div>

<div class="grid-2 cards">
  <div class="card">
    <h2>Administrators</h2>
    <table class="table">
      <tbody>
      <?php foreach ($admins as $a): ?>
        <tr><td><?= e($a['first_name'] . ' ' . $a['last_name']) ?><br><small class="muted"><?= e($a['email']) ?></small></td>
          <td><small class="muted"><?= $a['last_login_at'] ? 'Last login ' . e(fmt_date($a['last_login_at'])) : 'Never logged in' ?></small></td>
          <td><?php if ((int)$a['id'] !== (int)$me['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove_admin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="link danger" data-confirm="Remove this administrator?">Remove</button></form><?php else: ?><span class="tag">You</span><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_admin">
      <h3>Add administrator</h3>
      <div class="grid-2 tight">
        <label>First name<input name="first_name"></label>
        <label>Last name<input name="last_name"></label>
        <label>Email<input type="email" name="email" required></label>
        <label>Password<input type="text" name="password" required autocomplete="off"></label>
      </div>
      <button class="btn btn-ghost">Add administrator</button>
    </form>
  </div>
  <div class="card">
    <h2>Change my password</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="my_password">
      <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
      <label>New password<input type="password" name="new" required autocomplete="new-password"></label>
      <button class="btn btn-ghost">Change password</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
