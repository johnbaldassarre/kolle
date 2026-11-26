<?php
/**
 * Email: templates, placeholders, HTML rendering and delivery.
 *
 * Delivery is configured in config.php under 'mail':
 *   'transport' => 'mail'  PHP's built-in mail() (most shared hosts)
 *                  'smtp'  your mail server / Gmail / Microsoft 365 / SendGrid etc.
 *                  'log'   don't send; just record in Admin > Emails > Sent log (demo)
 * Every email, whatever the transport, is recorded in the email_log table.
 */
declare(strict_types=1);

/** Placeholders admins can use in templates, with a description of each. */
function email_placeholders(): array
{
    return [
        '{first_name}'        => "Player's first name",
        '{last_name}'         => "Player's last name",
        '{player_name}'       => "Player's full name",
        '{greeting_name}'     => 'Parent/guardian first name for minors, otherwise the player',
        '{parent_name}'       => 'Parent/guardian full name (blank for adults)',
        '{email}'             => 'Account email',
        '{level}'             => 'Level of play (confirmed level if changed by admin)',
        '{league}'            => 'League the player entered',
        '{team}'              => 'Team the player entered',
        '{camp_list}'         => "Bulleted list of the player's camps with dates",
        '{camp_name}'         => 'Camp name (when emailing a camp roster)',
        '{camp_dates}'        => 'Camp dates (when emailing a camp roster)',
        '{camp_location}'     => 'Camp location (when emailing a camp roster)',
        '{camp_schedule}'     => 'Camp schedule (when emailing a camp roster)',
        '{order_numbers}'     => "Player's order number(s)",
        '{total_paid}'        => 'Total paid across all camps',
        '{verification_note}' => "Admin's verification note",
        '{login_link}'        => 'Link to log in / My Account',
        '{reset_link}'        => 'Password reset link (password reset email only)',
        '{site_name}'         => 'Organization name',
        '{contact_email}'     => 'Contact email',
    ];
}

function email_template(string $key): ?array
{
    $stmt = db()->prepare('SELECT * FROM email_templates WHERE template_key = ?');
    $stmt->execute([$key]);
    return $stmt->fetch() ?: null;
}

/** Load the user + player + registrations needed to fill placeholders. */
function email_recipient(int $userId): ?array
{
    $stmt = db()->prepare('SELECT u.*, p.is_adult, p.parent_first_name, p.parent_last_name, p.parent_email, p.league_name, p.team_name,
                                  p.verification_note, l.name AS level_name
                           FROM users u LEFT JOIN players p ON p.user_id = u.id LEFT JOIN levels l ON l.id = p.level_id
                           WHERE u.id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

/** Build the placeholder => value map for one recipient. $extra overrides/adds values. */
function email_vars(array $r, array $extra = []): array
{
    $stmt = db()->prepare('SELECT c.name, c.start_date, c.end_date, c.schedule, c.location, o.order_number, r.price
                           FROM registrations r JOIN camps c ON c.id = r.camp_id JOIN orders o ON o.id = r.order_id
                           WHERE r.user_id = ? AND r.status = "active" ORDER BY c.start_date');
    $stmt->execute([$r['id']]);
    $regs = $stmt->fetchAll();
    $list = array_map(fn($c) => '• ' . $c['name'] . ' – ' . date_range($c['start_date'], $c['end_date'])
        . ($c['schedule'] ? ', ' . $c['schedule'] : '') . ($c['location'] ? ' (' . $c['location'] . ')' : ''), $regs);

    $minor  = isset($r['is_adult']) && $r['is_adult'] !== null ? !$r['is_adult'] : (age_from_birthdate($r['birthdate'] ?? null) ?? 99) < 18;
    $parent = trim(($r['parent_first_name'] ?? '') . ' ' . ($r['parent_last_name'] ?? ''));

    return array_merge([
        '{first_name}'        => $r['first_name'],
        '{last_name}'         => $r['last_name'],
        '{player_name}'       => $r['first_name'] . ' ' . $r['last_name'],
        '{greeting_name}'     => $minor && !empty($r['parent_first_name']) ? $r['parent_first_name'] : $r['first_name'],
        '{parent_name}'       => $parent,
        '{email}'             => $r['email'],
        '{level}'             => $r['level_name'] ?? '',
        '{league}'            => $r['league_name'] ?? '',
        '{team}'              => $r['team_name'] ?? '',
        '{camp_list}'         => $list ? implode("\n", $list) : '(no camps registered)',
        '{camp_name}'         => '',
        '{camp_dates}'        => '',
        '{camp_location}'     => '',
        '{camp_schedule}'     => '',
        '{order_numbers}'     => implode(', ', array_unique(array_column($regs, 'order_number'))),
        '{total_paid}'        => money(array_sum(array_map('floatval', array_column($regs, 'price')))),
        '{verification_note}' => $r['verification_note'] ?? '',
        '{login_link}'        => absolute_url('login.php'),
        '{reset_link}'        => '',
        '{site_name}'         => site_name(),
        '{contact_email}'     => setting('contact_email'),
    ], $extra);
}

function email_fill(string $text, array $vars): string
{
    $text = strtr($text, $vars);
    $text = preg_replace("/[ \t]+\n/", "\n", $text);
    return trim(preg_replace("/\n{3,}/", "\n\n", $text)); // collapse gaps left by empty placeholders
}

/** Wrap a plain-text body in the branded HTML layout. */
function email_html(string $subject, string $text): string
{
    $body = e($text);
    // Make links clickable
    $body = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#c8102e">$1</a>', $body);
    $body = nl2br($body);
    $name = e(site_name());
    $logo = setting('logo_url');
    $brand = $logo && preg_match('#^https?://#', $logo)
        ? '<img src="' . e($logo) . '" alt="' . $name . '" style="max-height:48px">'
        : '<span style="font-family:Oswald,Arial Narrow,Arial,sans-serif;font-size:20px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:#ffffff">' . $name . '</span>';
    $contact = e(setting('contact_email'));
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . e($subject) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f3f5f8">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f8;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:6px;overflow:hidden;border:1px solid #dde3ea">'
        . '<tr><td style="background:#0d1117;padding:18px 24px;border-bottom:3px solid #c8102e">' . $brand . '</td></tr>'
        . '<tr><td style="padding:28px 24px;font-family:Open Sans,Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1b1f24">' . $body . '</td></tr>'
        . '<tr><td style="padding:16px 24px;background:#f7f9fb;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#5f6b7a">'
        . $name . ($contact ? ' &middot; <a href="mailto:' . $contact . '" style="color:#5f6b7a">' . $contact . '</a>' : '') . '</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Send a template to a user. Returns ['ok' => bool, 'status' => sent|failed|logged, 'error' => ?string].
 * $opts: cc_parent (bool), extra vars (array), sent_by (admin id), subject/body overrides (string).
 */
function email_send_to_user(int $userId, array $template, array $opts = []): array
{
    $r = email_recipient($userId);
    if (!$r) {
        return ['ok' => false, 'status' => 'failed', 'error' => 'User not found'];
    }
    $vars    = email_vars($r, $opts['extra'] ?? []);
    $subject = email_fill($opts['subject'] ?? $template['subject'], $vars);
    $text    = email_fill($opts['body'] ?? $template['body'], $vars);
    $cc      = null;
    if (!empty($opts['cc_parent']) && !empty($r['parent_email']) && strcasecmp($r['parent_email'], $r['email']) !== 0) {
        $cc = $r['parent_email'];
    }
    $result = mail_deliver($r['email'], trim($r['first_name'] . ' ' . $r['last_name']), $cc, $subject, $text);

    db()->prepare('INSERT INTO email_log (user_id, template_id, to_email, cc_email, subject, body, status, error, sent_by) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$userId, $template['id'] ?? null, $r['email'], $cc, mb_substr($subject, 0, 255), $text, $result['status'], $result['error'], $opts['sent_by'] ?? null]);
    return $result;
}

// ---------------------------------------------------------------------------
// Delivery
// ---------------------------------------------------------------------------

function mail_config(): array
{
    $c = $GLOBALS['config']['mail'] ?? [];
    return array_merge([
        'transport'   => 'mail',
        'from_email'  => '',
        'from_name'   => '',
        'smtp_host'   => '',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',
        'smtp_user'   => '',
        'smtp_pass'   => '',
    ], $c);
}

function mail_clean_header(string $v): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $v));
}

function mail_encode_header(string $v): string
{
    $v = mail_clean_header($v);
    return preg_match('/[^\x20-\x7E]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
}

function mail_address(string $email, string $name = ''): string
{
    $email = mail_clean_header($email);
    if ($name === '') {
        return $email;
    }
    $name = mail_clean_header($name);
    return (preg_match('/[^\x20-\x7E]/', $name) ? mail_encode_header($name) : '"' . addcslashes($name, '"\\') . '"') . ' <' . $email . '>';
}

/** Deliver one message. Returns ['ok', 'status', 'error']. */
function mail_deliver(string $to, string $toName, ?string $cc, string $subject, string $text): array
{
    $cfg = mail_config();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || ($cc !== null && !filter_var($cc, FILTER_VALIDATE_EMAIL))) {
        return ['ok' => false, 'status' => 'failed', 'error' => 'Invalid email address'];
    }
    if ($cfg['transport'] === 'log') {
        return ['ok' => true, 'status' => 'logged', 'error' => null];
    }

    $fromEmail = $cfg['from_email'] ?: (setting('contact_email') ?: 'no-reply@' . preg_replace('/^www\./', '', parse_url(absolute_url(), PHP_URL_HOST) ?: 'localhost'));
    $fromName  = $cfg['from_name'] ?: site_name();
    $replyTo   = setting('contact_email');
    $boundary  = 'b' . bin2hex(random_bytes(12));
    $html      = email_html($subject, $text);
    $domain    = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);

    $headers = [
        'From: ' . mail_address($fromEmail, $fromName),
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    if ($cc) {
        $headers[] = 'Cc: ' . mail_address($cc);
    }
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL) && strcasecmp($replyTo, $fromEmail) !== 0) {
        $headers[] = 'Reply-To: ' . mail_address($replyTo);
    }
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text))
          . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html))
          . "--$boundary--\r\n";

    try {
        if ($cfg['transport'] === 'smtp') {
            $rcpts = array_filter([$to, $cc]);
            $msg = 'To: ' . mail_address($to, $toName) . "\r\nSubject: " . mail_encode_header($subject) . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;
            smtp_send($cfg, $fromEmail, $rcpts, $msg);
        } else {
            $ok = @mail(mail_address($to, $toName), mail_encode_header($subject), $body, implode("\r\n", $headers));
            if (!$ok) {
                throw new RuntimeException('PHP mail() failed. Your host may require SMTP – see config.php.');
            }
        }
        return ['ok' => true, 'status' => 'sent', 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}

/** Minimal SMTP client (STARTTLS or SSL, AUTH LOGIN). Throws on failure. */
function smtp_send(array $cfg, string $from, array $rcpts, string $message): void
{
    $host   = $cfg['smtp_host'];
    $port   = (int)$cfg['smtp_port'];
    $secure = strtolower((string)$cfg['smtp_secure']);
    if ($host === '') {
        throw new RuntimeException('SMTP host is not set in config.php.');
    }
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Could not connect to SMTP server $host:$port ($errstr).");
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int)substr($data, 0, 3), trim($data)];
    };
    $cmd = function (string $c, array $expect, bool $secret = false) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        [$code, $resp] = $read();
        if (!in_array($code, $expect, true)) {
            $shown = $secret ? '(login credentials)' : strtok($c, "\r\n");
            throw new RuntimeException("SMTP error after $shown: $resp");
        }
        return $resp;
    };

    try {
        [$code, $resp] = $read();
        if ($code !== 220) {
            throw new RuntimeException('SMTP server not ready: ' . $resp);
        }
        $ehloHost = parse_url(absolute_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd('EHLO ' . $ehloHost, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('Could not start TLS encryption with the SMTP server.');
            }
            $cmd('EHLO ' . $ehloHost, [250]);
        }
        if ($cfg['smtp_user'] !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($cfg['smtp_user']), [334], true);
            $cmd(base64_encode($cfg['smtp_pass']), [235], true);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        foreach ($rcpts as $r) {
            $cmd('RCPT TO:<' . $r . '>', [250, 251]);
        }
        $cmd('DATA', [354]);
        $message = preg_replace('/(?<!\r)\n/', "\r\n", $message);
        $message = preg_replace('/^\./m', '..', $message); // dot-stuffing
        $cmd($message . "\r\n.", [250], true);
        $cmd('QUIT', [221]);
    } finally {
        fclose($fp);
    }
}

/** Example values used for template previews. */
function email_sample_vars(): array
{
    return [
        '{first_name}' => 'Connor', '{last_name}' => 'Smith', '{player_name}' => 'Connor Smith', '{greeting_name}' => 'Tracy',
        '{parent_name}' => 'Tracy Smith', '{email}' => 'connor@example.com', '{level}' => 'U15 AAA', '{league}' => 'GTHL U15 AAA',
        '{team}' => 'Toronto Marlboros',
        '{camp_list}' => "• Elite Skills Camp – Jul 6–10, 2027, Mon–Fri · 9:00 AM – 12:00 PM (Main Arena – Rink A)\n• Power Skating Clinic – Jul 19–21, 2027 (Community Rink)",
        '{camp_name}' => 'Elite Skills Camp', '{camp_dates}' => 'Jul 6–10, 2027', '{camp_location}' => 'Main Arena – Rink A',
        '{camp_schedule}' => 'Mon–Fri · 9:00 AM – 12:00 PM', '{order_numbers}' => 'TK-270115-A1B2C3', '{total_paid}' => money(720),
        '{verification_note}' => 'Note from our staff: we could not find U15 AAA stats on the profile provided.',
        '{login_link}' => absolute_url('login.php'), '{reset_link}' => absolute_url('reset_password.php?token=example'),
        '{site_name}' => site_name(), '{contact_email}' => setting('contact_email'),
    ];
}

/** Extra placeholders describing one camp, for emails sent to a camp roster. */
function email_camp_vars(array $camp): array
{
    return [
        '{camp_name}'     => $camp['name'],
        '{camp_dates}'    => date_range($camp['start_date'], $camp['end_date']),
        '{camp_location}' => (string)$camp['location'],
        '{camp_schedule}' => (string)$camp['schedule'],
    ];
}

function email_status_badge(string $status): string
{
    $map = ['sent' => ['verified', 'Sent'], 'failed' => ['rejected', 'Failed'], 'logged' => ['pending', 'Logged only']];
    [$cls, $label] = $map[$status] ?? ['pending', $status];
    return '<span class="badge badge-' . $cls . '">' . e($label) . '</span>';
}
