-- Lets a registered application call /api/v1/* (create users, change passwords). Off by default.
-- Repeatable: only adds the column when it is missing.
SET @has := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'can_provision');
SET @sql := IF(@has = 0,
  'ALTER TABLE clients ADD COLUMN can_provision TINYINT NOT NULL DEFAULT 0 AFTER status',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
