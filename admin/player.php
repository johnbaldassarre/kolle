<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/verify.php';
$admin = require_admin();

$id = (int)($_GET['id'] ?? 0);
function load_player(int $id): ?array
{
    $stmt = db()->prepare('SELECT u.*, p.*, u.id AS id, l.name AS level_name, l.rank_value, vb.first_name AS vb_first, vb.last_name AS vb_last
                           FROM users u LEFT JOIN players p ON p.user_id = u.id LEFT JOIN levels l ON l.id = p.level_id
                           LEFT JOIN users vb ON vb.id = p.verified_by WHERE u.id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
$p = load_player($id);
if (!$p) {
    flash('error', 'Player not found.');
    redirect('admin/players.php');
}
if ($p['user_id'] === null) {
    db()->prepare('INSERT IGNORE INTO players (user_id) VALUES (?)')->execute([$id]);
    $p = load_player($id);
}

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'auto_check') {
        @set_time_limit(60);
        $result = verify_player($p);
        verify_store($id, $result);
        flash($result['verdict'] === 'unavailable' ? 'info' : 'success', 'Auto-check finished: ' . verify_verdict_label($result['verdict']) . ' (' . $result['score'] . '/100).');
    } elseif ($action === 'paste_check') {
        $text = (string)($_POST['page_text'] ?? '');
        if (trim($text) === '') {
            flash('error', 'Paste the profile page text first.');
        } else {
            $result = verify_player($p, substr($text, 0, VERIFY_MAX_BYTES));
            verify_store($id, $result);
            flash('success', 'Checked pasted text: ' . verify_verdict_label($result['verdict']) . ' (' . $result['score'] . '/100).');
        }
    } elseif ($action === 'decide') {
        $status = $_POST['verification_status'] ?? '';
        $note   = trim($_POST['verification_note'] ?? '');
        $level  = (int)($_POST['level_id'] ?? 0);
        if (in_array($status, ['pending', 'verified', 'flagged', 'rejected'], true)) {
            db()->prepare('UPDATE players SET verification_status = ?, verification_note = ?, verified_by = ?, verified_at = ?, level_id = COALESCE(NULLIF(?, 0), level_id)
                           WHERE user_id = ?')
                ->execute([$status, $note ?: null, $status === 'pending' ? null : $admin['id'], $status === 'pending' ? null : date('Y-m-d H:i:s'), $level, $id]);
            $msg = 'Verification saved.';
            $tplKey = ['verified' => 'registration_accepted', 'rejected' => 'registration_denied'][$status] ?? null;
            if ($tplKey && !empty($_POST['notify']) && ($tpl = email_template($tplKey))) {
                $res = email_send_to_user($id, $tpl, ['cc_parent' => true, 'sent_by' => $admin['id']]);
                $msg .= $res['ok']
                    ? ' "' . $tpl['name'] . '" email ' . ($res['status'] === 'logged' ? 'recorded (log-only mode).' : 'sent.')
                    : ' The email could not be sent: ' . $res['error'];
            }
            flash(isset($res) && !$res['ok'] ? 'error' : 'success', $msg);
        }
    } elseif ($action === 'cancel_reg') {
        db()->prepare('UPDATE registrations SET status = "cancelled" WHERE id = ? AND user_id = ?')->execute([(int)$_POST['reg_id'], $id]);
        flash('success', 'Registration cancelled. The spot is open again.');
    } elseif ($action === 'restore_reg') {
        db()->prepare('UPDATE registrations SET status = "active" WHERE id = ? AND user_id = ?')->execute([(int)$_POST['reg_id'], $id]);
        flash('success', 'Registration restored.');
    } elseif ($action === 'set_password') {
        $pw = (string)($_POST['new_password'] ?? '');
        if ($prob = password_problem($pw)) {
            flash('error', $prob);
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            flash('success', 'Password changed. Let the player know their new password.');
        }
    }
    redirect('admin/player.php?id=' . $id);
}

$stmt = db()->prepare('SELECT r.*, c.name, c.start_date, c.end_date, o.order_number, o.payment_method
                       FROM registrations r JOIN camps c ON c.id = r.camp_id JOIN orders o ON o.id = r.order_id
                       WHERE r.user_id = ? ORDER BY r.status, c.start_date');
$stmt->execute([$id]);
$regs = $stmt->fetchAll();

$stmt = db()->prepare('SELECT l.id, l.subject, l.status, l.created_at, l.to_email, l.cc_email FROM email_log l WHERE l.user_id = ? ORDER BY l.id DESC LIMIT 20');
$stmt->execute([$id]);
$emails = $stmt->fetchAll();

$check = $p['auto_check_result'] ? json_decode($p['auto_check_result'], true) : null;
$age = age_from_birthdate($p['birthdate']);

$pageTitle = $p['first_name'] . ' ' . $p['last_name'];
$adminPage = 'players';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title"><?= e($p['first_name'] . ' ' . $p['last_name']) ?> <?= verification_badge($p['verification_status']) ?></h1>
  <div class="head-actions">
    <a class="btn btn-ghost" href="<?= e(url('admin/email_compose.php?player=' . $id)) ?>">&#9993; Send email</a>
    <a class="btn btn-ghost" href="<?= e(url('admin/players.php')) ?>">&larr; All players</a>
  </div>
</div>

<div class="grid-2 cards">
  <div class="card">
    <h2>Player</h2>
    <dl class="details">
      <dt>Birthdate</dt><dd><?= e(fmt_date($p['birthdate'])) ?> (age <?= (int)$age ?><?= $age < 18 ? ', minor' : '' ?>)</dd>
      <dt>Email</dt><dd><a href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a></dd>
      <dt>Phone</dt><dd><?= e($p['phone'] ?: '—') ?></dd>
      <?php if (!$p['is_adult']): ?>
        <dt>Parent/guardian</dt><dd><?= e($p['parent_first_name'] . ' ' . $p['parent_last_name']) ?> (<?= e($p['parent_relationship']) ?>)<br>
          <a href="mailto:<?= e($p['parent_email']) ?>"><?= e($p['parent_email']) ?></a> &middot; <?= e($p['parent_phone']) ?></dd>
      <?php endif; ?>
      <dt>Position</dt><dd><?= e($p['position'] ?: '—') ?><?= $p['shoots'] ? ' · shoots ' . e($p['shoots']) : '' ?></dd>
      <dt>Account created</dt><dd><?= e(fmt_date($p['created_at'], 'M j, Y g:i a')) ?></dd>
    </dl>
  </div>
  <div class="card">
    <h2>Claimed level of play</h2>
    <dl class="details">
      <dt>Level</dt><dd><strong><?= e($p['level_name'] ?? '—') ?></strong></dd>
      <dt>League</dt><dd><?= e($p['league_name'] ?: '—') ?></dd>
      <dt>Team</dt><dd><?= e($p['team_name'] ?: '—') ?></dd>
      <dt>Season</dt><dd><?= e($p['last_season'] ?: '—') ?></dd>
      <dt>Profile</dt><dd><?php if ($p['profile_url']): ?><?= e(profile_source_label($p['profile_source'])) ?>:<br>
        <a href="<?= e($p['profile_url']) ?>" target="_blank" rel="noopener noreferrer" class="break"><?= e($p['profile_url']) ?> &#8599;</a>
        <?php else: ?><?= e(profile_source_label($p['profile_source'])) ?><?php endif; ?></dd>
      <?php if ($p['no_profile_reason']): ?><dt>Player's note</dt><dd><?= nl2br(e($p['no_profile_reason'])) ?></dd><?php endif; ?>
    </dl>
  </div>
</div>

<div class="card verify-card">
  <div class="card-head">
    <h2>Level verification</h2>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="auto_check">
      <button class="btn btn-primary"<?= $p['profile_url'] ? '' : ' disabled title="No profile link"' ?>>Run auto-check on profile link</button></form>
  </div>

  <?php if ($check): ?>
    <div class="verify-summary verdict-<?= e($check['verdict']) ?>">
      <div class="score-big"><?= (int)$check['score'] ?><small>/100</small></div>
      <div>
        <strong><?= e(verify_verdict_label($check['verdict'])) ?></strong><br>
        <span class="muted">Checked <?= e(fmt_date($check['checked_at'], 'M j, Y g:i a')) ?> &middot; source: <?= e(['fetched' => 'live page', 'pasted' => 'pasted text', 'url-only' => 'link only'][$check['source']] ?? $check['source']) ?></span>
        <?php if ($check['recent_season']): ?><br>Most recent season on page: <strong><?= e($check['recent_season']) ?></strong><?= $check['recent_leagues'] ? ' — ' . e(implode(', ', $check['recent_leagues'])) : '' ?><?php endif; ?>
      </div>
    </div>
    <ul class="checklist">
      <?php foreach ($check['checks'] as $c): ?>
        <li class="check-<?= e($c['status']) ?>"><span class="icon"></span><strong><?= e($c['label']) ?></strong> <span><?= e($c['detail']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($check['leagues_found']): ?>
      <details<?= count($check['leagues_found']) <= 6 ? ' open' : '' ?>>
        <summary>Levels detected on the page (<?= count($check['leagues_found']) ?>)</summary>
        <table class="table compact">
          <thead><tr><th>Level</th><th>Matched</th><th class="num">Hits</th><th>In season stats</th><th>Most recent</th></tr></thead>
          <tbody>
          <?php foreach ($check['leagues_found'] as $lf): ?>
            <tr<?= $lf['claimed'] ? ' class="highlight"' : '' ?>><td><?= e($lf['level']) ?><?= $lf['claimed'] ? ' <span class="tag">claimed</span>' : '' ?></td>
              <td><small><?= e($lf['keywords']) ?></small></td><td class="num"><?= (int)$lf['count'] ?></td>
              <td><?= $lf['in_stats'] ? '&#10003;' : '' ?></td><td><?= $lf['recent'] ? '&#10003;' : '' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </details>
    <?php endif; ?>
  <?php else: ?>
    <p class="muted">No automatic check has run yet.</p>
  <?php endif; ?>

  <details class="paste-box"<?= $check && $check['verdict'] === 'unavailable' ? ' open' : '' ?>>
    <summary>Check pasted page text (use when a site blocks the automatic check)</summary>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="paste_check">
      <p class="muted">Open the player's profile, press <kbd>Ctrl</kbd>+<kbd>A</kbd> then <kbd>Ctrl</kbd>+<kbd>C</kbd>, and paste it here.</p>
      <textarea name="page_text" rows="6" placeholder="Paste the profile page here…"></textarea>
      <button class="btn btn-ghost">Check pasted text</button>
    </form>
  </details>

  <hr>
  <form method="post" class="decision">
    <?= csrf_field() ?><input type="hidden" name="action" value="decide">
    <h3>Your decision</h3>
    <div class="choice-row">
      <?php foreach (['verified' => 'Verified', 'flagged' => 'Flag for follow-up', 'rejected' => 'Rejected', 'pending' => 'Pending'] as $k => $v): ?>
        <label class="choice choice-<?= $k ?>"><input type="radio" name="verification_status" value="<?= $k ?>"<?= checked($p['verification_status'] === $k) ?>><span><?= $v ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="grid-2">
      <label>Confirmed level (change if the claim was wrong)
        <select name="level_id">
          <?php foreach (levels(false) as $l): ?><option value="<?= (int)$l['id'] ?>"<?= selected($p['level_id'], $l['id']) ?>><?= e($l['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Note<input name="verification_note" value="<?= e($p['verification_note']) ?>" placeholder="e.g. Confirmed on EP – 2025-26 OJHL">
        <span class="hint">Included in emails that use {verification_note} (e.g. Registration denied).</span></label>
    </div>
    <label class="checkbox notify-box" data-notify hidden><input type="checkbox" name="notify" value="1" checked>
      <span data-notify-label="verified">Email the player the <strong>Registration accepted</strong> message<?= !$p['is_adult'] && $p['parent_email'] ? ' (cc parent/guardian)' : '' ?></span>
      <span data-notify-label="rejected">Email the player the <strong>Registration denied</strong> message<?= !$p['is_adult'] && $p['parent_email'] ? ' (cc parent/guardian)' : '' ?></span>
      <a href="<?= e(url('admin/emails.php')) ?>" class="small-link">edit wording</a></label>
    <div class="form-actions">
      <span class="muted"><?php if ($p['verified_at']): ?>Last decision by <?= e($p['vb_first'] . ' ' . $p['vb_last']) ?> on <?= e(fmt_date($p['verified_at'], 'M j, Y g:i a')) ?><?php endif; ?></span>
      <button class="btn btn-primary">Save decision</button>
    </div>
  </form>
</div>

<div class="grid-2 cards">
  <div class="card">
    <h2>Camp registrations</h2>
    <?php if (!$regs): ?><p class="muted">No registrations<?= (int)$p['wizard_step'] < 5 ? ' — the player stopped at step ' . (int)$p['wizard_step'] . ' of the wizard' : '' ?>.</p><?php else: ?>
    <table class="table">
      <thead><tr><th>Camp</th><th>Order</th><th class="num">Price</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($regs as $r): ?>
        <tr<?= $r['status'] === 'cancelled' ? ' class="inactive"' : '' ?>>
          <td><?= e($r['name']) ?><br><small class="muted"><?= e(date_range($r['start_date'], $r['end_date'])) ?></small></td>
          <td><?= e($r['order_number']) ?><?= $r['payment_method'] === 'demo' ? ' <span class="tag">demo</span>' : '' ?></td>
          <td class="num"><?= money($r['price']) ?></td>
          <td><form method="post"><?= csrf_field() ?><input type="hidden" name="reg_id" value="<?= (int)$r['id'] ?>">
            <?php if ($r['status'] === 'active'): ?>
              <input type="hidden" name="action" value="cancel_reg"><button class="link danger" data-confirm="Cancel this registration?">Cancel</button>
            <?php else: ?>
              <input type="hidden" name="action" value="restore_reg"><span class="tag tag-warn">Cancelled</span> <button class="link">Restore</button>
            <?php endif; ?></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h2>Emails</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('admin/email_compose.php?player=' . $id)) ?>">Send email</a></div>
    <?php if (!$emails): ?><p class="muted">No emails sent to this player yet.</p><?php else: ?>
    <table class="table compact">
      <tbody>
      <?php foreach ($emails as $m): ?>
        <tr><td><a href="<?= e(url('admin/email_view.php?id=' . $m['id'])) ?>"><?= e($m['subject']) ?></a><br><small class="muted"><?= e(fmt_date($m['created_at'], 'M j, Y g:i a')) ?></small></td>
          <td><?= email_status_badge($m['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <hr>
    <h3 style="margin-top:0">Account</h3>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="set_password">
      <label>Set a new password for this player<input type="text" name="new_password" autocomplete="off" placeholder="New password"></label>
      <button class="btn btn-ghost">Set password</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
