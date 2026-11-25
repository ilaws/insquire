CREATE TABLE IF NOT EXISTS canvas_published_assignments (
    publish_key CHAR(64) NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    deployment_id VARCHAR(255) NOT NULL,
    context_id VARCHAR(255) NOT NULL,
    canvas_module_id BIGINT UNSIGNED NOT NULL,
    canvas_assignment_id BIGINT UNSIGNED NULL,
    resource_link_id VARCHAR(255) NULL,
    canvas_module_item_id BIGINT UNSIGNED NULL,
    publish_state VARCHAR(24) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (publish_key),
    KEY idx_canvas_published_assignment (assignment_id),
    KEY idx_canvas_published_class (class_id),
    CONSTRAINT fk_canvas_published_assignment
        FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_canvas_published_class
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canvas_imported_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_key CHAR(64) NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    issuer VARCHAR(255) NOT NULL,
    deployment_id VARCHAR(255) NOT NULL,
    context_id VARCHAR(255) NOT NULL,
    canvas_course_id VARCHAR(32) NOT NULL,
    canvas_assignment_id BIGINT UNSIGNED NOT NULL,
    canvas_url VARCHAR(2048) NULL,
    canvas_updated_at DATETIME NULL,
    last_synced_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_canvas_import_source (source_key),
    UNIQUE KEY uq_canvas_import_assignment (assignment_id),
    KEY idx_canvas_import_course (class_id, canvas_course_id),
    CONSTRAINT fk_canvas_import_class
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_canvas_import_assignment
        FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
