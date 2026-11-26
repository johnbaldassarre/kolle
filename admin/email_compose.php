<?php
require __DIR__ . '/../includes/bootstrap.php';
$admin = require_admin();

$templates = db()->query("SELECT * FROM email_templates WHERE template_key IS NULL OR template_key <> 'password_reset'
                          ORDER BY is_system DESC, name")->fetchAll();
$byId = array_column($templates, null, 'id');
$camps = camps_with_counts(false);

// ---- Form state (GET pre-fills, POST submits) -----------------------------
$src = is_post() ? $_POST : $_GET;
$audience = $src['audience'] ?? (isset($src['player']) ? 'player' : (isset($src['camp']) ? 'camp' : 'camp'));
$playerId = (int)($src['player'] ?? 0);
$campId   = (int)($src['camp'] ?? ($camps[0]['id'] ?? 0));
$status   = $src['status'] ?? 'verified';
$tplId    = (int)($src['template'] ?? 0);
$tpl      = $byId[$tplId] ?? null;
$subject  = is_post() ? trim((string)($_POST['subject'] ?? '')) : ($tpl['subject'] ?? '');
$body     = is_post() ? str_replace("\r\n", "\n", trim((string)($_POST['body'] ?? ''))) : ($tpl['body'] ?? '');
$ccParent = is_post() ? !empty($_POST['cc_parent']) : true;

/** Resolve the audience to [user ids, camp row or null]. */
function compose_recipients(string $audience, int $playerId, int $campId, string $status): array
{
    switch ($audience) {
        case 'player':
            $stmt = db()->prepare('SELECT id FROM users WHERE id = ? AND role = "user"');
            $stmt->execute([$playerId]);
            return [array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), null];
        case 'camp':
            $stmt = db()->prepare('SELECT DISTINCT user_id FROM registrations WHERE camp_id = ? AND status = "active"');
            $stmt->execute([$campId]);
            $camp = db()->prepare('SELECT * FROM camps WHERE id = ?');
            $camp->execute([$campId]);
            return [array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), $camp->fetch() ?: null];
        case 'status':
            $stmt = db()->prepare('SELECT DISTINCT p.user_id FROM players p JOIN registrations r ON r.user_id = p.user_id AND r.status = "active"
                                   WHERE p.verification_status = ?');
            $stmt->execute([$status]);
            return [array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), null];
        default: // all registered
            return [array_map('intval', db()->query('SELECT DISTINCT user_id FROM registrations WHERE status = "active"')->fetchAll(PDO::FETCH_COLUMN)), null];
    }
}

[$recipients, $camp] = compose_recipients($audience, $playerId, $campId, $status);
$errors = [];
$preview = null;

if (is_post()) {
    csrf_check();
    if ($subject === '') $errors[] = 'Enter a subject.';
    if ($body === '') $errors[] = 'Enter a message.';
    if (!$recipients) $errors[] = 'There are no recipients for this selection.';
    $unknown = array_diff(array_unique(preg_match_all('/\{[a-z_]+\}/', $subject . ' ' . $body, $m) ? $m[0] : []), array_keys(email_placeholders()));
    if ($unknown) $errors[] = 'Unknown placeholder(s): ' . implode(', ', $unknown) . '.';

    if (!$errors && ($_POST['action'] ?? '') === 'send') {
        @set_time_limit(300);
        $counts = ['sent' => 0, 'logged' => 0, 'failed' => 0];
        $templateRow = $tpl ?? ['id' => null, 'subject' => $subject, 'body' => $body];
        foreach ($recipients as $uid) {
            $res = email_send_to_user($uid, $templateRow, [
                'subject'   => $subject,
                'body'      => $body,
                'cc_parent' => $ccParent,
                'extra'     => $camp ? email_camp_vars($camp) : [],
                'sent_by'   => $admin['id'],
            ]);
            $counts[$res['status']]++;
        }
        $msg = [];
        if ($counts['sent'])   $msg[] = $counts['sent'] . ' sent';
        if ($counts['logged']) $msg[] = $counts['logged'] . ' recorded (log-only mode)';
        if ($counts['failed']) $msg[] = $counts['failed'] . ' failed – see the Sent log';
        flash($counts['failed'] ? 'error' : 'success', 'Email: ' . implode(', ', $msg) . '.');
        redirect($audience === 'player' ? 'admin/player.php?id=' . $playerId : 'admin/emails.php?tab=log');
    }
    if (!$errors && $recipients) {
        $first = email_recipient($recipients[0]);
        $vars = email_vars($first, $camp ? email_camp_vars($camp) : []);
        $preview = ['to' => $first, 'subject' => email_fill($subject, $vars), 'body' => email_fill($body, $vars)];
    }
}

// Names for the recipient summary
$names = [];
if ($recipients) {
    $in = implode(',', array_fill(0, count($recipients), '?'));
    $stmt = db()->prepare("SELECT u.first_name, u.last_name, u.email, p.parent_email FROM users u LEFT JOIN players p ON p.user_id = u.id
                           WHERE u.id IN ($in) ORDER BY u.last_name, u.first_name");
    $stmt->execute($recipients);
    $names = $stmt->fetchAll();
}
$single = null;
if ($playerId) {
    $stmt = db()->prepare('SELECT first_name, last_name FROM users WHERE id = ?');
    $stmt->execute([$playerId]);
    $single = $stmt->fetch();
}

$pageTitle = 'Send Email';
$adminPage = 'emails';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title">Send an email</h1>
  <a class="btn btn-ghost" href="<?= e(url($audience === 'player' && $playerId ? 'admin/player.php?id=' . $playerId : 'admin/emails.php')) ?>">&larr; Back</a>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

<div class="template-layout">
<form method="post" class="card" novalidate data-compose
      data-templates="<?= e(json_encode(array_map(fn($t) => ['subject' => $t['subject'], 'body' => $t['body']], $byId), JSON_UNESCAPED_UNICODE)) ?>">
  <?= csrf_field() ?>
  <h2>Recipients</h2>
  <div class="grid-2">
    <label>Send to
      <select name="audience" data-audience>
        <?php if ($playerId): ?><option value="player"<?= selected($audience, 'player') ?>>One player<?= $single ? ': ' . e($single['first_name'] . ' ' . $single['last_name']) : '' ?></option><?php endif; ?>
        <option value="camp"<?= selected($audience, 'camp') ?>>Everyone in a camp</option>
        <option value="status"<?= selected($audience, 'status') ?>>Registered players by verification status</option>
        <option value="all"<?= selected($audience, 'all') ?>>All registered players</option>
      </select>
    </label>
    <label data-show-for="camp">Camp
      <select name="camp">
        <?php foreach ($camps as $c): ?><option value="<?= (int)$c['id'] ?>"<?= selected($campId, $c['id']) ?>><?= e($c['name']) ?> (<?= (int)$c['registered'] ?>)</option><?php endforeach; ?>
      </select>
    </label>
    <label data-show-for="status">Verification status
      <select name="status">
        <?php foreach (['verified' => 'Verified', 'pending' => 'Pending', 'flagged' => 'Flagged', 'rejected' => 'Rejected'] as $k => $v): ?>
          <option value="<?= $k ?>"<?= selected($status, $k) ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <input type="hidden" name="player" value="<?= (int)$playerId ?>">
  <label class="checkbox"><input type="checkbox" name="cc_parent" value="1"<?= checked($ccParent) ?>><span>Also send to the parent/guardian (cc) for players under 18</span></label>

  <h2>Message</h2>
  <label>Start from a template
    <select name="template" data-template-select>
      <option value="">— Blank email —</option>
      <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"<?= selected($tplId, $t['id']) ?>><?= e($t['name']) ?><?= $t['is_system'] ? '' : ' (custom)' ?></option><?php endforeach; ?>
    </select>
    <span class="hint">Edits here apply to this send only. To change the template permanently, edit it under Emails &rarr; Templates.</span>
  </label>
  <label>Subject<input name="subject" value="<?= e($subject) ?>" data-insert-target></label>
  <label>Message<textarea name="body" rows="14" class="mono" data-insert-target><?= e($body) ?></textarea></label>

  <div class="form-actions">
    <span class="muted"><strong><?= count($recipients) ?></strong> recipient(s) for the current selection<?= is_post() ? '' : ' (press Preview to refresh)' ?></span>
    <div class="head-actions">
      <button class="btn btn-ghost" name="action" value="preview">Preview</button>
      <button class="btn btn-primary" name="action" value="send" data-confirm="Send this email now?">Send email</button>
    </div>
  </div>
</form>

<aside class="card placeholders">
  <h3 style="margin-top:0">Placeholders</h3>
  <ul>
    <?php foreach (email_placeholders() as $ph => $desc): if ($ph === '{reset_link}') continue; ?>
      <li><button type="button" class="ph" data-insert="<?= e($ph) ?>"><?= e($ph) ?></button><small><?= e($desc) ?></small></li>
    <?php endforeach; ?>
  </ul>
</aside>
</div>

<?php if ($preview): ?>
<div class="card">
  <h2>Preview <small class="muted">for <?= e($preview['to']['first_name'] . ' ' . $preview['to']['last_name']) ?></small></h2>
  <p><strong>Subject:</strong> <?= e($preview['subject']) ?></p>
  <iframe class="email-frame" sandbox title="Email preview" srcdoc="<?= e(email_html($preview['subject'], $preview['body'])) ?>"></iframe>
</div>
<?php endif; ?>

<?php if ($names && $audience !== 'player'): ?>
<details class="card">
  <summary><?= count($names) ?> recipient(s)</summary>
  <ul class="recipient-list">
    <?php foreach ($names as $n): ?><li><?= e($n['last_name'] . ', ' . $n['first_name']) ?> <small class="muted"><?= e($n['email']) ?><?= $ccParent && $n['parent_email'] ? ' + ' . e($n['parent_email']) : '' ?></small></li><?php endforeach; ?>
  </ul>
</details>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
