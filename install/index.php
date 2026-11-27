<?php
/**
 * Web installer.
 * 1. Checks server requirements
 * 2. Takes database parameters + first administrator account
 * 3. Creates the tables, loads default data, writes ../config.php
 * Delete (or rename) this /install folder once installation is complete.
 */
declare(strict_types=1);

$root       = dirname(__DIR__);
$configFile = $root . '/config.php';
$lockFile   = __DIR__ . '/install.lock';

require_once dirname(__DIR__) . '/includes/migrate.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function run_sql_file(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Statements end with ";" at the end of a line.
    foreach (preg_split('/;\s*$/m', $sql) as $stmt) {
        if (trim($stmt) !== '') {
            $pdo->exec($stmt);
        }
    }
}

$installed = is_file($lockFile);
$reqs = [
    'PHP 8.1 or newer'          => version_compare(PHP_VERSION, '8.1.0', '>='),
    'PDO MySQL extension'       => extension_loaded('pdo_mysql'),
    'cURL extension (for the profile verifier)' => extension_loaded('curl'),
    'mbstring or intl (recommended)' => extension_loaded('mbstring') || extension_loaded('intl'),
    'App folder writable (to save config.php)' => is_writable($root) || (is_file($configFile) && is_writable($configFile)),
];
$hardFail = !$reqs['PHP 8.1 or newer'] || !$reqs['PDO MySQL extension'];

$errors = [];
$done = false;
$configText = null;
$in = array_merge([
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'kolle_registration', 'db_user' => '', 'db_pass' => '',
    'create_db' => '', 'site_name' => 'Ty Kolle Hockey Development', 'contact_email' => '', 'timezone' => 'America/Toronto',
    'admin_first' => '', 'admin_last' => '', 'admin_email' => '', 'admin_pass' => '', 'sample' => '1', 'demo_mode' => '1',
    'mail_transport' => 'mail', 'mail_from' => '', 'smtp_host' => '', 'smtp_port' => '587', 'smtp_secure' => 'tls', 'smtp_user' => '', 'smtp_pass' => '',
], $_POST);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Unticked checkboxes are not sent, so they must not fall back to the defaults.
    foreach (['create_db', 'sample', 'demo_mode'] as $box) {
        $in[$box] = $_POST[$box] ?? '';
    }
}

if (!$installed && !$hardFail && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $in['db_name'])) $errors[] = 'Database name may only contain letters, numbers, - and _.';
    if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid administrator email.';
    if (strlen($in['admin_pass']) < 8 || !preg_match('/[A-Za-z]/', $in['admin_pass']) || !preg_match('/\d/', $in['admin_pass'])) {
        $errors[] = 'Administrator password must be at least 8 characters with a letter and a number.';
    }
    if (!in_array($in['timezone'], DateTimeZone::listIdentifiers(), true)) $errors[] = 'Choose a valid timezone.';
    if (!in_array($in['mail_transport'], ['mail', 'smtp', 'log'], true)) $errors[] = 'Choose how email is sent.';
    if ($in['mail_from'] !== '' && !filter_var($in['mail_from'], FILTER_VALIDATE_EMAIL)) $errors[] = 'The "From" email address is not valid.';
    if ($in['mail_transport'] === 'smtp' && trim($in['smtp_host']) === '') $errors[] = 'Enter your SMTP server, or choose a different email method.';

    if (!$errors) {
        try {
            $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
            $port = (int)$in['db_port'] ?: 3306;
            if ($in['create_db']) {
                $pdo = new PDO("mysql:host={$in['db_host']};port=$port;charset=utf8mb4", $in['db_user'], $in['db_pass'], $opts);
                $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . $in['db_name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }
            $pdo = new PDO("mysql:host={$in['db_host']};port=$port;dbname={$in['db_name']};charset=utf8mb4", $in['db_user'], $in['db_pass'], $opts);

            $hasTables = (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetch();
            run_sql_file($pdo, __DIR__ . '/schema.sql');
            if (!(int)$pdo->query('SELECT COUNT(*) FROM levels')->fetchColumn()) {
                run_sql_file($pdo, __DIR__ . '/seed.sql');
                if ($in['sample']) {
                    run_sql_file($pdo, __DIR__ . '/sample_camps.sql');
                }
            }
            run_migrations($pdo);
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $siteUrl = ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost')
                     . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')), '/\\') . '/';
            $set = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $set->execute(['site_name', $in['site_name']]);
            $set->execute(['site_url', $siteUrl]);
            if ($in['contact_email']) {
                $set->execute(['contact_email', $in['contact_email']]);
            }

            $email = strtolower(trim($in['admin_email']));
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $hash = password_hash($in['admin_pass'], PASSWORD_DEFAULT);
            if ($id = $stmt->fetchColumn()) {
                $pdo->prepare('UPDATE users SET role = "admin", password_hash = ? WHERE id = ?')->execute([$hash, $id]);
            } else {
                $pdo->prepare('INSERT INTO users (role, first_name, last_name, email, password_hash) VALUES ("admin", ?, ?, ?, ?)')
                    ->execute([$in['admin_first'] ?: 'Admin', $in['admin_last'], $email, $hash]);
            }

            $config = [
                'db' => [
                    'host' => $in['db_host'], 'port' => $port, 'name' => $in['db_name'],
                    'user' => $in['db_user'], 'password' => $in['db_pass'], 'charset' => 'utf8mb4',
                ],
                'base_url'  => '',
                'demo_mode' => (bool)$in['demo_mode'],
                'timezone'  => $in['timezone'],
                'mail'      => [
                    'transport'   => $in['mail_transport'],
                    'from_email'  => trim($in['mail_from']),
                    'from_name'   => '',
                    'smtp_host'   => trim($in['smtp_host']),
                    'smtp_port'   => (int)$in['smtp_port'] ?: 587,
                    'smtp_secure' => in_array($in['smtp_secure'], ['tls', 'ssl', ''], true) ? $in['smtp_secure'] : 'tls',
                    'smtp_user'   => trim($in['smtp_user']),
                    'smtp_pass'   => $in['smtp_pass'],
                ],
                'debug'     => false,
            ];
            $configText = "<?php\n// Generated by the installer on " . date('Y-m-d H:i') . ". See config.sample.php for notes.\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents($configFile, $configText) !== false) {
                @chmod($configFile, 0640);
                $configText = null;
            }
            @file_put_contents($lockFile, date('c'));
            $done = true;
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install – Registration System</title>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<header class="site-header"><div class="container header-inner"><span class="brand"><img src="../assets/img/logo.svg" class="brand-logo" alt=""><span class="brand-text">Registration System Installer</span></span></div></header>
<main class="site-main"><div class="container">

<?php if ($installed && !$done): ?>
  <div class="card narrow">
    <h2>Already installed</h2>
    <p>This system has already been installed. For security, <strong>delete the <code>install</code> folder</strong> from your server.</p>
    <p>To reinstall, delete <code>install/install.lock</code> (and <code>config.php</code> to change database settings) and reload this page.</p>
    <a class="btn btn-primary" href="../">Go to the site</a>
  </div>

<?php elseif ($done): ?>
  <div class="card narrow-wide">
    <h2>Installation complete &#10003;</h2>
    <?php if ($configText): ?>
      <div class="alert alert-error">The installer could not write <code>config.php</code> (folder not writable). Create a file named <code>config.php</code> in the application folder with this content:</div>
      <textarea rows="18" readonly style="font-family:monospace;font-size:13px"><?= h($configText) ?></textarea>
    <?php else: ?>
      <p><code>config.php</code> was saved.</p>
    <?php endif; ?>
    <div class="alert alert-info"><strong>Important:</strong> delete the <code>install</code> folder from your server now.</div>
    <p>
      <a class="btn btn-primary" href="../login.php">Log in as administrator</a>
      <a class="btn btn-ghost" href="../">View registration site</a>
    </p>
  </div>

<?php else: ?>
  <form method="post" class="card narrow-wide" autocomplete="off">
    <h2>Install the registration system</h2>
    <h3>Server check</h3>
    <ul class="checklist">
      <?php foreach ($reqs as $label => $ok): ?>
        <li class="check-<?= $ok ? 'pass' : 'fail' ?>"><span class="icon"></span><?= h($label) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($hardFail): ?>
      <div class="alert alert-error">Your server is missing a required component. Ask your host to enable PHP 8.1+ with PDO MySQL.</div>
    <?php else: ?>
      <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endforeach; ?>

      <h3>Database</h3>
      <p class="muted">Create an empty MySQL/MariaDB database and user in your hosting control panel, then enter the details.</p>
      <div class="grid-2">
        <label>Host<input name="db_host" value="<?= h($in['db_host']) ?>" required></label>
        <label>Port<input name="db_port" value="<?= h($in['db_port']) ?>"></label>
        <label>Database name<input name="db_name" value="<?= h($in['db_name']) ?>" required></label>
        <label>Username<input name="db_user" value="<?= h($in['db_user']) ?>" required></label>
        <label>Password<input type="password" name="db_pass" value="<?= h($in['db_pass']) ?>"></label>
        <label class="checkbox"><input type="checkbox" name="create_db" value="1"<?= $in['create_db'] ? ' checked' : '' ?>><span>Create the database if it doesn't exist (needs permission)</span></label>
      </div>

      <h3>Organization</h3>
      <div class="grid-2">
        <label>Organization name<input name="site_name" value="<?= h($in['site_name']) ?>"></label>
        <label>Contact email<input type="email" name="contact_email" value="<?= h($in['contact_email']) ?>"></label>
        <label>Timezone
          <select name="timezone">
            <?php foreach (DateTimeZone::listIdentifiers() as $tz): if (!preg_match('#^(America|Europe|Canada|US|Pacific|Asia|Australia|UTC)#', $tz)) continue; ?>
              <option<?= $tz === $in['timezone'] ? ' selected' : '' ?>><?= h($tz) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div>
          <label class="checkbox"><input type="checkbox" name="sample" value="1"<?= $in['sample'] ? ' checked' : '' ?>><span>Load 5 sample camps</span></label>
          <label class="checkbox"><input type="checkbox" name="demo_mode" value="1"<?= $in['demo_mode'] ? ' checked' : '' ?>><span>Demo mode (no payment processing)</span></label>
        </div>
      </div>

      <h3>Email</h3>
      <p class="muted">Used for password resets and registration emails. You can change this later in <code>config.php</code>.</p>
      <div class="grid-2">
        <label>How should email be sent?
          <select name="mail_transport" data-mail-transport>
            <option value="mail"<?= $in['mail_transport'] === 'mail' ? ' selected' : '' ?>>Server default – PHP mail()</option>
            <option value="smtp"<?= $in['mail_transport'] === 'smtp' ? ' selected' : '' ?>>SMTP server (Gmail, Microsoft 365, your host…)</option>
            <option value="log"<?= $in['mail_transport'] === 'log' ? ' selected' : '' ?>>Don't send – record in admin only (demo)</option>
          </select>
        </label>
        <label>"From" email address<input type="email" name="mail_from" value="<?= h($in['mail_from']) ?>" placeholder="no-reply@yourdomain.com"><span class="hint">Blank = the contact email.</span></label>
      </div>
      <div class="grid-2" data-smtp-fields>
        <label>SMTP server<input name="smtp_host" value="<?= h($in['smtp_host']) ?>" placeholder="smtp.gmail.com"></label>
        <div class="grid-2 tight">
          <label>Port<input name="smtp_port" value="<?= h($in['smtp_port']) ?>"></label>
          <label>Encryption
            <select name="smtp_secure">
              <?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', '' => 'None'] as $k => $v): ?><option value="<?= $k ?>"<?= $in['smtp_secure'] === $k ? ' selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <label>SMTP username<input name="smtp_user" value="<?= h($in['smtp_user']) ?>" autocomplete="off"></label>
        <label>SMTP password<input type="password" name="smtp_pass" value="<?= h($in['smtp_pass']) ?>" autocomplete="new-password"></label>
      </div>

      <h3>Administrator account</h3>
      <div class="grid-2">
        <label>First name<input name="admin_first" value="<?= h($in['admin_first']) ?>"></label>
        <label>Last name<input name="admin_last" value="<?= h($in['admin_last']) ?>"></label>
        <label>Email (login)<input type="email" name="admin_email" value="<?= h($in['admin_email']) ?>" required></label>
        <label>Password<input type="password" name="admin_pass" required minlength="8"><span class="hint">8+ characters, a letter and a number.</span></label>
      </div>
      <div class="form-actions"><span></span><button class="btn btn-primary">Install</button></div>
    <?php endif; ?>
  </form>
<?php endif; ?>

</div></main>
<script>
(function () {
  var sel = document.querySelector('[data-mail-transport]'), box = document.querySelector('[data-smtp-fields]');
  if (!sel || !box) return;
  var sync = function () { box.hidden = sel.value !== 'smtp'; };
  sel.addEventListener('change', sync); sync();
})();
</script>
</body>
</html>
