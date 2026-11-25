CREATE TABLE IF NOT EXISTS personal_task_writing (
    task_id BIGINT UNSIGNED NOT NULL,
    draft LONGTEXT NOT NULL,
    review_json LONGTEXT NULL,
    reviewed_draft_hash CHAR(64) NULL,
    reviewed_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (task_id),
    CONSTRAINT fk_personal_task_writing_task
        FOREIGN KEY (task_id) REFERENCES personal_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
