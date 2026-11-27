<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$camp = [
    'name' => '', 'description' => '', 'location' => '', 'start_date' => '', 'end_date' => '', 'schedule' => '',
    'min_birth_year' => '', 'max_birth_year' => '', 'capacity' => '', 'min_level_id' => '', 'price' => '0.00',
    'registration_opens' => '', 'registration_closes' => '', 'is_active' => 1, 'sort_order' => 0,
];
if ($id) {
    $stmt = db()->prepare('SELECT * FROM camps WHERE id = ?');
    $stmt->execute([$id]);
    $camp = $stmt->fetch();
    if (!$camp) {
        flash('error', 'Camp not found.');
        redirect('admin/camps.php');
    }
}

$errors = [];
if (is_post()) {
    csrf_check();
    $in = [];
    foreach (array_keys($camp) as $k) {
        if (array_key_exists($k, $_POST)) {
            $in[$k] = trim((string)$_POST[$k]);
        }
    }
    $in['is_active'] = !empty($_POST['is_active']) ? 1 : 0;
    $camp = array_merge($camp, $in);

    if ($in['name'] === '') $errors['name'] = 'Name is required.';
    if (!is_numeric($in['price']) || (float)$in['price'] < 0) $errors['price'] = 'Enter a price of 0 or more.';
    foreach (['start_date', 'end_date', 'registration_opens', 'registration_closes'] as $d) {
        if ($in[$d] !== '' && !valid_date($in[$d])) $errors[$d] = 'Invalid date.';
    }
    if ($in['start_date'] && $in['end_date'] && $in['end_date'] < $in['start_date']) $errors['end_date'] = 'End date is before start date.';
    foreach (['min_birth_year', 'max_birth_year'] as $y) {
        if ($in[$y] !== '' && (!ctype_digit($in[$y]) || $in[$y] < 1930 || $in[$y] > (int)date('Y'))) $errors[$y] = 'Enter a 4-digit year.';
    }
    if ($in['min_birth_year'] && $in['max_birth_year'] && $in['min_birth_year'] > $in['max_birth_year']) $errors['max_birth_year'] = 'Must be the same or later than the earliest year.';
    $levelIds = array_map('intval', array_column(levels(false), 'id'));
    if ($in['min_level_id'] !== '' && !in_array((int)$in['min_level_id'], $levelIds, true)) $errors['min_level_id'] = 'Choose a level from the list.';
    if ($in['capacity'] !== '' && (!ctype_digit($in['capacity']) || (int)$in['capacity'] < 1)) $errors['capacity'] = 'Enter a whole number, or leave blank for unlimited.';

    if (!$errors) {
        $vals = [
            $in['name'], $in['description'] ?: null, $in['location'] ?: null, $in['start_date'] ?: null, $in['end_date'] ?: null,
            $in['schedule'] ?: null, $in['min_birth_year'] ?: null, $in['max_birth_year'] ?: null, $in['capacity'] !== '' ? (int)$in['capacity'] : null,
            $in['min_level_id'] !== '' ? (int)$in['min_level_id'] : null, round((float)$in['price'], 2), $in['registration_opens'] ?: null, $in['registration_closes'] ?: null, $in['is_active'], (int)$in['sort_order'],
        ];
        if ($id) {
            $vals[] = $id;
            db()->prepare('UPDATE camps SET name=?, description=?, location=?, start_date=?, end_date=?, schedule=?, min_birth_year=?, max_birth_year=?,
                           capacity=?, min_level_id=?, price=?, registration_opens=?, registration_closes=?, is_active=?, sort_order=? WHERE id=?')->execute($vals);
            flash('success', 'Camp updated.');
        } else {
            db()->prepare('INSERT INTO camps (name, description, location, start_date, end_date, schedule, min_birth_year, max_birth_year,
                           capacity, min_level_id, price, registration_opens, registration_closes, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($vals);
            flash('success', 'Camp created.');
        }
        redirect('admin/camps.php');
    }
}

function ce(array $errors, string $k): string
{
    return isset($errors[$k]) ? '<div class="field-error">' . e($errors[$k]) . '</div>' : '';
}

$pageTitle = $id ? 'Edit Camp' : 'New Camp';
$adminPage = 'camps';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <h1 class="page-title"><?= $id ? 'Edit camp' : 'New camp' ?></h1>
  <a class="btn btn-ghost" href="<?= e(url('admin/camps.php')) ?>">&larr; All camps</a>
</div>
<form method="post" class="card" novalidate>
  <?= csrf_field() ?>
  <div class="grid-2">
    <label class="span-2">Camp name<input name="name" value="<?= e($camp['name']) ?>" required><?= ce($errors, 'name') ?></label>
    <label class="span-2">Description<textarea name="description" rows="3"><?= e($camp['description']) ?></textarea></label>
    <label>Location / rink<input name="location" value="<?= e($camp['location']) ?>"></label>
    <label>Schedule<input name="schedule" value="<?= e($camp['schedule']) ?>" placeholder="Mon–Fri · 9:00 AM – 12:00 PM"></label>
    <label>Start date<input type="date" name="start_date" value="<?= e($camp['start_date']) ?>"><?= ce($errors, 'start_date') ?></label>
    <label>End date<input type="date" name="end_date" value="<?= e($camp['end_date']) ?>"><?= ce($errors, 'end_date') ?></label>
  </div>
  <h3>Pricing &amp; capacity</h3>
  <div class="grid-3">
    <label>Price (<?= e(setting('currency_code', 'CAD')) ?>)<div class="input-money"><span><?= e(setting('currency_symbol', '$')) ?></span><input type="number" step="0.01" min="0" name="price" value="<?= e($camp['price']) ?>"></div><?= ce($errors, 'price') ?></label>
    <label>Capacity<input type="number" min="1" name="capacity" value="<?= e((string)$camp['capacity']) ?>" placeholder="Unlimited"><?= ce($errors, 'capacity') ?></label>
    <label>Display order<input type="number" name="sort_order" value="<?= e((string)$camp['sort_order']) ?>"><span class="hint">Lower numbers show first.</span></label>
  </div>
  <h3>Eligibility</h3>
  <div class="grid-3">
    <label>Earliest birth year<input type="number" name="min_birth_year" value="<?= e((string)$camp['min_birth_year']) ?>" placeholder="Any"><span class="hint">Oldest players allowed</span><?= ce($errors, 'min_birth_year') ?></label>
    <label>Latest birth year<input type="number" name="max_birth_year" value="<?= e((string)$camp['max_birth_year']) ?>" placeholder="Any"><span class="hint">Youngest players allowed</span><?= ce($errors, 'max_birth_year') ?></label>
    <label>Minimum level of play
      <select name="min_level_id">
        <option value="">Any level</option>
        <?php foreach (levels(false) as $l): ?>
          <option value="<?= (int)$l['id'] ?>"<?= selected($camp['min_level_id'], $l['id']) ?>><?= e($l['name']) ?> (rank <?= (int)$l['rank_value'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <span class="hint">Players who claimed a lower level can't register.</span><?= ce($errors, 'min_level_id') ?>
    </label>
    <label>Registration opens<input type="date" name="registration_opens" value="<?= e((string)$camp['registration_opens']) ?>"><?= ce($errors, 'registration_opens') ?></label>
    <label>Registration closes<input type="date" name="registration_closes" value="<?= e((string)$camp['registration_closes']) ?>"><?= ce($errors, 'registration_closes') ?></label>
  </div>
  <label class="checkbox"><input type="checkbox" name="is_active" value="1"<?= checked((bool)$camp['is_active']) ?>><span>Visible and open for registration</span></label>
  <div class="form-actions">
    <a class="btn btn-ghost" href="<?= e(url('admin/camps.php')) ?>">Cancel</a>
    <button class="btn btn-primary"><?= $id ? 'Save changes' : 'Create camp' ?></button>
  </div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
