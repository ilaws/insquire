CREATE TABLE IF NOT EXISTS personal_task_ai_chats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task_id BIGINT UNSIGNED NOT NULL,
    role ENUM('learner','coach') NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_personal_task_ai_chats_task (task_id, id),
    CONSTRAINT fk_personal_task_ai_chats_task
        FOREIGN KEY (task_id) REFERENCES personal_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
