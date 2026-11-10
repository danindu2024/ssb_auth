-- SSB central login (identity provider). MySQL 5.7+ / MariaDB 10.3+. All timestamps are UTC.
CREATE TABLE IF NOT EXISTS users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id             VARCHAR(64)  NOT NULL,              -- employee no. / username, case-insensitive unique
  full_name            VARCHAR(150) NOT NULL,
  email                VARCHAR(190) NULL,
  password_hash        VARCHAR(255) NOT NULL,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  is_admin             TINYINT(1)   NOT NULL DEFAULT 0,    -- may use /admin of THIS service only
  status               ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at        DATETIME NULL,
  password_changed_at  DATETIME NULL,
  created_at           DATETIME NOT NULL,
  updated_at           DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_login (login_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registered applications (OAuth clients). Roles are NOT stored here: each app keeps its own.
CREATE TABLE IF NOT EXISTS clients (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id     VARCHAR(64)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  secret_hash   CHAR(64)     NOT NULL,                    -- sha256 of a 256-bit random secret
  redirect_uris TEXT         NOT NULL,                    -- one exact URI per line
  logout_uris   TEXT         NOT NULL,                    -- one exact URI per line (allowed post-logout targets)
  launch_url    VARCHAR(255) NULL,                        -- tile link shown in /apps
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  can_provision TINYINT      NOT NULL DEFAULT 0,          -- 1 = may call /api/v1/* (create users, change passwords)
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_cid (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which users may sign in to which app (the app itself still decides the role).
CREATE TABLE IF NOT EXISTS user_apps (
  user_id   INT UNSIGNED NOT NULL,
  client_pk INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, client_pk),
  CONSTRAINT fk_ua_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_ua_client FOREIGN KEY (client_pk) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time authorization codes (only the sha256 is stored).
CREATE TABLE IF NOT EXISTS auth_codes (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code_hash      CHAR(64)     NOT NULL,
  client_pk      INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  redirect_uri   VARCHAR(500) NOT NULL,
  code_challenge CHAR(43)     NOT NULL,                   -- PKCE S256
  auth_time      DATETIME     NOT NULL,
  expires_at     DATETIME     NOT NULL,
  used_at        DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_codes_hash (code_hash),
  KEY ix_codes_exp (expires_at),
  CONSTRAINT fk_ac_client FOREIGN KEY (client_pk) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ac_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login_id   VARCHAR(64) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  created_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_la_login (login_id, created_at),
  KEY ix_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;   -- failures only

CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  event      VARCHAR(40)  NOT NULL,
  user_id    INT UNSIGNED NULL,
  login_id   VARCHAR(64)  NULL,
  client_id  VARCHAR(64)  NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(255) NULL,
  detail     VARCHAR(500) NULL,
  PRIMARY KEY (id),
  KEY ix_audit_time (created_at),
  KEY ix_audit_login (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
