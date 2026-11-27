<?php
/**
 * Database upgrades. Each version's statements run once; the current version
 * is stored in settings.schema_version. Used by the installer and, for
 * existing installs, automatically on the first page load after an update.
 */
declare(strict_types=1);

const SCHEMA_VERSION = 3;

function run_migrations(PDO $pdo): void
{
    $current = (int)($pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'")->fetchColumn() ?: 1);

    if ($current < 2) {
        // ---- v2: password resets, email templates, email log ---------------
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NOT NULL,
            token_hash  CHAR(64)     NOT NULL,
            expires_at  DATETIME     NOT NULL,
            used_at     DATETIME     NULL,
            ip          VARCHAR(45)  NULL,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reset_token (token_hash),
            KEY idx_reset_user (user_id, created_at),
            CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS email_templates (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            template_key  VARCHAR(50)  NULL COMMENT 'Set for built-in templates the system sends itself',
            name          VARCHAR(120) NOT NULL,
            subject       VARCHAR(255) NOT NULL,
            body          TEXT         NOT NULL,
            is_system     TINYINT(1)   NOT NULL DEFAULT 0,
            updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_template_key (template_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS email_log (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      INT UNSIGNED NULL,
            template_id  INT UNSIGNED NULL,
            to_email     VARCHAR(190) NOT NULL,
            cc_email     VARCHAR(190) NULL,
            subject      VARCHAR(255) NOT NULL,
            body         MEDIUMTEXT   NOT NULL,
            status       ENUM('sent','failed','logged') NOT NULL,
            error        TEXT         NULL,
            sent_by      INT UNSIGNED NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_email_user (user_id, created_at),
            CONSTRAINT fk_email_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_email_tpl  FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $ins = $pdo->prepare('INSERT IGNORE INTO email_templates (template_key, name, subject, body, is_system) VALUES (?, ?, ?, ?, 1)');
        foreach (default_email_templates() as $key => [$name, $subject, $body]) {
            $ins->execute([$key, $name, $subject, $body]);
        }
        $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('site_url', ?)")
            ->execute([function_exists('request_origin') && isset($_SERVER['HTTP_HOST']) ? rtrim(request_origin() . base_url(), '/') . '/' : '']);
    }

    if ($current < 3) {
        // ---- v3: waivers, minimum level per camp, waiver step in the wizard ----
        if (!migrate_column_exists($pdo, 'camps', 'min_level_id')) {
            $pdo->exec("ALTER TABLE camps ADD COLUMN min_level_id INT UNSIGNED NULL AFTER capacity,
                        ADD CONSTRAINT fk_camps_min_level FOREIGN KEY (min_level_id) REFERENCES levels(id) ON DELETE SET NULL");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS waivers (
            id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id             INT UNSIGNED NOT NULL,
            waiver_version      INT          NOT NULL,
            signer_name         VARCHAR(160) NOT NULL,
            signer_relationship VARCHAR(40)  NOT NULL,
            waiver_text         TEXT         NOT NULL,
            ip                  VARCHAR(45)  NULL,
            user_agent          VARCHAR(255) NULL,
            signed_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_waivers_user (user_id, signed_at),
            CONSTRAINT fk_waivers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('waiver_version', '1')");
        // The wizard gained a Waiver step (4); Camps moved to 5 and Checkout to 6.
        $pdo->exec('UPDATE players SET wizard_step = wizard_step + 1 WHERE wizard_step >= 5');
    }

    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([(string)SCHEMA_VERSION]);
    unset($GLOBALS['__settings']); // reload settings cache
}

/** Built-in templates: key => [name, subject, body]. Admins can edit the wording. */
function default_email_templates(): array
{
    return [
        'registration_accepted' => [
            'Registration accepted',
            "You're confirmed – {site_name}",
            "Hi {greeting_name},

Great news! {player_name}'s registration has been reviewed and accepted. Their level of play ({level}) has been verified.

Registered camps:
{camp_list}

Please arrive 30 minutes before the first session with full equipment and a water bottle.

You can view your registration any time here:
{login_link}

Questions? Just reply to this email or contact us at {contact_email}.

See you on the ice!
{site_name}",
        ],
        'registration_denied' => [
            'Registration denied',
            'Update on your {site_name} registration',
            "Hi {greeting_name},

Thank you for registering {player_name}. After reviewing the player profile, we are unable to accept the registration for the following camp(s) at this time:
{camp_list}

{verification_note}

Any payment received for these camps will be refunded in full.

If you believe this is a mistake, or you have more information about the player's level of play, please contact us at {contact_email}.

Thank you,
{site_name}",
        ],
        'password_reset' => [
            'Password reset',
            'Reset your {site_name} password',
            "Hi {first_name},

We received a request to reset the password for your account ({email}).

Reset your password using this link:
{reset_link}

The link expires in 60 minutes and can only be used once. If you didn't ask to reset your password, you can ignore this email and your password will not change.

{site_name}",
        ],
    ];
}

function migrate_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}
