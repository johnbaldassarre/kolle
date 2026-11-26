<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle') {
        db()->prepare('UPDATE camps SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('success', 'Camp visibility updated.');
    } elseif ($action === 'delete') {
        $count = db()->prepare('SELECT COUNT(*) FROM registrations WHERE camp_id = ?');
        $count->execute([$id]);
        if ((int)$count->fetchColumn() > 0) {
            flash('error', 'This camp has registrations, so it cannot be deleted. Hide it instead.');
        } else {
            db()->prepare('DELETE FROM camps WHERE id = ?')->execute([$id]);
            flash('success', 'Camp deleted.');
        }
    } elseif ($action === 'price') {
        // Quick price edit from the list
        foreach ((array)($_POST['prices'] ?? []) as $cid => $price) {
            if (is_numeric($price) && $price >= 0) {
                db()->prepare('UPDATE camps SET price = ? WHERE id = ?')->execute([round((float)$price, 2), (int)$cid]);
            }
        }
        flash('success', 'Prices saved.');
    }
    redirect('admin/camps.php');
}

$camps = camps_with_counts(false);
$pageTitle = 'Camps & Pricing';
$adminPage = 'camps';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title">Camps &amp; Pricing</h1>
  <a class="btn btn-primary" href="<?= e(url('admin/camp_edit.php')) ?>">+ New camp</a>
</div>

<form method="post" class="card">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="price">
  <table class="table">
    <thead><tr><th>Camp</th><th>Dates</th><th>Age group</th><th class="num">Enrolled</th><th class="num" style="width:140px">Price</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$camps): ?><tr><td colspan="7" class="muted">No camps yet. Create your first camp.</td></tr><?php endif; ?>
    <?php foreach ($camps as $c): ?>
      <tr<?= $c['is_active'] ? '' : ' class="inactive"' ?>>
        <td><strong><?= e($c['name']) ?></strong><br><small class="muted"><?= e($c['location']) ?></small></td>
        <td><?= e(date_range($c['start_date'], $c['end_date'])) ?></td>
        <td><?= e(birth_years_label($c['min_birth_year'] ? (int)$c['min_birth_year'] : null, $c['max_birth_year'] ? (int)$c['max_birth_year'] : null)) ?></td>
        <td class="num"><?= (int)$c['registered'] ?><?= $c['capacity'] ? ' / ' . (int)$c['capacity'] : '' ?></td>
        <td class="num"><div class="input-money"><span><?= e(setting('currency_symbol', '$')) ?></span><input type="number" step="0.01" min="0" name="prices[<?= (int)$c['id'] ?>]" value="<?= e($c['price']) ?>"></div></td>
        <td><?= $c['is_active'] ? '<span class="badge badge-verified">Open</span>' : '<span class="badge badge-rejected">Hidden</span>' ?></td>
        <td class="actions">
          <a href="<?= e(url('admin/camp_edit.php?id=' . $c['id'])) ?>">Edit</a>
          <a href="<?= e(url('admin/roster.php?camp=' . $c['id'])) ?>">Roster</a>
          <button class="link" form="toggle-<?= (int)$c['id'] ?>"><?= $c['is_active'] ? 'Hide' : 'Show' ?></button>
          <?php if (!(int)$c['registered']): ?>
            <button class="link danger" form="delete-<?= (int)$c['id'] ?>" data-confirm="Delete this camp permanently?">Delete</button>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($camps): ?><div class="form-actions"><span class="muted">Edit prices inline, then save. Changes apply to new registrations only.</span><button class="btn btn-primary">Save prices</button></div><?php endif; ?>
</form>

<?php foreach ($camps as $c): ?>
  <form method="post" id="toggle-<?= (int)$c['id'] ?>" hidden><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
  <form method="post" id="delete-<?= (int)$c['id'] ?>" hidden><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
