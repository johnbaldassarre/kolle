<?php
require __DIR__ . '/includes/bootstrap.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$reset = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = db()->prepare('SELECT pr.*, u.email, u.first_name FROM password_resets pr JOIN users u ON u.id = pr.user_id
                           WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()');
    $stmt->execute([hash('sha256', $token)]);
    $reset = $stmt->fetch() ?: null;
}

$error = '';
if ($reset && is_post()) {
    csrf_check();
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password_confirm'] ?? '');
    if ($prob = password_problem($pw)) {
        $error = $prob;
    } elseif ($pw !== $pw2) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $reset['user_id']]);
        // Use up this link and any other outstanding links for the account.
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$reset['user_id']]);
        $pdo->commit();
        logout_user();
        flash('success', 'Your password has been changed. Log in with your new password.');
        redirect('login.php');
    }
}

$pageTitle = 'Choose a New Password';
require __DIR__ . '/includes/header.php';
?>
<div class="card narrow">
  <h2>Choose a new password</h2>
  <?php if (!$reset): ?>
    <div class="alert alert-error">This reset link is invalid or has expired. Links work once and expire after 60 minutes.</div>
    <a class="btn btn-primary btn-block" href="<?= e(url('forgot_password.php')) ?>">Request a new link</a>
  <?php else: ?>
    <p class="muted">Account: <strong><?= e($reset['email']) ?></strong></p>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>New password<input type="password" name="password" required autofocus autocomplete="new-password" minlength="8"><span class="hint">At least 8 characters with a letter and a number.</span></label>
      <label>Confirm new password<input type="password" name="password_confirm" required autocomplete="new-password"></label>
      <button class="btn btn-primary btn-block">Save new password</button>
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
