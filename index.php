<?php
require __DIR__ . '/includes/bootstrap.php';

$user  = current_user();
$camps = camps_with_counts();
$pageTitle = 'Camp Registration';
$hero = [
    'title'    => 'Camp Registration',
    'subtitle' => 'Elite on-ice development for players at every level. Pick your camps, register in minutes.',
    'cta'      => $user
        ? '<a class="btn btn-primary" href="' . e(url('register.php')) . '">Continue Registration</a> <a class="btn btn-outline-light" href="' . e(url('account.php')) . '">My Account</a>'
        : '<a class="btn btn-primary" href="' . e(url('register.php')) . '">Register Now</a> <a class="btn btn-outline-light" href="' . e(url('login.php')) . '">Log In</a>',
];
require __DIR__ . '/includes/header.php';
?>
<section class="how-it-works">
  <h2 class="section-title">How it works</h2>
  <ol class="steps-row">
    <li><span>1</span>Create your account</li>
    <li><span>2</span>Tell us your level of play</li>
    <li><span>3</span>Share your player profile</li>
    <li><span>4</span>Sign the waiver</li>
    <li><span>5</span>Pick your camps</li>
    <li><span>6</span>Check out</li>
  </ol>
</section>

<section>
  <h2 class="section-title">Upcoming camps</h2>
  <?php if (!$camps): ?>
    <p class="muted">New camps are coming soon.</p>
  <?php endif; ?>
  <div class="camp-grid">
    <?php foreach ($camps as $c):
        $full = $c['capacity'] !== null && (int)$c['registered'] >= (int)$c['capacity']; ?>
      <article class="camp-option static">
        <span class="camp-body">
          <span class="camp-top">
            <span class="camp-name"><?= e($c['name']) ?></span>
            <span class="camp-price"><?= money($c['price']) ?></span>
          </span>
          <span class="camp-meta"><?= e(date_range($c['start_date'], $c['end_date'])) ?><?= $c['schedule'] ? ' &middot; ' . e($c['schedule']) : '' ?></span>
          <?php if ($c['location']): ?><span class="camp-meta"><?= e($c['location']) ?></span><?php endif; ?>
          <?php if ($c['description']): ?><span class="camp-desc"><?= e($c['description']) ?></span><?php endif; ?>
          <span class="camp-tags">
            <span class="tag"><?= e(birth_years_label($c['min_birth_year'] ? (int)$c['min_birth_year'] : null, $c['max_birth_year'] ? (int)$c['max_birth_year'] : null)) ?></span>
            <?php if (camp_level_label($c)): ?><span class="tag"><?= e(camp_level_label($c)) ?></span><?php endif; ?>
            <?php if ($full): ?><span class="tag tag-warn">Full</span><?php endif; ?>
          </span>
        </span>
      </article>
    <?php endforeach; ?>
  </div>
  <p class="center"><a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Register Now</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
