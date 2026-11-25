CREATE TABLE IF NOT EXISTS submission_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    teacher_id BIGINT UNSIGNED NOT NULL,
    anchor_text TEXT NOT NULL,
    context_before VARCHAR(160) NOT NULL DEFAULT '',
    context_after VARCHAR(160) NOT NULL DEFAULT '',
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_submission_comments_submission (submission_id, id),
    CONSTRAINT fk_submission_comments_submission
        FOREIGN KEY (submission_id) REFERENCES submissions(id) ON DELETE CASCADE,
    CONSTRAINT fk_submission_comments_teacher
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
