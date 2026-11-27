<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('account.php');
}

$sent = false;
if (is_post()) {
    csrf_check();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    // Per-session throttle so the form can't be used to flood an inbox.
    $now = time();
    $_SESSION['reset_requests'] = array_filter($_SESSION['reset_requests'] ?? [], fn($t) => $t > $now - 3600);

    if (filter_var($email, FILTER_VALIDATE_EMAIL) && count($_SESSION['reset_requests']) < 5) {
        $_SESSION['reset_requests'][] = $now;
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $userId = (int)$stmt->fetchColumn();

        if ($userId) {
            // Max 3 reset emails per account per hour.
            $recent = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
            $recent->execute([$userId]);
            if ((int)$recent->fetchColumn() < 3) {
                $token = bin2hex(random_bytes(32));
                db()->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?, ?, NOW() + INTERVAL 60 MINUTE, ?)')
                    ->execute([$userId, hash('sha256', $token), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45)]);
                $tpl = email_template('password_reset');
                if ($tpl) {
                    email_send_to_user($userId, $tpl, ['extra' => ['{reset_link}' => absolute_url('reset_password.php?token=' . $token)]]);
                }
            }
        }
    }
    // Always show the same message so the form doesn't reveal which emails have accounts.
    $sent = true;
}

$pageTitle = 'Forgot Password';
require __DIR__ . '/includes/header.php';
?>
<div class="card narrow">
  <h2>Reset your password</h2>
  <?php if ($sent): ?>
    <div class="alert alert-success">If an account exists for that email, we've sent a link to reset the password. It expires in 60 minutes.</div>
    <p class="muted">Didn't get it? Check your spam folder, or contact <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a>.</p>
    <a class="btn btn-ghost btn-block" href="<?= e(url('login.php')) ?>">Back to log in</a>
  <?php else: ?>
    <p class="muted">Enter the email you registered with and we'll send you a link to choose a new password.</p>
    <form method="post">
      <?= csrf_field() ?>
      <label>Email<input type="email" name="email" required autofocus autocomplete="email"></label>
      <button class="btn btn-primary btn-block">Send reset link</button>
    </form>
    <p class="center"><a href="<?= e(url('login.php')) ?>">Back to log in</a></p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
