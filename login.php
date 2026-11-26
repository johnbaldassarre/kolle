<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect(is_admin() ? 'admin/' : 'account.php');
}

$error = '';
if (is_post()) {
    csrf_check();
    $user = attempt_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
    if ($user) {
        $next = $_SESSION['after_login'] ?? '';
        unset($_SESSION['after_login']);
        // Only follow local paths.
        if ($next && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            header('Location: ' . $next);
            exit;
        }
        redirect($user['role'] === 'admin' ? 'admin/' : 'account.php');
    }
    $error = count($_SESSION['login_failures'] ?? []) >= 8
        ? 'Too many failed attempts. Please wait 10 minutes and try again.'
        : 'That email and password combination was not found.';
}

$pageTitle = 'Log In';
require __DIR__ . '/includes/header.php';
?>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <h2>Log in</h2>
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <label>Email<input type="email" name="email" value="<?= old('email') ?>" required autofocus autocomplete="email"></label>
  <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary btn-block">Log In</button>
  <p class="muted center">New player? <a href="<?= e(url('register.php')) ?>">Create an account</a><br>
  Forgot your password? Contact <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></p>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
