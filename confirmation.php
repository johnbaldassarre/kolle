<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$stmt = db()->prepare('SELECT * FROM orders WHERE order_number = ? AND user_id = ?');
$stmt->execute([$_GET['order'] ?? '', $user['id']]);
$order = $stmt->fetch();
if (!$order) {
    redirect('account.php');
}
$stmt = db()->prepare('SELECT r.price, c.name, c.start_date, c.end_date, c.schedule, c.location
                       FROM registrations r JOIN camps c ON c.id = r.camp_id WHERE r.order_id = ? ORDER BY c.start_date');
$stmt->execute([$order['id']]);
$items = $stmt->fetchAll();

$pageTitle = 'Registration Complete';
require __DIR__ . '/includes/header.php';
?>
<div class="card narrow-wide confirmation">
  <div class="confirm-icon">&#10003;</div>
  <h2>You're registered!</h2>
  <p>Thanks, <?= e($user['first_name']) ?>. Your order number is <strong><?= e($order['order_number']) ?></strong>.</p>
  <table class="table">
    <thead><tr><th>Camp</th><th>When</th><th>Where</th><th class="num">Price</th></tr></thead>
    <tbody>
    <?php foreach ($items as $i): ?>
      <tr><td><?= e($i['name']) ?></td><td><?= e(date_range($i['start_date'], $i['end_date'])) ?><br><small><?= e($i['schedule']) ?></small></td><td><?= e($i['location']) ?></td><td class="num"><?= money($i['price']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th colspan="3">Total paid<?= $order['payment_method'] === 'demo' ? ' (demo)' : '' ?></th><th class="num"><?= money($order['total']) ?></th></tr></tfoot>
  </table>
  <p class="muted">Our staff will review your player profile to confirm your level of play. You can check your status any time in My Account.</p>
  <p class="center no-print">
    <a class="btn btn-primary" href="<?= e(url('account.php')) ?>">Go to My Account</a>
    <button class="btn btn-ghost" onclick="window.print()">Print receipt</button>
  </p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
