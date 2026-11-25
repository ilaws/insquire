CREATE TABLE IF NOT EXISTS lti_auth_requests (
    state_hash CHAR(64) NOT NULL,
    nonce VARCHAR(64) NOT NULL,
    session_hash CHAR(64) NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    target_link_uri VARCHAR(512) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (state_hash),
    KEY idx_lti_auth_requests_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lti_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    issuer_hash CHAR(64) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lti_identity_platform_user (issuer_hash, subject_hash),
    KEY idx_lti_identity_user (user_id),
    CONSTRAINT fk_lti_identity_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lti_courses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_key CHAR(64) NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    deployment_id VARCHAR(255) NOT NULL,
    context_id VARCHAR(255) NOT NULL,
    canvas_course_id VARCHAR(32) NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    teacher_user_id BIGINT UNSIGNED NOT NULL,
    roster_synced_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lti_course_key (course_key),
    KEY idx_lti_courses_class (class_id),
    CONSTRAINT fk_lti_course_class
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_lti_course_teacher
        FOREIGN KEY (teacher_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @ensure_lti_course_class_index = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE lti_courses ADD INDEX idx_lti_courses_class (class_id)',
        'SELECT 1'
    )
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'lti_courses'
      AND index_name = 'idx_lti_courses_class'
);
PREPARE ensure_lti_course_class_index_stmt FROM @ensure_lti_course_class_index;
EXECUTE ensure_lti_course_class_index_stmt;
DEALLOCATE PREPARE ensure_lti_course_class_index_stmt;

SET @drop_lti_course_class_index = (
    SELECT IF(
        COUNT(*) > 0,
        'ALTER TABLE lti_courses DROP INDEX uq_lti_course_class',
        'SELECT 1'
    )
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'lti_courses'
      AND index_name = 'uq_lti_course_class'
);
PREPARE drop_lti_course_class_index_stmt FROM @drop_lti_course_class_index;
EXECUTE drop_lti_course_class_index_stmt;
DEALLOCATE PREPARE drop_lti_course_class_index_stmt;

ALTER TABLE lti_courses
    ADD COLUMN IF NOT EXISTS canvas_course_id VARCHAR(32) NULL;

CREATE TABLE IF NOT EXISTS lti_assignment_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_key CHAR(64) NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    deployment_id VARCHAR(255) NOT NULL,
    context_id VARCHAR(255) NOT NULL,
    resource_link_id VARCHAR(255) NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    lineitem_url VARCHAR(2048) NULL,
    score_maximum DECIMAL(10,2) NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lti_resource_key (resource_key),
    KEY idx_lti_assignment_link_assignment (assignment_id),
    CONSTRAINT fk_lti_assignment_link_assignment
        FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lti_account_link_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code_hash CHAR(64) NOT NULL,
    target_user_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    redeemed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lti_account_link_code (code_hash),
    KEY idx_lti_account_link_target (target_user_id, redeemed_at, expires_at),
    CONSTRAINT fk_lti_account_link_target
        FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canvas_oauth_connections (
    teacher_id BIGINT UNSIGNED NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NULL,
    expires_at DATETIME NOT NULL,
    canvas_user_id VARCHAR(64) NOT NULL,
    assignment_read_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id),
    CONSTRAINT fk_canvas_oauth_teacher
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canvas_native_oauth_states (
    state_hash CHAR(64) NOT NULL,
    teacher_id BIGINT UNSIGNED NOT NULL,
    assignment_read_enabled TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','processing','connected','failed') NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (state_hash),
    KEY idx_canvas_native_oauth_teacher (teacher_id, created_at),
    KEY idx_canvas_native_oauth_expiry (expires_at),
    CONSTRAINT fk_canvas_native_oauth_teacher
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
