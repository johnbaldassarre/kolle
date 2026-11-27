<?php
/**
 * Page header. Set before including:
 *   $pageTitle  (string)  title shown in the browser tab
 *   $adminPage  (string)  active admin tab key, enables the admin sub-navigation
 *   $hero       (array)   optional ['title' => ..., 'subtitle' => ...] banner
 */
$pageTitle = $pageTitle ?? '';
$user      = current_user();
$logo      = setting('logo_url', '');
$script    = basename($_SERVER['SCRIPT_NAME'] ?? '');
$inAdmin   = isset($adminPage);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($pageTitle ? $pageTitle . ' | ' : '') . site_name()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=<?= APP_VERSION ?>">
</head>
<body class="<?= $inAdmin ? 'is-admin' : 'is-public' ?>">
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= e(url($inAdmin ? 'admin/' : '')) ?>">
      <?php if ($logo): ?>
        <img src="<?= e($logo) ?>" alt="<?= e(site_name()) ?>" class="brand-logo">
      <?php else: ?>
        <img src="<?= e(url('assets/img/logo.svg')) ?>" alt="" class="brand-logo">
        <span class="brand-text"><?= e(site_name()) ?></span>
      <?php endif; ?>
    </a>
    <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false" data-nav-toggle>
      <span></span><span></span><span></span>
    </button>
    <nav class="main-nav" data-nav>
      <?php if ($inAdmin): ?>
        <a href="<?= e(url('')) ?>">View Site</a>
      <?php else: ?>
        <a href="<?= e(url('')) ?>" class="<?= $script === 'index.php' ? 'active' : '' ?>">Camps</a>
      <?php endif; ?>
      <?php if ($user): ?>
        <?php if (!$inAdmin): ?>
          <a href="<?= e(url('account.php')) ?>" class="<?= $script === 'account.php' ? 'active' : '' ?>">My Account</a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin' && !$inAdmin): ?>
          <a href="<?= e(url('admin/')) ?>">Admin</a>
        <?php endif; ?>
        <a href="<?= e(url('logout.php')) ?>">Log Out</a>
      <?php else: ?>
        <a href="<?= e(url('login.php')) ?>" class="<?= $script === 'login.php' ? 'active' : '' ?>">Log In</a>
        <a href="<?= e(url('register.php')) ?>" class="btn btn-primary btn-sm">Register Now</a>
      <?php endif; ?>
    </nav>
  </div>
  <?php if ($inAdmin): ?>
  <div class="admin-nav">
    <div class="container">
      <?php
      $tabs = [
          'dashboard' => ['Dashboard', 'admin/'],
          'camps'     => ['Camps & Pricing', 'admin/camps.php'],
          'players'   => ['Players & Verification', 'admin/players.php'],
          'reports'   => ['Reports & Rosters', 'admin/reports.php'],
          'emails'    => ['Emails', 'admin/emails.php'],
          'levels'    => ['Levels of Play', 'admin/levels.php'],
          'settings'  => ['Settings', 'admin/settings.php'],
      ];
      foreach ($tabs as $key => [$label, $href]): ?>
        <a href="<?= e(url($href)) ?>" class="<?= $adminPage === $key ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</header>

<?php if (!empty($hero)): ?>
<section class="hero">
  <div class="container">
    <h1><?= e($hero['title']) ?></h1>
    <?php if (!empty($hero['subtitle'])): ?><p><?= e($hero['subtitle']) ?></p><?php endif; ?>
    <?php if (!empty($hero['cta'])): ?>
      <div class="hero-actions"><?= $hero['cta'] ?></div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<main class="site-main">
  <div class="container">
    <?php foreach (flashes() as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?php if (!empty($GLOBALS['config']['demo_mode']) && !$inAdmin && in_array($script, ['register.php', 'index.php'], true)): ?>
      <div class="demo-ribbon">Demo mode &middot; no payments are processed</div>
    <?php endif; ?>
