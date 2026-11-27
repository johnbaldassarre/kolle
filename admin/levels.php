<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        foreach ((array)($_POST['levels'] ?? []) as $id => $l) {
            $name = trim($l['name'] ?? '');
            if ($name === '') continue;
            db()->prepare('UPDATE levels SET name = ?, category = ?, rank_value = ?, keywords = ?, sort_order = ?, is_active = ? WHERE id = ?')
                ->execute([$name, trim($l['category'] ?? '') ?: 'Other', (int)($l['rank_value'] ?? 0), trim($l['keywords'] ?? ''),
                           (int)($l['sort_order'] ?? 0), !empty($l['is_active']) ? 1 : 0, (int)$id]);
        }
        flash('success', 'Levels saved.');
    } elseif ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            db()->prepare('INSERT INTO levels (name, category, rank_value, keywords, sort_order) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, trim($_POST['category'] ?? '') ?: 'Other', (int)($_POST['rank_value'] ?? 0), trim($_POST['keywords'] ?? ''), (int)($_POST['sort_order'] ?? 999)]);
            flash('success', 'Level added.');
        }
    }
    redirect('admin/levels.php');
}

$levels = levels(false);
$pageTitle = 'Levels of Play';
$adminPage = 'levels';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="page-title">Levels of Play</h1>
<p class="muted">These are the tiers players choose from in the registration wizard. <strong>Rank</strong> orders levels from highest (100) to lowest and is used to spot players claiming a higher or lower level than their stats show.
<strong>Keywords</strong> are the league names and abbreviations the verifier looks for on Elite Prospects and league pages (comma separated; short ALL-CAPS keywords are case-sensitive).</p>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <div class="table-wrap">
  <table class="table levels-table">
    <thead><tr><th>Order</th><th>Level name</th><th>Category</th><th>Rank</th><th>Verifier keywords</th><th>Active</th></tr></thead>
    <tbody>
    <?php foreach ($levels as $l): $k = 'levels[' . (int)$l['id'] . ']'; ?>
      <tr>
        <td><input type="number" name="<?= $k ?>[sort_order]" value="<?= (int)$l['sort_order'] ?>" class="w-xs"></td>
        <td><input name="<?= $k ?>[name]" value="<?= e($l['name']) ?>"></td>
        <td><input name="<?= $k ?>[category]" value="<?= e($l['category']) ?>" class="w-sm"></td>
        <td><input type="number" name="<?= $k ?>[rank_value]" value="<?= (int)$l['rank_value'] ?>" class="w-xs"></td>
        <td><textarea name="<?= $k ?>[keywords]" rows="2"><?= e($l['keywords']) ?></textarea></td>
        <td class="center"><input type="checkbox" name="<?= $k ?>[is_active]" value="1"<?= checked((bool)$l['is_active']) ?>></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="form-actions"><span class="muted">Untick "Active" to hide a level from the wizard without losing existing players' data.</span><button class="btn btn-primary">Save levels</button></div>
</form>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <h2>Add a level</h2>
  <div class="grid-3">
    <label>Level name<input name="name" required></label>
    <label>Category<input name="category" placeholder="Junior, Youth, Professional…"></label>
    <label>Rank (0–100)<input type="number" name="rank_value" value="50"></label>
    <label class="span-2">Verifier keywords<input name="keywords" placeholder="e.g. GOJHL, Greater Ontario Junior Hockey League"></label>
    <label>Order<input type="number" name="sort_order" value="999"></label>
  </div>
  <div class="form-actions"><span></span><button class="btn btn-primary">Add level</button></div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
