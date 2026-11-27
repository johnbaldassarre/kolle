<?php
/**
 * Registration wizard.
 *   Step 1  Account       name, email, birthdate, password
 *   Step 2  Player info   adult?  -> parent/guardian info if under 18, last level of hockey played
 *   Step 3  Profile link  Elite Prospects or league player profile (for admin verification)
 *   Step 4  Waiver        read and sign (parent/guardian signs for players under 18)
 *   Step 5  Camps         choose one or more camps (or all eligible), live total
 *   Step 6  Checkout      review + demo payment
 */
require __DIR__ . '/includes/bootstrap.php';

$steps = [1 => 'Account', 2 => 'Player Info', 3 => 'Player Profile', 4 => 'Waiver', 5 => 'Select Camps', 6 => 'Checkout'];
$step  = max(1, min(6, (int)($_GET['step'] ?? 0)));
$user  = current_user();
$errors = [];

if (setting('registration_open', '1') !== '1' && !is_admin()) {
    $pageTitle = 'Registration';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card narrow"><h2>Registration is currently closed</h2><p>Please check back soon or contact us at '
        . e(setting('contact_email')) . '.</p></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ---- Load player profile and work out which steps are unlocked -----------
$player = null;
if ($user) {
    $stmt = db()->prepare('SELECT * FROM players WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    $player = $stmt->fetch();
    if (!$player) {
        db()->prepare('INSERT INTO players (user_id, wizard_step) VALUES (?, 2)')->execute([$user['id']]);
        $player = ['user_id' => $user['id'], 'wizard_step' => 2];
    }
    $maxStep = max(2, (int)$player['wizard_step']);
    if (!isset($_GET['step'])) {
        $step = min($maxStep, 5);
    }
    if ($step === 1) {
        $step = 2;
    }
    if ($step > $maxStep) {
        redirect('register.php?step=' . $maxStep);
    }
    // Camps and checkout need a signed copy of the current waiver.
    if ($step >= 5 && !has_current_waiver((int)$user['id'])) {
        if (latest_waiver((int)$user['id'])) {
            flash('info', 'Our waiver has been updated. Please review and sign the new version to continue.');
        }
        redirect('register.php?step=4');
    }
} else {
    $maxStep = 1;
    if ($step > 1) {
        flash('info', 'Create an account or log in to continue your registration.');
        redirect('register.php?step=1');
    }
}

// Players whose registration was rejected can't buy camps until an admin reviews them again.
$rejected = $player && ($player['verification_status'] ?? '') === 'rejected';
$playerRank = $user ? player_level_rank((int)$user['id']) : null;

function advance_wizard(int $userId, int $toStep): void
{
    db()->prepare('UPDATE players SET wizard_step = GREATEST(wizard_step, ?) WHERE user_id = ?')->execute([$toStep, $userId]);
}

function season_options(): array
{
    $y = (int)date('Y');
    $start = (int)date('n') >= 8 ? $y : $y - 1;
    $out = [];
    for ($i = 0; $i < 12; $i++) {
        $s = $start - $i;
        $out[] = $s . '-' . substr((string)($s + 1), -2);
    }
    return $out;
}

// ---- Handle submissions --------------------------------------------------
if (is_post()) {
    csrf_check();

    // STEP 1: create account ------------------------------------------------
    if ($step === 1) {
        $first = trim($_POST['first_name'] ?? '');
        $last  = trim($_POST['last_name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? '');
        $dob   = trim($_POST['birthdate'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');

        if ($first === '') $errors['first_name'] = 'First name is required.';
        if ($last === '')  $errors['last_name']  = 'Last name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        if (!valid_date($dob)) {
            $errors['birthdate'] = 'Enter a valid birthdate.';
        } else {
            $age = age_from_birthdate($dob);
            if ($dob > date('Y-m-d') || $age < 4 || $age > 90) {
                $errors['birthdate'] = 'Please check the birthdate.';
            }
        }
        if ($p = password_problem($pass)) $errors['password'] = $p;
        elseif ($pass !== $pass2)         $errors['password_confirm'] = 'Passwords do not match.';

        if (!$errors) {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors['email'] = 'An account with this email already exists. Please log in instead.';
            }
        }
        if (!$errors) {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO users (role, first_name, last_name, email, phone, birthdate, password_hash)
                           VALUES ("user", ?, ?, ?, ?, ?, ?)')
                ->execute([$first, $last, $email, $phone ?: null, $dob, password_hash($pass, PASSWORD_DEFAULT)]);
            $uid = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO players (user_id, wizard_step, is_adult) VALUES (?, 2, ?)')
                ->execute([$uid, age_from_birthdate($dob) >= 18 ? 1 : 0]);
            $pdo->commit();
            login_user($uid);
            flash('success', 'Account created. Next, tell us about your hockey.');
            redirect('register.php?step=2');
        }
    }

    // STEP 2: adult / parent info / level of play --------------------------
    if ($step === 2) {
        $age     = age_from_birthdate($user['birthdate']);
        $isAdult = $_POST['is_adult'] ?? '';
        $data = [
            'parent_first_name'   => trim($_POST['parent_first_name'] ?? ''),
            'parent_last_name'    => trim($_POST['parent_last_name'] ?? ''),
            'parent_relationship' => trim($_POST['parent_relationship'] ?? ''),
            'parent_email'        => strtolower(trim($_POST['parent_email'] ?? '')),
            'parent_phone'        => trim($_POST['parent_phone'] ?? ''),
            'level_id'            => (int)($_POST['level_id'] ?? 0),
            'league_name'         => trim($_POST['league_name'] ?? ''),
            'team_name'           => trim($_POST['team_name'] ?? ''),
            'last_season'         => trim($_POST['last_season'] ?? ''),
            'position'            => trim($_POST['position'] ?? ''),
            'shoots'              => trim($_POST['shoots'] ?? ''),
        ];

        if (!in_array($isAdult, ['1', '0'], true)) {
            $errors['is_adult'] = 'Please tell us whether you are 18 or older.';
        } elseif ($isAdult === '1' && $age !== null && $age < 18) {
            $errors['is_adult'] = "Your birthdate shows you are $age. Players under 18 must register with a parent or guardian.";
        } elseif ($isAdult === '0' && $age !== null && $age >= 18) {
            $errors['is_adult'] = "Your birthdate shows you are $age, so you can register as an adult.";
        }

        if ($isAdult === '0') {
            if ($data['parent_first_name'] === '') $errors['parent_first_name'] = 'Required.';
            if ($data['parent_last_name'] === '')  $errors['parent_last_name']  = 'Required.';
            if ($data['parent_relationship'] === '') $errors['parent_relationship'] = 'Required.';
            if (!filter_var($data['parent_email'], FILTER_VALIDATE_EMAIL)) $errors['parent_email'] = 'Enter a valid email.';
            if (strlen(preg_replace('/\D/', '', $data['parent_phone'])) < 7) $errors['parent_phone'] = 'Enter a valid phone number.';
        } else {
            foreach (['parent_first_name', 'parent_last_name', 'parent_relationship', 'parent_email', 'parent_phone'] as $k) {
                $data[$k] = '';
            }
        }

        $levelIds = array_column(levels(), 'id');
        if (!in_array($data['level_id'], array_map('intval', $levelIds), true)) $errors['level_id'] = 'Choose your last level of play.';
        if ($data['league_name'] === '') $errors['league_name'] = 'Enter the league you last played in.';
        if (!in_array($data['last_season'], season_options(), true)) $errors['last_season'] = 'Choose a season.';
        if (!in_array($data['position'], ['Forward', 'Defence', 'Goalie'], true)) $errors['position'] = 'Choose a position.';
        if (!in_array($data['shoots'], ['L', 'R'], true)) $errors['shoots'] = 'Choose one.';

        if (!$errors) {
            $old = $player;
            $sql = 'UPDATE players SET is_adult = ?, parent_first_name = ?, parent_last_name = ?, parent_relationship = ?,
                    parent_email = ?, parent_phone = ?, level_id = ?, league_name = ?, team_name = ?, last_season = ?,
                    position = ?, shoots = ? WHERE user_id = ?';
            db()->prepare($sql)->execute([
                (int)$isAdult,
                $data['parent_first_name'] ?: null, $data['parent_last_name'] ?: null, $data['parent_relationship'] ?: null,
                $data['parent_email'] ?: null, $data['parent_phone'] ?: null,
                $data['level_id'], $data['league_name'], $data['team_name'] ?: null, $data['last_season'],
                $data['position'], $data['shoots'], $user['id'],
            ]);
            // Changing the claimed level after verification sends the player back to review.
            if (($old['level_id'] ?? null) && (int)$old['level_id'] !== $data['level_id'] && in_array($old['verification_status'] ?? '', ['verified', 'flagged'], true)) {
                db()->prepare('UPDATE players SET verification_status = "pending", verified_at = NULL, verified_by = NULL WHERE user_id = ?')
                    ->execute([$user['id']]);
            }
            advance_wizard((int)$user['id'], 3);
            redirect('register.php?step=3');
        }
    }

    // STEP 3: profile link --------------------------------------------------
    if ($step === 3) {
        $source = $_POST['profile_source'] ?? '';
        $link   = trim($_POST['profile_url'] ?? '');
        $reason = trim($_POST['no_profile_reason'] ?? '');

        if (!in_array($source, ['eliteprospects', 'league', 'other', 'none'], true)) {
            $errors['profile_source'] = 'Choose where your player profile is.';
        } elseif ($source === 'none') {
            if (strlen($reason) < 10) $errors['no_profile_reason'] = 'Please tell us briefly why there is no online profile (team, coach contact, etc.).';
            $link = '';
        } else {
            if ($link !== '' && !preg_match('#^https?://#i', $link)) {
                $link = 'https://' . $link;
            }
            $host = strtolower((string)parse_url($link, PHP_URL_HOST));
            if (!filter_var($link, FILTER_VALIDATE_URL) || !$host || !str_contains($host, '.')) {
                $errors['profile_url'] = 'Enter the full web address of your profile page.';
            } elseif ($source === 'eliteprospects' && !str_ends_with($host, 'eliteprospects.com')) {
                $errors['profile_url'] = 'That does not look like an Elite Prospects link (eliteprospects.com/player/...).';
            } elseif (strlen($link) > 500) {
                $errors['profile_url'] = 'That link is too long.';
            }
            $reason = '';
        }

        if (!$errors) {
            $changed = ($player['profile_url'] ?? '') !== $link;
            db()->prepare('UPDATE players SET profile_source = ?, profile_url = ?, no_profile_reason = ? WHERE user_id = ?')
                ->execute([$source, $link ?: null, $reason ?: null, $user['id']]);
            if ($changed) {
                db()->prepare('UPDATE players SET verification_status = "pending", auto_check_result = NULL, auto_check_score = NULL,
                               auto_checked_at = NULL, verified_at = NULL, verified_by = NULL WHERE user_id = ? AND verification_status <> "rejected"')->execute([$user['id']]);
            }
            advance_wizard((int)$user['id'], 4);
            redirect('register.php?step=4');
        }
    }

    // STEP 4: waiver --------------------------------------------------------
    if ($step === 4) {
        $minor    = !(int)($player['is_adult'] ?? 0);
        $expected = $minor ? trim(($player['parent_first_name'] ?? '') . ' ' . ($player['parent_last_name'] ?? '')) : $user['first_name'] . ' ' . $user['last_name'];
        $signer   = trim(preg_replace('/\s+/', ' ', (string)($_POST['signer_name'] ?? '')));

        if (empty($_POST['agree'])) {
            $errors['agree'] = 'Please tick the box to confirm you have read and agree to the waiver.';
        }
        if ($signer === '') {
            $errors['signer_name'] = 'Type your full name to sign.';
        } elseif (normalize_name($signer) !== normalize_name($expected)) {
            $errors['signer_name'] = $minor
                ? "The waiver must be signed by the parent/guardian on file ($expected). Type their full name exactly, or go back to Player Info to change the parent/guardian."
                : "Type your full name exactly as registered ($expected).";
        }
        if (!$errors) {
            db()->prepare('INSERT INTO waivers (user_id, waiver_version, signer_name, signer_relationship, waiver_text, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$user['id'], waiver_version(), $signer, $minor ? ($player['parent_relationship'] ?: 'Parent/Guardian') : 'Self',
                           setting('waiver_text'), substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
            advance_wizard((int)$user['id'], 5);
            flash('success', 'Waiver signed. Now choose your camps.');
            redirect('register.php?step=5');
        }
    }

    // STEP 5: camp selection ------------------------------------------------
    if ($step === 5 && $rejected) {
        redirect('register.php?step=5');
    }
    if ($step === 5) {
        $chosen  = array_unique(array_map('intval', (array)($_POST['camps'] ?? [])));
        $camps   = camps_with_counts();
        $already = user_camp_ids((int)$user['id']);
        $valid   = [];
        foreach ($camps as $c) {
            if (in_array((int)$c['id'], $chosen, true)) {
                if ($why = camp_block_reason($c, $user['birthdate'], $already, $playerRank)) {
                    $errors['camps'] = $c['name'] . ': ' . $why . '.';
                } else {
                    $valid[] = (int)$c['id'];
                }
            }
        }
        if (!$valid && empty($errors['camps'])) {
            $errors['camps'] = 'Select at least one camp.';
        }
        if (!$errors) {
            $_SESSION['cart'] = $valid;
            advance_wizard((int)$user['id'], 6);
            redirect('register.php?step=6');
        }
    }

    // STEP 6: checkout ------------------------------------------------------
    if ($step === 6) {
        $cart = $_SESSION['cart'] ?? [];
        $billing = trim($_POST['billing_name'] ?? '');
        if (!$cart || $rejected) {
            redirect('register.php?step=5');
        }
        if ($billing === '') $errors['billing_name'] = 'Enter the name of the person paying.';

        if (!$errors) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                // Lock the chosen camps so two people cannot take the last spot at once.
                $in   = implode(',', array_fill(0, count($cart), '?'));
                $stmt = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM registrations r WHERE r.camp_id = c.id AND r.status = 'active') AS registered,
                                              ml.name AS min_level_name, ml.rank_value AS min_level_rank
                                       FROM camps c LEFT JOIN levels ml ON ml.id = c.min_level_id
                                       WHERE c.id IN ($in) AND c.is_active = 1 FOR UPDATE");
                $stmt->execute($cart);
                $camps   = $stmt->fetchAll();
                $already = user_camp_ids((int)$user['id']);
                $total   = 0.0;
                foreach ($camps as $c) {
                    if ($why = camp_block_reason($c, $user['birthdate'], $already, $playerRank)) {
                        throw new RuntimeException($c['name'] . ': ' . $why);
                    }
                    $total += (float)$c['price'];
                }
                if (count($camps) !== count($cart)) {
                    throw new RuntimeException('One of your selected camps is no longer available.');
                }
                $orderNo = 'TK-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $demo    = !empty($GLOBALS['config']['demo_mode']);
                $pdo->prepare('INSERT INTO orders (order_number, user_id, total, status, payment_method, payment_ref, billing_name, paid_at)
                               VALUES (?, ?, ?, "paid", ?, ?, ?, NOW())')
                    ->execute([$orderNo, $user['id'], $total, $demo ? 'demo' : 'offline', $demo ? 'DEMO-' . strtoupper(bin2hex(random_bytes(4))) : null, $billing]);
                $orderId = (int)$pdo->lastInsertId();
                $ins = $pdo->prepare('INSERT INTO registrations (order_id, user_id, camp_id, price) VALUES (?, ?, ?, ?)');
                foreach ($camps as $c) {
                    $ins->execute([$orderId, $user['id'], $c['id'], $c['price']]);
                }
                $pdo->commit();
                unset($_SESSION['cart']);
                redirect('confirmation.php?order=' . urlencode($orderNo));
            } catch (RuntimeException $ex) {
                $pdo->rollBack();
                flash('error', $ex->getMessage() . ' Please update your selection.');
                redirect('register.php?step=5');
            }
        }
    }

    // Reload the profile so the form shows what was just saved.
    if ($user) {
        $stmt = db()->prepare('SELECT * FROM players WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $player = $stmt->fetch();
    }
}

/** Value for a field: POSTed value on a failed submit, else the saved one. */
function fv(string $key, ?array $row, $fallback = ''): string
{
    if (is_post() && array_key_exists($key, $_POST)) {
        return e((string)$_POST[$key]);
    }
    return e((string)($row[$key] ?? $fallback));
}

function err(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="field-error">' . e($errors[$key]) . '</div>' : '';
}

$pageTitle = 'Register – ' . $steps[$step];
require __DIR__ . '/includes/header.php';
?>

<div class="wizard">
  <ol class="wizard-steps">
    <?php foreach ($steps as $n => $label):
        $state = $n < $step ? 'done' : ($n === $step ? 'current' : 'todo');
        $link  = $user && $n <= $maxStep && $n > 1 && $n !== $step; ?>
      <li class="<?= $state ?>">
        <?php if ($link): ?><a href="<?= e(url('register.php?step=' . $n)) ?>"><?php endif; ?>
        <span class="num"><?= $n < $step ? '&#10003;' : $n ?></span><span class="label"><?= e($label) ?></span>
        <?php if ($link): ?></a><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($errors): ?>
    <div class="alert alert-error">Please fix the highlighted fields below.</div>
  <?php endif; ?>

<?php if ($step === 1): ?>
  <form method="post" class="card" novalidate>
    <?= csrf_field() ?>
    <h2>Create your player account</h2>
    <p class="muted">Registering a youth player? Enter the <strong>player's</strong> name and birthdate &mdash; you'll add parent/guardian details on the next step.</p>
    <div class="grid-2">
      <label>Player first name<input name="first_name" value="<?= old('first_name') ?>" required autocomplete="given-name"><?= err($errors, 'first_name') ?></label>
      <label>Player last name<input name="last_name" value="<?= old('last_name') ?>" required autocomplete="family-name"><?= err($errors, 'last_name') ?></label>
      <label>Email (used to log in)<input type="email" name="email" value="<?= old('email') ?>" required autocomplete="email"><?= err($errors, 'email') ?></label>
      <label>Phone <span class="opt">(optional)</span><input type="tel" name="phone" value="<?= old('phone') ?>" autocomplete="tel"></label>
      <label>Birthdate<input type="date" name="birthdate" value="<?= old('birthdate') ?>" max="<?= date('Y-m-d') ?>" required><?= err($errors, 'birthdate') ?></label>
      <div></div>
      <label>Password<input type="password" name="password" required autocomplete="new-password" minlength="8"><span class="hint">At least 8 characters with a letter and a number.</span><?= err($errors, 'password') ?></label>
      <label>Confirm password<input type="password" name="password_confirm" required autocomplete="new-password"><?= err($errors, 'password_confirm') ?></label>
    </div>
    <div class="form-actions">
      <span class="muted">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></span>
      <button class="btn btn-primary">Continue &rarr;</button>
    </div>
  </form>

<?php elseif ($step === 2):
    $age = age_from_birthdate($user['birthdate']);
    $adultVal = is_post() ? ($_POST['is_adult'] ?? '') : (string)($player['is_adult'] ?? ($age >= 18 ? '1' : '0'));
    $levelsByCat = [];
    foreach (levels() as $l) { $levelsByCat[$l['category']][] = $l; }
?>
  <form method="post" class="card" novalidate data-adult-form>
    <?= csrf_field() ?>
    <h2>Player information</h2>
    <p class="muted">Player: <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong> &middot; Born <?= e(fmt_date($user['birthdate'])) ?> (age <?= (int)$age ?>)</p>

    <fieldset>
      <legend>Is the player 18 years of age or older?</legend>
      <div class="choice-row">
        <label class="choice"><input type="radio" name="is_adult" value="1"<?= checked($adultVal === '1') ?>><span>Yes, I'm an adult (18+)</span></label>
        <label class="choice"><input type="radio" name="is_adult" value="0"<?= checked($adultVal === '0') ?>><span>No, the player is under 18</span></label>
      </div>
      <?= err($errors, 'is_adult') ?>
    </fieldset>

    <fieldset class="parent-fields" data-parent-fields<?= $adultVal === '1' ? ' hidden' : '' ?>>
      <legend>Parent / guardian</legend>
      <div class="grid-2">
        <label>First name<input name="parent_first_name" value="<?= fv('parent_first_name', $player) ?>"><?= err($errors, 'parent_first_name') ?></label>
        <label>Last name<input name="parent_last_name" value="<?= fv('parent_last_name', $player) ?>"><?= err($errors, 'parent_last_name') ?></label>
        <label>Relationship to player
          <select name="parent_relationship">
            <option value="">Select…</option>
            <?php $rel = is_post() ? ($_POST['parent_relationship'] ?? '') : ($player['parent_relationship'] ?? '');
            foreach (['Mother', 'Father', 'Legal Guardian', 'Other'] as $r): ?>
              <option<?= selected($rel, $r) ?>><?= $r ?></option>
            <?php endforeach; ?>
          </select><?= err($errors, 'parent_relationship') ?>
        </label>
        <div></div>
        <label>Email<input type="email" name="parent_email" value="<?= fv('parent_email', $player) ?>"><?= err($errors, 'parent_email') ?></label>
        <label>Phone<input type="tel" name="parent_phone" value="<?= fv('parent_phone', $player) ?>"><?= err($errors, 'parent_phone') ?></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Last level of hockey played</legend>
      <div class="grid-2">
        <label class="span-2">Tier / level
          <select name="level_id" required>
            <option value="">Select the highest level you played last season…</option>
            <?php $lv = is_post() ? ($_POST['level_id'] ?? '') : ($player['level_id'] ?? '');
            foreach ($levelsByCat as $cat => $items): ?>
              <optgroup label="<?= e($cat) ?>">
                <?php foreach ($items as $l): ?>
                  <option value="<?= (int)$l['id'] ?>"<?= selected($lv, $l['id']) ?>><?= e($l['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select><?= err($errors, 'level_id') ?>
        </label>
        <label>League name<input name="league_name" value="<?= fv('league_name', $player) ?>" placeholder="e.g. OHL, BCHL, GTHL U16 AAA"><?= err($errors, 'league_name') ?></label>
        <label>Team <span class="opt">(optional)</span><input name="team_name" value="<?= fv('team_name', $player) ?>" placeholder="e.g. Toronto Marlboros"></label>
        <label>Season
          <select name="last_season">
            <?php $ls = is_post() ? ($_POST['last_season'] ?? '') : ($player['last_season'] ?? '');
            foreach (season_options() as $s): ?>
              <option<?= selected($ls, $s) ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select><?= err($errors, 'last_season') ?>
        </label>
        <div class="grid-2 tight">
          <label>Position
            <select name="position">
              <option value="">Select…</option>
              <?php $pos = is_post() ? ($_POST['position'] ?? '') : ($player['position'] ?? '');
              foreach (['Forward', 'Defence', 'Goalie'] as $p): ?><option<?= selected($pos, $p) ?>><?= $p ?></option><?php endforeach; ?>
            </select><?= err($errors, 'position') ?>
          </label>
          <label>Shoots / catches
            <select name="shoots">
              <option value="">Select…</option>
              <?php $sh = is_post() ? ($_POST['shoots'] ?? '') : ($player['shoots'] ?? ''); ?>
              <option value="L"<?= selected($sh, 'L') ?>>Left</option>
              <option value="R"<?= selected($sh, 'R') ?>>Right</option>
            </select><?= err($errors, 'shoots') ?>
          </label>
        </div>
      </div>
    </fieldset>

    <div class="form-actions">
      <span></span>
      <button class="btn btn-primary">Continue &rarr;</button>
    </div>
  </form>

<?php elseif ($step === 3):
    $src = is_post() ? ($_POST['profile_source'] ?? '') : ($player['profile_source'] ?? '');
?>
  <form method="post" class="card" novalidate data-profile-form>
    <?= csrf_field() ?>
    <h2>Player profile</h2>
    <p class="muted">Our staff use your public player profile to confirm your level of play before camp groups are finalized.</p>

    <fieldset>
      <legend>Where can we find your player stats?</legend>
      <div class="choice-grid">
        <label class="choice-card"><input type="radio" name="profile_source" value="eliteprospects"<?= checked($src === 'eliteprospects') ?>>
          <span><strong>Elite Prospects</strong><small>eliteprospects.com/player/…</small></span></label>
        <label class="choice-card"><input type="radio" name="profile_source" value="league"<?= checked($src === 'league') ?>>
          <span><strong>League player page</strong><small>OHL, USHL, BCHL, GTHL, HockeyTech, GameSheet…</small></span></label>
        <label class="choice-card"><input type="radio" name="profile_source" value="other"<?= checked($src === 'other') ?>>
          <span><strong>Other link</strong><small>Team site, MaxPreps, NCAA roster…</small></span></label>
        <label class="choice-card"><input type="radio" name="profile_source" value="none"<?= checked($src === 'none') ?>>
          <span><strong>No online profile</strong><small>House league, rec, new player…</small></span></label>
      </div>
      <?= err($errors, 'profile_source') ?>
    </fieldset>

    <div data-url-field<?= $src === 'none' ? ' hidden' : '' ?>>
      <label>Profile web address
        <input type="url" name="profile_url" value="<?= fv('profile_url', $player) ?>" placeholder="https://www.eliteprospects.com/player/123456/first-last">
        <span class="hint">Open your profile in a browser and copy the address bar.</span>
        <?= err($errors, 'profile_url') ?>
      </label>
    </div>
    <div data-reason-field<?= $src === 'none' ? '' : ' hidden' ?>>
      <label>Tell us about your last team
        <textarea name="no_profile_reason" rows="3" placeholder="Team, league, coach name and contact so we can confirm your level"><?= fv('no_profile_reason', $player) ?></textarea>
        <?= err($errors, 'no_profile_reason') ?>
      </label>
    </div>

    <div class="form-actions">
      <a class="btn btn-ghost" href="<?= e(url('register.php?step=2')) ?>">&larr; Back</a>
      <button class="btn btn-primary">Continue &rarr;</button>
    </div>
  </form>

<?php elseif ($step === 4):
    $minor    = !(int)($player['is_adult'] ?? 0);
    $expected = $minor ? trim(($player['parent_first_name'] ?? '') . ' ' . ($player['parent_last_name'] ?? '')) : $user['first_name'] . ' ' . $user['last_name'];
    $signed   = latest_waiver((int)$user['id']);
    $current  = $signed && (int)$signed['waiver_version'] >= waiver_version();
?>
  <form method="post" class="card waiver-card" novalidate>
    <?= csrf_field() ?>
    <h2>Waiver &amp; release</h2>
    <?php if ($current): ?>
      <div class="alert alert-success">Signed by <strong><?= e($signed['signer_name']) ?></strong> on <?= e(fmt_date($signed['signed_at'], 'M j, Y \a\t g:i a')) ?>. You're all set &mdash; <a href="<?= e(url('register.php?step=5')) ?>">continue to camp selection</a>.</div>
    <?php elseif ($signed): ?>
      <div class="alert alert-info">The waiver has been updated since you last signed it. Please review and sign the new version.</div>
    <?php endif; ?>
    <p class="muted">Please read the full waiver below<?= $minor ? '. Because the player is under 18, it must be signed by the <strong>parent or legal guardian</strong>.' : '.' ?></p>
    <div class="waiver-text" tabindex="0" aria-label="Waiver text"><?= nl2br(e(setting('waiver_text'))) ?></div>

    <?php if (!$current): ?>
    <div class="waiver-sign">
      <dl class="details">
        <dt>Participant</dt><dd><?= e($user['first_name'] . ' ' . $user['last_name']) ?> (born <?= e(fmt_date($user['birthdate'])) ?>)</dd>
        <dt>Signed by</dt><dd><?= $minor ? e($expected) . ' &middot; ' . e($player['parent_relationship'] ?: 'Parent/Guardian') : 'Participant (adult)' ?></dd>
        <dt>Date</dt><dd><?= e(date('M j, Y')) ?></dd>
      </dl>
      <label class="checkbox"><input type="checkbox" name="agree" value="1"<?= checked(!empty($_POST['agree'])) ?>>
        <span>I have read and understand this waiver and agree to its terms<?= $minor ? ' on behalf of the participant, as their parent/legal guardian' : '' ?>.</span></label>
      <?= err($errors, 'agree') ?>
      <label>Type your full name to sign<?= $minor ? ' (parent/guardian)' : '' ?>
        <input name="signer_name" class="signature" value="<?= old('signer_name') ?>" placeholder="<?= e($expected) ?>" autocomplete="name">
        <span class="hint">Typing your name here is your electronic signature.</span>
        <?= err($errors, 'signer_name') ?>
      </label>
    </div>
    <?php endif; ?>

    <div class="form-actions">
      <a class="btn btn-ghost" href="<?= e(url('register.php?step=3')) ?>">&larr; Back</a>
      <?php if ($current): ?>
        <a class="btn btn-primary" href="<?= e(url('register.php?step=5')) ?>">Continue &rarr;</a>
      <?php else: ?>
        <button class="btn btn-primary">Sign &amp; continue &rarr;</button>
      <?php endif; ?>
    </div>
  </form>

<?php elseif ($step === 5 && $rejected): ?>
  <div class="card">
    <h2>Registration not accepted</h2>
    <p>After reviewing the player profile, our staff were unable to accept this registration, so new camp registrations are not available on this account.</p>
    <?php if (!empty($player['verification_note'])): ?><p class="muted"><?= e($player['verification_note']) ?></p><?php endif; ?>
    <p>If you have more information about the player's level of play, please contact us at <a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a>.</p>
    <a class="btn btn-ghost" href="<?= e(url('account.php')) ?>">Go to My Account</a>
  </div>

<?php elseif ($step === 5):
    $camps   = camps_with_counts();
    $already = user_camp_ids((int)$user['id']);
    $picked  = is_post() ? array_map('intval', (array)($_POST['camps'] ?? [])) : ($_SESSION['cart'] ?? []);
    $eligible = array_values(array_filter($camps, fn($c) => camp_block_reason($c, $user['birthdate'], $already, $playerRank) === null));
    $eligibleTotal = array_sum(array_map(fn($c) => (float)$c['price'], $eligible));
    $stmt = db()->prepare('SELECT name FROM levels WHERE id = ?');
    $stmt->execute([$player['level_id'] ?? 0]);
    $levelName = $stmt->fetchColumn();
?>
  <form method="post" class="card" data-camp-form data-currency="<?= e(setting('currency_symbol', '$')) ?>">
    <?= csrf_field() ?>
    <h2>Select your camps</h2>
    <p class="muted">Choose as many camps as you like. Camps outside your age group or level of play<?= $levelName ? ' (<strong>' . e($levelName) . '</strong>)' : '' ?>, full, or already registered are greyed out.</p>
    <?= err($errors, 'camps') ?>

    <?php if (count($eligible) > 1): ?>
      <label class="all-camps">
        <input type="checkbox" data-select-all<?= checked(count(array_intersect(array_column($eligible, 'id'), $picked)) === count($eligible)) ?>>
        <span><strong>All Camps</strong> &mdash; select every camp you're eligible for
          <small><?= count($eligible) ?> camps &middot; <?= money($eligibleTotal) ?></small></span>
      </label>
    <?php endif; ?>

    <?php if (!$camps): ?>
      <p>No camps are open for registration right now. Please check back soon.</p>
    <?php endif; ?>

    <div class="camp-grid">
      <?php foreach ($camps as $c):
          $why = camp_block_reason($c, $user['birthdate'], $already, $playerRank);
          $left = $c['capacity'] !== null ? max(0, (int)$c['capacity'] - (int)$c['registered']) : null; ?>
        <label class="camp-option<?= $why ? ' disabled' : '' ?>">
          <input type="checkbox" name="camps[]" value="<?= (int)$c['id'] ?>" data-price="<?= e($c['price']) ?>"
                 <?= $why ? 'disabled' : '' ?><?= checked(!$why && in_array((int)$c['id'], $picked, true)) ?>>
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
              <?php if (camp_level_label($c) && !$why): ?><span class="tag"><?= e(camp_level_label($c)) ?></span><?php endif; ?>
              <?php if ($why): ?><span class="tag tag-warn"><?= e($why) ?></span>
              <?php elseif ($left !== null && $left <= 5): ?><span class="tag tag-hot"><?= $left ?> spot<?= $left === 1 ? '' : 's' ?> left</span><?php endif; ?>
            </span>
            <span class="camp-check" aria-hidden="true"></span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="total-bar">
      <div><span data-count>0</span> camp(s) selected</div>
      <div class="total">Total <strong data-total><?= money(0) ?></strong></div>
      <button class="btn btn-primary">Continue to checkout &rarr;</button>
    </div>
    <div class="form-actions">
      <a class="btn btn-ghost" href="<?= e(url('register.php?step=4')) ?>">&larr; Back</a>
    </div>
  </form>

<?php elseif ($step === 6):
    $cart = $_SESSION['cart'] ?? [];
    if (!$cart) {
        echo '<div class="card"><p>Your cart is empty.</p><a class="btn btn-primary" href="' . e(url('register.php?step=5')) . '">Choose camps</a></div>';
    } else {
        $in = implode(',', array_fill(0, count($cart), '?'));
        $stmt = db()->prepare("SELECT * FROM camps WHERE id IN ($in) ORDER BY sort_order, start_date");
        $stmt->execute($cart);
        $items = $stmt->fetchAll();
        $total = array_sum(array_map(fn($c) => (float)$c['price'], $items));
        $defaultBilling = $player['parent_first_name'] ? $player['parent_first_name'] . ' ' . $player['parent_last_name'] : $user['first_name'] . ' ' . $user['last_name'];
?>
  <form method="post" class="checkout" novalidate>
    <?= csrf_field() ?>
    <div class="card">
      <h2>Order summary</h2>
      <table class="table">
        <thead><tr><th>Camp</th><th>Dates</th><th class="num">Price</th></tr></thead>
        <tbody>
        <?php foreach ($items as $c): ?>
          <tr><td><?= e($c['name']) ?></td><td><?= e(date_range($c['start_date'], $c['end_date'])) ?></td><td class="num"><?= money($c['price']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="2">Total (<?= e(setting('currency_code', 'CAD')) ?>)</th><th class="num"><?= money($total) ?></th></tr></tfoot>
      </table>
      <p><a href="<?= e(url('register.php?step=5')) ?>">Change selection</a></p>
      <h3>Player</h3>
      <p><?= e($user['first_name'] . ' ' . $user['last_name']) ?> &middot; born <?= e(fmt_date($user['birthdate'])) ?><br>
        <?php if (!$player['is_adult']): ?>Parent/guardian: <?= e($player['parent_first_name'] . ' ' . $player['parent_last_name']) ?> (<?= e($player['parent_email']) ?>)<?php endif; ?></p>
    </div>

    <div class="card">
      <h2>Payment</h2>
      <?php if (!empty($GLOBALS['config']['demo_mode'])): ?>
        <div class="alert alert-info"><strong>Demo mode.</strong> No card is charged. The order is recorded as paid so you can test the full flow.</div>
      <?php endif; ?>
      <label>Name of person paying<input name="billing_name" value="<?= e(is_post() ? ($_POST['billing_name'] ?? '') : $defaultBilling) ?>"><?= err($errors, 'billing_name') ?></label>
      <div class="demo-card" aria-hidden="true">
        <label>Card number<input value="4242 4242 4242 4242" disabled></label>
        <div class="grid-2 tight">
          <label>Expiry<input value="12 / 30" disabled></label>
          <label>CVC<input value="123" disabled></label>
        </div>
      </div>
      <?php $w = latest_waiver((int)$user['id']); ?>
      <p class="waiver-ok">&#10003; Waiver signed by <?= e($w['signer_name']) ?> on <?= e(fmt_date($w['signed_at'])) ?></p>
      <button class="btn btn-primary btn-block">Complete registration &middot; <?= money($total) ?></button>
    </div>
  </form>
<?php } endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
