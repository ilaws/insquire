-- Back up the database before applying.
-- Existing accounts are retained as Education accounts. This preserves every
-- existing student/teacher/admin classification, including students with no
-- class membership yet. Personal accounts created after this migration use a
-- separate account_scope and may share an email with an Education account.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS account_scope ENUM('personal','education') NOT NULL DEFAULT 'education';

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) NULL;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email') > 0,
    'ALTER TABLE users DROP INDEX uq_users_email',
    'SELECT 1'
);
PREPARE account_separation_stmt FROM @ddl;
EXECUTE account_separation_stmt;
DEALLOCATE PREPARE account_separation_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email_scope') = 0,
    'CREATE UNIQUE INDEX uq_users_email_scope ON users (email, account_scope)',
    'SELECT 1'
);
PREPARE account_separation_stmt FROM @ddl;
EXECUTE account_separation_stmt;
DEALLOCATE PREPARE account_separation_stmt;

SET @google_id_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_id'
);
SET @google_unique_index = (
    SELECT INDEX_NAME FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'google_id' AND NON_UNIQUE = 0
      AND INDEX_NAME <> 'PRIMARY'
    GROUP BY INDEX_NAME
    HAVING COUNT(*) = 1
    ORDER BY INDEX_NAME
    LIMIT 1
);
SET @ddl = IF(
    @google_id_exists > 0 AND @google_unique_index IS NOT NULL,
    CONCAT('ALTER TABLE users DROP INDEX `', REPLACE(@google_unique_index, '`', '``'), '`'),
    'SELECT 1'
);
PREPARE account_separation_stmt FROM @ddl;
EXECUTE account_separation_stmt;
DEALLOCATE PREPARE account_separation_stmt;

SET @ddl = IF(
    @google_id_exists > 0 AND
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
       AND INDEX_NAME = 'uq_users_google_scope') = 0,
    'CREATE UNIQUE INDEX uq_users_google_scope ON users (google_id, account_scope)',
    'SELECT 1'
);
PREPARE account_separation_stmt FROM @ddl;
EXECUTE account_separation_stmt;
DEALLOCATE PREPARE account_separation_stmt;
