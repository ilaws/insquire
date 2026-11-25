-- Additive, production-safe Education institutions and moderation migration.
-- Back up the database, apply this file once, then deploy the matching PHP code.
-- Existing classes remain institution_id = NULL and retain legacy access.

CREATE TABLE IF NOT EXISTS institutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(180) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_institutions_creator (created_by),
    KEY idx_institutions_status (status),
    CONSTRAINT fk_institutions_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('admin','teacher','student') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_member (institution_id, user_id),
    KEY idx_institution_members_user (user_id, institution_id),
    CONSTRAINT fk_institution_members_institution
        FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_members_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    institution_id BIGINT UNSIGNED NOT NULL,
    invited_by BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    accepted_by BIGINT UNSIGNED NULL,
    accepted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_institution_invite_token (token_hash),
    KEY idx_institution_invites_pending (institution_id, accepted_at, expires_at),
    CONSTRAINT fk_institution_invites_institution
        FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_invites_inviter
        FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_institution_invites_acceptor
        FOREIGN KEY (accepted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    moderation_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    flagged_at DATETIME NULL,
    moderated_by BIGINT UNSIGNED NULL,
    moderated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_class_messages_thread (class_id, sender_id, recipient_id, created_at),
    KEY idx_class_messages_recipient (recipient_id, created_at),
    CONSTRAINT fk_class_messages_class
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_messages_sender
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_messages_recipient
        FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    moderation_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    flagged_at DATETIME NULL,
    moderated_by BIGINT UNSIGNED NULL,
    moderated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_class_announcements_class_created (class_id, created_at),
    CONSTRAINT fk_class_announcements_class
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_announcements_author
        FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE classes
    ADD COLUMN IF NOT EXISTS institution_id BIGINT UNSIGNED NULL;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classes' AND INDEX_NAME = 'idx_classes_institution') = 0,
    'CREATE INDEX idx_classes_institution ON classes (institution_id)',
    'SELECT 1'
);
PREPARE institution_moderation_stmt FROM @ddl;
EXECUTE institution_moderation_stmt;
DEALLOCATE PREPARE institution_moderation_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'classes'
       AND CONSTRAINT_NAME = 'fk_classes_institution') = 0,
    'ALTER TABLE classes ADD CONSTRAINT fk_classes_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE institution_moderation_stmt FROM @ddl;
EXECUTE institution_moderation_stmt;
DEALLOCATE PREPARE institution_moderation_stmt;

ALTER TABLE class_messages
    ADD COLUMN IF NOT EXISTS moderation_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    ADD COLUMN IF NOT EXISTS flagged_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS moderated_by BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS moderated_at DATETIME NULL;

ALTER TABLE class_announcements
    ADD COLUMN IF NOT EXISTS moderation_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
    ADD COLUMN IF NOT EXISTS flagged_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS moderated_by BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS moderated_at DATETIME NULL;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'class_messages' AND INDEX_NAME = 'idx_class_messages_moderation') = 0,
    'CREATE INDEX idx_class_messages_moderation ON class_messages (class_id, moderation_status, created_at)',
    'SELECT 1'
);
PREPARE institution_moderation_stmt FROM @ddl;
EXECUTE institution_moderation_stmt;
DEALLOCATE PREPARE institution_moderation_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'class_announcements' AND INDEX_NAME = 'idx_class_announcements_moderation') = 0,
    'CREATE INDEX idx_class_announcements_moderation ON class_announcements (class_id, moderation_status, created_at)',
    'SELECT 1'
);
PREPARE institution_moderation_stmt FROM @ddl;
EXECUTE institution_moderation_stmt;
DEALLOCATE PREPARE institution_moderation_stmt;
