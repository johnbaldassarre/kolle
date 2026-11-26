<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$tpl = ['id' => 0, 'template_key' => null, 'name' => '', 'subject' => '', 'body' => '', 'is_system' => 0];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM email_templates WHERE id = ?');
    $stmt->execute([$id]);
    $tpl = $stmt->fetch();
    if (!$tpl) {
        flash('error', 'Template not found.');
        redirect('admin/emails.php');
    }
}

$errors = [];
if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $id && !$tpl['is_system']) {
        db()->prepare('DELETE FROM email_templates WHERE id = ? AND is_system = 0')->execute([$id]);
        flash('success', 'Template deleted.');
        redirect('admin/emails.php');
    }
    if ($action === 'restore' && $tpl['is_system']) {
        $defaults = default_email_templates()[$tpl['template_key']] ?? null;
        if ($defaults) {
            db()->prepare('UPDATE email_templates SET subject = ?, body = ? WHERE id = ?')->execute([$defaults[1], $defaults[2], $id]);
            flash('success', 'Default wording restored.');
        }
        redirect('admin/email_template.php?id=' . $id);
    }

    $tpl['name']    = trim((string)($_POST['name'] ?? $tpl['name']));
    $tpl['subject'] = trim((string)($_POST['subject'] ?? ''));
    $tpl['body']    = str_replace("\r\n", "\n", trim((string)($_POST['body'] ?? '')));

    if ($tpl['name'] === '') $errors['name'] = 'Give the template a name.';
    if ($tpl['subject'] === '') $errors['subject'] = 'Subject is required.';
    if ($tpl['body'] === '') $errors['body'] = 'Message is required.';
    if ($tpl['template_key'] === 'password_reset' && !str_contains($tpl['body'], '{reset_link}')) {
        $errors['body'] = 'The password reset email must include {reset_link}.';
    }
    $unknown = array_diff(array_unique(preg_match_all('/\{[a-z_]+\}/', $tpl['subject'] . ' ' . $tpl['body'], $m) ? $m[0] : []), array_keys(email_placeholders()));
    if ($unknown) {
        $errors['body'] = 'Unknown placeholder(s): ' . implode(', ', $unknown) . '. Check the list on the right.';
    }

    if (!$errors && $action === 'save') {
        if ($id) {
            db()->prepare('UPDATE email_templates SET name = ?, subject = ?, body = ? WHERE id = ?')
                ->execute([$tpl['name'], $tpl['subject'], $tpl['body'], $id]);
            flash('success', 'Template saved.');
        } else {
            db()->prepare('INSERT INTO email_templates (name, subject, body, is_system) VALUES (?, ?, ?, 0)')
                ->execute([$tpl['name'], $tpl['subject'], $tpl['body']]);
            $id = (int)db()->lastInsertId();
            flash('success', 'Template created.');
        }
        redirect('admin/email_template.php?id=' . $id);
    }
}

$sample = email_sample_vars();
$previewSubject = $tpl['subject'] !== '' ? email_fill($tpl['subject'], $sample) : '';
$previewBody = $tpl['body'] !== '' ? email_fill($tpl['body'], $sample) : '';

$pageTitle = $id ? 'Edit Email Template' : 'New Email Template';
$adminPage = 'emails';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title"><?= $id ? e($tpl['name']) : 'New email template' ?> <?= $tpl['is_system'] ? '<span class="tag">Built-in</span>' : '' ?></h1>
  <div class="head-actions">
    <?php if ($id && $tpl['template_key'] !== 'password_reset'): ?><a class="btn btn-ghost" href="<?= e(url('admin/email_compose.php?template=' . $id)) ?>">Send this email</a><?php endif; ?>
    <a class="btn btn-ghost" href="<?= e(url('admin/emails.php')) ?>">&larr; All templates</a>
  </div>
</div>

<div class="template-layout">
  <form method="post" class="card" id="tpl-form" novalidate>
    <?= csrf_field() ?>
    <?php if ($tpl['template_key'] === 'registration_accepted'): ?>
      <div class="alert alert-info">Offered automatically when you mark a player <strong>Verified</strong> on their player page.</div>
    <?php elseif ($tpl['template_key'] === 'registration_denied'): ?>
      <div class="alert alert-info">Offered automatically when you mark a player <strong>Rejected</strong>. Your verification note fills <code>{verification_note}</code>.</div>
    <?php elseif ($tpl['template_key'] === 'password_reset'): ?>
      <div class="alert alert-info">Sent automatically when someone uses <strong>Forgot password</strong>. Must contain <code>{reset_link}</code>.</div>
    <?php endif; ?>
    <label>Template name<input name="name" value="<?= e($tpl['name']) ?>" placeholder="e.g. Camp reminder – 1 week out"><?= isset($errors['name']) ? '<div class="field-error">' . e($errors['name']) . '</div>' : '' ?></label>
    <label>Subject<input name="subject" value="<?= e($tpl['subject']) ?>" data-insert-target><?= isset($errors['subject']) ? '<div class="field-error">' . e($errors['subject']) . '</div>' : '' ?></label>
    <label>Message<textarea name="body" rows="16" data-insert-target class="mono"><?= e($tpl['body']) ?></textarea>
      <span class="hint">Plain text. Line breaks are kept, links become clickable, and it's sent inside your branded email layout.</span>
      <?= isset($errors['body']) ? '<div class="field-error">' . e($errors['body']) . '</div>' : '' ?></label>
    <div class="form-actions">
      <div class="head-actions">
        <?php if ($id && !$tpl['is_system']): ?>
          <button class="link danger" name="action" value="delete" data-confirm="Delete this template?">Delete template</button>
        <?php elseif ($tpl['is_system']): ?>
          <button class="link" name="action" value="restore" data-confirm="Replace the subject and message with the original wording?">Restore default wording</button>
        <?php endif; ?>
      </div>
      <div class="head-actions">
        <button class="btn btn-ghost" name="action" value="preview">Preview</button>
        <button class="btn btn-primary" name="action" value="save"><?= $id ? 'Save template' : 'Create template' ?></button>
      </div>
    </div>
  </form>

  <aside class="card placeholders">
    <h3 style="margin-top:0">Placeholders</h3>
    <p class="muted"><small>Click to insert at the cursor. Each is replaced with the recipient's details when sent.</small></p>
    <ul>
      <?php foreach (email_placeholders() as $ph => $desc): if ($ph === '{reset_link}' && $tpl['template_key'] !== 'password_reset') continue; ?>
        <li><button type="button" class="ph" data-insert="<?= e($ph) ?>"><?= e($ph) ?></button><small><?= e($desc) ?></small></li>
      <?php endforeach; ?>
    </ul>
  </aside>
</div>

<?php if ($previewBody !== ''): ?>
<div class="card">
  <h2>Preview <small class="muted">(sample player)</small></h2>
  <p><strong>Subject:</strong> <?= e($previewSubject) ?></p>
  <iframe class="email-frame" sandbox title="Email preview" srcdoc="<?= e(email_html($previewSubject, $previewBody)) ?>"></iframe>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
