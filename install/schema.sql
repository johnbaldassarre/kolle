-- Ty Kolle Hockey Development - Registration System schema (MySQL 5.7+/MariaDB 10.3+)

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role            ENUM('user','admin') NOT NULL DEFAULT 'user',
    first_name      VARCHAR(80)  NOT NULL,
    last_name       VARCHAR(80)  NOT NULL,
    email           VARCHAR(190) NOT NULL,
    phone           VARCHAR(40)  NULL,
    birthdate       DATE         NULL,
    password_hash   VARCHAR(255) NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at   DATETIME     NULL,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS levels (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    category    VARCHAR(60)  NOT NULL,
    rank_value  INT          NOT NULL DEFAULT 0 COMMENT 'Higher = higher level of play',
    keywords    TEXT         NULL COMMENT 'Comma separated league names/abbreviations used by the verifier',
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS players (
    user_id               INT UNSIGNED PRIMARY KEY,
    is_adult              TINYINT(1)   NULL,
    parent_first_name     VARCHAR(80)  NULL,
    parent_last_name      VARCHAR(80)  NULL,
    parent_relationship   VARCHAR(40)  NULL,
    parent_email          VARCHAR(190) NULL,
    parent_phone          VARCHAR(40)  NULL,
    level_id              INT UNSIGNED NULL,
    league_name           VARCHAR(150) NULL,
    team_name             VARCHAR(150) NULL,
    last_season           VARCHAR(20)  NULL,
    position              VARCHAR(20)  NULL,
    shoots                VARCHAR(5)   NULL,
    profile_url           VARCHAR(500) NULL,
    profile_source        VARCHAR(30)  NULL,
    no_profile_reason     TEXT         NULL,
    wizard_step           TINYINT      NOT NULL DEFAULT 2,
    verification_status   ENUM('pending','verified','flagged','rejected') NOT NULL DEFAULT 'pending',
    verification_note     TEXT         NULL,
    verified_by           INT UNSIGNED NULL,
    verified_at           DATETIME     NULL,
    auto_check_score      INT          NULL,
    auto_check_result     MEDIUMTEXT   NULL,
    auto_checked_at       DATETIME     NULL,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_players_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_players_level FOREIGN KEY (level_id) REFERENCES levels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camps (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150)  NOT NULL,
    description         TEXT          NULL,
    location            VARCHAR(200)  NULL,
    start_date          DATE          NULL,
    end_date            DATE          NULL,
    schedule            VARCHAR(255)  NULL,
    min_birth_year      SMALLINT      NULL,
    max_birth_year      SMALLINT      NULL,
    capacity            INT           NULL COMMENT 'NULL = unlimited',
    price               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    registration_opens  DATE          NULL,
    registration_closes DATE          NULL,
    is_active           TINYINT(1)    NOT NULL DEFAULT 1,
    sort_order          INT           NOT NULL DEFAULT 0,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number    VARCHAR(30)   NOT NULL,
    user_id         INT UNSIGNED  NOT NULL,
    total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status          ENUM('pending','paid','cancelled','refunded') NOT NULL DEFAULT 'pending',
    payment_method  VARCHAR(30)   NULL,
    payment_ref     VARCHAR(100)  NULL,
    billing_name    VARCHAR(160)  NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at         DATETIME      NULL,
    UNIQUE KEY uq_orders_number (order_number),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED  NOT NULL,
    user_id     INT UNSIGNED  NOT NULL,
    camp_id     INT UNSIGNED  NOT NULL,
    price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status      ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_reg_camp (camp_id, status),
    KEY idx_reg_user (user_id, status),
    CONSTRAINT fk_reg_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_reg_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_reg_camp  FOREIGN KEY (camp_id)  REFERENCES camps(id)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(60) PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
