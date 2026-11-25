CREATE TABLE IF NOT EXISTS english_diagnostic_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id BIGINT UNSIGNED NOT NULL,
    teacher_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    due_at DATETIME NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_english_diagnostic_assignments_class (class_id, status, due_at),
    KEY idx_english_diagnostic_assignments_teacher (teacher_id),
    CONSTRAINT fk_english_diagnostic_assignments_class
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_english_diagnostic_assignments_teacher
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS english_diagnostic_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NOT NULL,
    max_score TINYINT UNSIGNED NOT NULL,
    cefr_level VARCHAR(2) NOT NULL,
    skill_scores LONGTEXT NOT NULL,
    answers LONGTEXT NOT NULL,
    completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_english_diagnostic_attempt_assignment_student (assignment_id, student_id),
    KEY idx_english_diagnostic_attempts_student (student_id, completed_at),
    CONSTRAINT fk_english_diagnostic_attempts_assignment
        FOREIGN KEY (assignment_id) REFERENCES english_diagnostic_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_english_diagnostic_attempts_student
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
