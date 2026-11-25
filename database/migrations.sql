-- Select the target database before running this migration.

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    account_scope ENUM('personal','education') NOT NULL DEFAULT 'education',
    role ENUM('student','teacher','admin') NOT NULL DEFAULT 'student',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email_scope (email, account_scope),
    KEY idx_users_role (role),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    KEY idx_api_tokens_user (user_id),
    KEY idx_api_tokens_expiry (expires_at),
    CONSTRAINT fk_api_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_request_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_ai_request_log_user_created (user_id, created_at),
    KEY idx_ai_request_log_created (created_at),
    CONSTRAINT fk_ai_request_log_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    details TEXT NULL,
    due_at DATETIME NULL,
    status ENUM('planned','in_progress','completed') NOT NULL DEFAULT 'planned',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_personal_tasks_user_due (user_id, due_at),
    KEY idx_personal_tasks_user_status (user_id, status),
    CONSTRAINT fk_personal_tasks_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS font_scale ENUM('normal','large','x-large') NOT NULL DEFAULT 'normal',
    ADD COLUMN IF NOT EXISTS high_contrast TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS reduced_motion TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS bio TEXT NULL,
    ADD COLUMN IF NOT EXISTS profile_image VARCHAR(80) NULL;

DROP TABLE IF EXISTS two_factor_challenges;
ALTER TABLE users
    DROP COLUMN IF EXISTS two_factor_phone,
    DROP COLUMN IF EXISTS two_factor_enabled;
DROP TABLE IF EXISTS ai_training_examples;
DROP TABLE IF EXISTS ai_settings;

CREATE TABLE IF NOT EXISTS classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    teacher_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    grade_level VARCHAR(40) NULL,
    join_code VARCHAR(16) NOT NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_classes_join_code (join_code),
    KEY idx_classes_teacher (teacher_id),
    KEY idx_classes_status (status),
    CONSTRAINT fk_classes_teacher
        FOREIGN KEY (teacher_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_class_member (class_id,user_id),
    KEY idx_members_user (user_id),
    KEY idx_members_class (class_id),
    CONSTRAINT fk_members_class
        FOREIGN KEY (class_id)
        REFERENCES classes(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_members_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS class_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_class_announcements_class_created (class_id, created_at),
    CONSTRAINT fk_class_announcements_class
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_announcements_author
        FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    class_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    prompt TEXT NOT NULL,
    instructions TEXT NULL,
    rubric_text LONGTEXT NULL,
    rubric_filename VARCHAR(255) NULL,
    due_at DATETIME NULL,
    allow_late TINYINT(1) NOT NULL DEFAULT 1,
    late_penalty_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    status ENUM('draft','published','closed') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assignments_class (class_id),
    KEY idx_assignments_status (status),
    KEY idx_assignments_due (due_at),
    CONSTRAINT fk_assignments_class
        FOREIGN KEY (class_id)
        REFERENCES classes(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE assignments
    ADD COLUMN IF NOT EXISTS allow_late TINYINT(1) NOT NULL DEFAULT 1 AFTER due_at,
    ADD COLUMN IF NOT EXISTS late_penalty_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER allow_late;

ALTER TABLE classes
    ADD COLUMN IF NOT EXISTS grade_level VARCHAR(40) NULL AFTER description;

ALTER TABLE classes
    ADD COLUMN IF NOT EXISTS show_final_average TINYINT(1) NOT NULL DEFAULT 1 AFTER grade_level;

CREATE TABLE IF NOT EXISTS lesson_standards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    state_code VARCHAR(10) NOT NULL,
    state_name VARCHAR(100) NOT NULL,
    grade_level VARCHAR(40) NOT NULL,
    skill VARCHAR(120) NOT NULL DEFAULT 'Reading comprehension',
    subject VARCHAR(80) NOT NULL DEFAULT 'English Language Arts',
    standard_code VARCHAR(80) NOT NULL,
    standard_title VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lesson_standard (state_code, grade_level, subject, standard_code),
    KEY idx_lesson_standards_filter (state_code, grade_level, subject)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lessons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    standard_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    objective TEXT NOT NULL,
    content LONGTEXT NOT NULL,
    activity LONGTEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lessons_standard (standard_id),
    UNIQUE KEY uq_lessons_standard_title (standard_id, title),
    CONSTRAINT fk_lessons_standard FOREIGN KEY (standard_id) REFERENCES lesson_standards(id) ON DELETE SET NULL,
    CONSTRAINT fk_lessons_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE lesson_standards
    ADD COLUMN IF NOT EXISTS skill VARCHAR(120) NOT NULL DEFAULT 'Reading comprehension' AFTER grade_level;

CREATE TABLE IF NOT EXISTS lesson_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id BIGINT UNSIGNED NOT NULL,
    question_type ENUM('multiple_choice','short_response') NOT NULL DEFAULT 'multiple_choice',
    question_text TEXT NOT NULL,
    options_json JSON NULL,
    correct_answer VARCHAR(255) NULL,
    explanation TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lesson_question_order (lesson_id, sort_order),
    KEY idx_lesson_questions_lesson (lesson_id, sort_order),
    CONSTRAINT fk_lesson_questions_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_question_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question_type ENUM('multiple_choice','short_response') NOT NULL DEFAULT 'multiple_choice',
    prompt_template TEXT NOT NULL,
    options_json JSON NULL,
    correct_answer VARCHAR(255) NULL,
    explanation_template TEXT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO lesson_question_templates
    (id, question_type, prompt_template, options_json, correct_answer, explanation_template)
VALUES
    (1, 'multiple_choice', 'Which response best demonstrates {skill}?', '["A clear claim supported by a specific detail and explanation.","A guess with no evidence.","A copied passage with no explanation.","A list unrelated to the text."]', 'A clear claim supported by a specific detail and explanation.', 'Strong work applies {skill} with evidence and reasoning.'),
    (2, 'multiple_choice', 'What is the first step when practicing {skill}?', '["Identify the task and relevant evidence.","Skip the text.","Copy the conclusion.","Choose the longest answer."]', 'Identify the task and relevant evidence.', 'Start by understanding the task and locating relevant information.'),
    (3, 'multiple_choice', 'Which evidence best supports work aligned to {standard}?', '["A precise detail from the passage.","An unrelated opinion.","A detail from a different text.","No evidence."]', 'A precise detail from the passage.', 'Specific evidence makes a standards-based response defensible.'),
    (4, 'multiple_choice', 'Which explanation is strongest for {skill}?', '["It explains why the evidence matters.","It repeats the evidence.","It changes the topic.","It gives no reason."]', 'It explains why the evidence matters.', 'Explanation connects the evidence to the skill.'),
    (5, 'multiple_choice', 'Which answer shows revision rather than copying?', '["It keeps the idea but improves precision.","It copies every sentence.","It removes the main idea.","It adds an unrelated claim."]', 'It keeps the idea but improves precision.', 'Revision improves a writer’s own idea without replacing its meaning.'),
    (6, 'multiple_choice', 'What should a reader check after applying {skill}?', '["Whether the answer is supported by the text.","Whether it is the longest answer.","Whether it avoids all details.","Whether it changes the prompt."]', 'Whether the answer is supported by the text.', 'Text support is the check against unsupported answers.'),
    (7, 'multiple_choice', 'Which choice is an example of analysis?', '["Explaining how a detail creates meaning.","Repeating what happened.","Listing character names.","Copying a sentence."]', 'Explaining how a detail creates meaning.', 'Analysis explains significance, not just events.'),
    (8, 'multiple_choice', 'Which organization best supports {skill}?', '["Point, evidence, reasoning.","Evidence, unrelated topic, point.","Conclusion only.","Random details."]', 'Point, evidence, reasoning.', 'A logical sequence helps readers follow the reasoning.'),
    (9, 'multiple_choice', 'Which question helps evaluate {skill}?', '["What detail proves my answer?","How can I avoid the text?","Can I make a random guess?","Can I copy the prompt?"]', 'What detail proves my answer?', 'Evidence questions make thinking visible.'),
    (10, 'multiple_choice', 'What makes an answer aligned to {standard}?', '["It addresses the standard and explains evidence.","It uses unrelated facts.","It only repeats a definition.","It avoids the text."]', 'It addresses the standard and explains evidence.', 'Alignment requires both the target skill and support.'),
    (11, 'short_response', 'In two sentences, explain how you would practice {skill} using a passage.', NULL, NULL, 'A strong response names a step, uses a detail, and explains the connection.'),
    (12, 'short_response', 'Write one question a reader should ask when evaluating {skill}.', NULL, NULL, 'A useful question checks the task, evidence, or reasoning.'),
    (13, 'short_response', 'Describe one mistake a writer might make with {skill} and how to fix it.', NULL, NULL, 'A strong response identifies a specific mistake and a practical revision.'),
    (14, 'short_response', 'Use the standard focus {standard} in one sentence about a text.', NULL, NULL, 'The sentence should make a clear claim connected to the stated focus.'),
    (15, 'multiple_choice', 'Which sentence would be the best next step after learning {skill}?', '["Apply it to a new passage.","Stop before practicing.","Ignore the evidence.","Memorize an unrelated fact."]', 'Apply it to a new passage.', 'Transfer practice shows whether the skill is understood.'),
    (16, 'multiple_choice', 'Which response is most precise?', '["A specific claim with a relevant example.","Something happened.","It is good.","The author does things."]', 'A specific claim with a relevant example.', 'Precision makes reasoning easier to evaluate.'),
    (17, 'multiple_choice', 'Why does evidence matter for {skill}?', '["It lets the reader verify the reasoning.","It makes every answer correct.","It replaces explanation.","It removes the need for a claim."]', 'It lets the reader verify the reasoning.', 'Evidence allows the reader to test the interpretation.'),
    (18, 'short_response', 'Give one example of feedback that would help someone improve at {skill}.', NULL, NULL, 'Useful feedback is specific, kind, and connected to the work.'),
    (19, 'multiple_choice', 'Which answer best avoids unsupported assumptions?', '["It distinguishes what the text says from what the reader infers.","It treats every guess as fact.","It ignores the passage.","It uses only personal opinion."]', 'It distinguishes what the text says from what the reader infers.', 'Good analysis separates evidence from inference.'),
    (20, 'short_response', 'Explain how the focus {standard} could improve your next draft.', NULL, NULL, 'Connect the focus to a concrete change you can make.'),
    (21, 'multiple_choice', 'Which revision would make work on {skill} clearer?', '["Name the evidence and explain its purpose.","Remove all examples.","Add unrelated details.","Use vaguer words."]', 'Name the evidence and explain its purpose.', 'Clarity comes from specific evidence and explanation.'),
    (22, 'multiple_choice', 'What should a teacher look for when checking {skill}?', '["A clear target, relevant evidence, and reasoning.","Only length.","Only spelling.","Only the final sentence."]', 'A clear target, relevant evidence, and reasoning.', 'A skill check should measure the target, not a distracting feature.'),
    (23, 'short_response', 'Write a short example that demonstrates {skill}.', NULL, NULL, 'The example should visibly apply the skill rather than merely name it.'),
    (24, 'multiple_choice', 'Which choice best supports independent thinking about {skill}?', '["Explain your own reasoning before comparing examples.","Copy the model answer.","Skip the question.","Choose without reading."]', 'Explain your own reasoning before comparing examples.', 'Independent reasoning helps students transfer the skill.'),
    (25, 'short_response', 'What evidence would you collect to demonstrate {skill}?', NULL, NULL, 'Choose evidence that directly relates to the skill and explain why.');

CREATE TABLE IF NOT EXISTS lesson_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id BIGINT UNSIGNED NOT NULL,
    class_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    due_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lesson_class (lesson_id, class_id),
    KEY idx_lesson_assignments_class (class_id),
    CONSTRAINT fk_lesson_assignments_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_assignments_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_assignments_teacher FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lesson_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_assignment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    response LONGTEXT NULL,
    score DECIMAL(5,2) NULL,
    status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
    completed_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lesson_progress (lesson_assignment_id, student_id),
    CONSTRAINT fk_lesson_progress_assignment FOREIGN KEY (lesson_assignment_id) REFERENCES lesson_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_lesson_progress_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE lesson_progress
    ADD COLUMN IF NOT EXISTS score DECIMAL(5,2) NULL AFTER response;

INSERT IGNORE INTO lesson_standards
    (state_code, state_name, grade_level, skill, subject, standard_code, standard_title)
VALUES
    ('CCSS', 'Common Core', '9-10', 'Argument and evidence', 'English Language Arts', 'RL.9-10.2', 'Determine themes or central ideas and analyze their development.'),
    ('CCSS', 'Common Core', '11-12', 'Theme analysis', 'English Language Arts', 'RL.11-12.2', 'Determine two or more themes and analyze their development.'),
    ('CA', 'California', '9-10', 'Theme analysis', 'English Language Arts', 'RL.9-10.2', 'Analyze the development of theme or central idea.'),
    ('TX', 'Texas', '9-10', 'Literary elements', 'English Language Arts', 'ELAR.9.10.5', 'Analyze how authors use literary elements and techniques.'),
    ('NY', 'New York', '9-10', 'Theme analysis', 'English Language Arts', 'RL.9-10.2', 'Determine themes and analyze their development.'),
    ('FL', 'Florida', '9-10', 'Theme analysis', 'English Language Arts', 'ELA.9.R.1.2', 'Analyze the development of stated or implied theme.'),
    ('WA', 'Washington', '11-12', 'Theme analysis', 'English Language Arts', 'RL.11-12.2', 'Analyze the development of theme across a text.');

INSERT IGNORE INTO lesson_standards
    (state_code, state_name, grade_level, skill, subject, standard_code, standard_title)
VALUES
    ('SKILL', 'Writing Skills', 'All grades', 'Thesis writing', 'English Language Arts', 'SKILL.THESIS', 'Write a clear, arguable thesis.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Evidence use', 'English Language Arts', 'SKILL.EVIDENCE', 'Select and explain relevant evidence.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Organization', 'English Language Arts', 'SKILL.ORGANIZATION', 'Organize analytical paragraphs logically.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Clarity and revision', 'English Language Arts', 'SKILL.CLARITY', 'Revise for precise, readable writing.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Reading comprehension', 'English Language Arts', 'SKILL.COMPREHENSION', 'Identify the central idea and supporting details.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Inference', 'English Language Arts', 'SKILL.INFERENCE', 'Make and support logical inferences.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Main idea', 'English Language Arts', 'SKILL.MAIN_IDEA', 'Determine the main idea of a text.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Summarizing', 'English Language Arts', 'SKILL.SUMMARY', 'Summarize without copying or adding unsupported ideas.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Vocabulary in context', 'English Language Arts', 'SKILL.VOCABULARY', 'Use context to determine word meaning.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Text structure', 'English Language Arts', 'SKILL.STRUCTURE', 'Analyze how structure shapes meaning.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Point of view', 'English Language Arts', 'SKILL.POV', 'Analyze how perspective affects a text.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Character analysis', 'English Language Arts', 'SKILL.CHARACTER', 'Analyze character choices and development.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Figurative language', 'English Language Arts', 'SKILL.FIGURATIVE', 'Interpret imagery, symbolism, and figurative language.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Counterclaims', 'English Language Arts', 'SKILL.COUNTERCLAIM', 'Recognize and respond to an opposing claim.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Transitions', 'English Language Arts', 'SKILL.TRANSITIONS', 'Use transitions to show relationships between ideas.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Sentence variety', 'English Language Arts', 'SKILL.SENTENCES', 'Combine sentence structures for fluent writing.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Grammar and conventions', 'English Language Arts', 'SKILL.GRAMMAR', 'Edit grammar, punctuation, and usage.'),
    ('SKILL', 'Writing Skills', 'All grades', 'Research and citations', 'English Language Arts', 'SKILL.CITATIONS', 'Integrate and cite sources responsibly.');

INSERT IGNORE INTO lesson_standards
    (state_code, state_name, grade_level, skill, subject, standard_code, standard_title)
VALUES
    ('AL', 'Alabama', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('AK', 'Alaska', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('AZ', 'Arizona', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('AR', 'Arkansas', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('CA', 'California', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('CO', 'Colorado', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('CT', 'Connecticut', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('DE', 'Delaware', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('FL', 'Florida', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('GA', 'Georgia', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('HI', 'Hawaii', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('ID', 'Idaho', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('IL', 'Illinois', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('IN', 'Indiana', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('IA', 'Iowa', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('KS', 'Kansas', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('KY', 'Kentucky', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('LA', 'Louisiana', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('ME', 'Maine', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('MD', 'Maryland', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('MA', 'Massachusetts', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('MI', 'Michigan', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('MN', 'Minnesota', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('MS', 'Mississippi', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('MO', 'Missouri', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('MT', 'Montana', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('NE', 'Nebraska', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('NV', 'Nevada', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('NH', 'New Hampshire', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('NJ', 'New Jersey', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('NM', 'New Mexico', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('NY', 'New York', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('NC', 'North Carolina', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('ND', 'North Dakota', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('OH', 'Ohio', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('OK', 'Oklahoma', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('OR', 'Oregon', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('PA', 'Pennsylvania', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('RI', 'Rhode Island', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('SC', 'South Carolina', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('SD', 'South Dakota', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('TN', 'Tennessee', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('TX', 'Texas', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('UT', 'Utah', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('VT', 'Vermont', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('VA', 'Virginia', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('WA', 'Washington', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('WV', 'West Virginia', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('WI', 'Wisconsin', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.'),
    ('WY', 'Wyoming', 'All grades', 'Reading and writing foundations', 'English Language Arts', 'ELA.GENERAL', 'Practice reading, analysis, and written communication.');

DELETE FROM lessons
WHERE created_by IS NULL
  AND (
      title LIKE '% ELA: %'
      OR title LIKE '% Practice'
      OR title LIKE '% Skill Builder'
  );
DELETE FROM lesson_standards
WHERE standard_code LIKE 'ELA.GENERAL%';

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT ls.id, 'Theme, Evidence, and Analysis', 'Build a defensible literary theme claim using precise evidence.', 'Students will connect a theme to two pieces of textual evidence and explain the significance of each.', 'A strong literary analysis does more than name a theme. It explains how the author develops that theme through characters, conflict, symbols, or recurring images. Use the pattern claim, evidence, reasoning: state what the text suggests, identify a precise moment, then explain how that moment supports the claim.', 'Choose a short passage from the class text. Write one theme claim, select two brief pieces of evidence, and write two sentences explaining how each piece develops the theme.', NULL, 1
FROM lesson_standards ls WHERE ls.state_code = 'CCSS' AND ls.grade_level = '9-10' AND ls.standard_code = 'RL.9-10.2'
LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT ls.id, 'Write a Strong Thesis', 'Practice turning a topic into a clear, arguable claim.', 'Students will write a thesis that answers the prompt and previews the main reasoning.', 'A thesis is a specific answer to the prompt, not a topic announcement. It should make a claim that a reader could discuss and should point toward the reasons or evidence the essay will develop.', 'Turn a broad topic into a claim. Then underline the words that answer the prompt and circle the words that preview your reasons.', NULL, 1
FROM lesson_standards ls WHERE ls.state_code = 'SKILL' AND ls.standard_code = 'SKILL.THESIS' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT ls.id, 'Use Evidence Effectively', 'Learn to select, introduce, and explain evidence.', 'Students will connect evidence to a claim instead of dropping quotations into a paragraph.', 'Use the evidence sandwich: introduce the context, present a short quotation or detail, and explain why it proves the claim. Evidence is only persuasive when the explanation makes the connection clear.', 'Find one detail from your text. Write the claim it supports and one sentence explaining the connection.', NULL, 1
FROM lesson_standards ls WHERE ls.state_code = 'SKILL' AND ls.standard_code = 'SKILL.EVIDENCE' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT ls.id, 'Organize an Analytical Paragraph', 'Build paragraphs that move from claim to evidence to reasoning.', 'Students will arrange ideas in a logical sequence with purposeful transitions.', 'An analytical paragraph usually begins with a focused point, develops that point with evidence, and ends by explaining its significance. Transitions should show relationships such as contrast, cause, example, or continuation.', 'Label each sentence in one paragraph as point, evidence, reasoning, or transition. Move any sentence that interrupts the logic.', NULL, 1
FROM lesson_standards ls WHERE ls.state_code = 'SKILL' AND ls.standard_code = 'SKILL.ORGANIZATION' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT ls.id, 'Revise for Clarity', 'Find vague wording and replace it with precise language.', 'Students will revise sentences so the subject, action, and meaning are clear.', 'Clarity improves when concrete nouns and active verbs replace vague phrases. Read each sentence asking: who is acting, what are they doing, and why does it matter?', 'Choose two sentences from your draft. Replace one vague word in each with a more precise word, then reread the paragraph aloud.', NULL, 1
FROM lesson_standards ls WHERE ls.state_code = 'SKILL' AND ls.standard_code = 'SKILL.CLARITY' LIMIT 1;

INSERT IGNORE INTO lesson_questions
    (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
SELECT id, 'multiple_choice', 'Which sentence best explains the role of reasoning in literary analysis?',
    '["It repeats the plot.","It explains how evidence supports the claim.","It lists every character.","It replaces the thesis."]',
    'It explains how evidence supports the claim.',
    'Reasoning connects the selected evidence back to the argument.', 1
FROM lessons WHERE title = 'Theme, Evidence, and Analysis' LIMIT 1;

INSERT IGNORE INTO lesson_questions
    (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
SELECT id, 'short_response', 'In one or two sentences, explain how a symbol can develop a theme.',
    NULL, NULL, 'A strong response names the symbol, states what it represents, and connects it to the theme.', 2
FROM lessons WHERE title = 'Theme, Evidence, and Analysis' LIMIT 1;

INSERT IGNORE INTO lesson_questions
    (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
SELECT id, 'multiple_choice', 'Which order creates the clearest analytical paragraph?',
    '["Evidence, unrelated detail, conclusion","Claim, evidence, reasoning","Conclusion, claim, evidence","Only evidence"]',
    'Claim, evidence, reasoning',
    'The claim gives the paragraph direction, evidence supports it, and reasoning explains the connection.', 1
FROM lessons WHERE title = 'Organize an Analytical Paragraph' LIMIT 1;

INSERT IGNORE INTO lesson_questions
    (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
SELECT id, 'short_response', 'Write one transition that shows a contrast between two ideas.',
    NULL, NULL, 'Strong answers use language such as however, in contrast, or although to show the relationship.', 2
FROM lessons WHERE title = 'Organize an Analytical Paragraph' LIMIT 1;

INSERT IGNORE INTO lesson_questions
    (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
SELECT id, 'multiple_choice', 'Which is the strongest thesis for a literary analysis?',
    '["This essay is about a book.","The book has many characters.","Through the symbol of the mockingbird, Lee shows how prejudice destroys innocence.","I will discuss the story."]',
    'Through the symbol of the mockingbird, Lee shows how prejudice destroys innocence.',
    'A strong thesis makes a specific, arguable claim that answers the prompt.', 1
FROM lessons WHERE title = 'Write a Strong Thesis' LIMIT 1;

INSERT IGNORE INTO lesson_questions
    (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
SELECT id, 'multiple_choice', 'What should reasoning do after evidence is presented?',
    '["Repeat the quotation only.","Explain how the evidence supports the claim.","Introduce an unrelated topic.","End the paragraph immediately."]',
    'Explain how the evidence supports the claim.',
    'Reasoning makes the evidence meaningful by connecting it to the argument.', 1
FROM lessons WHERE title = 'Use Evidence Effectively' LIMIT 1;

INSERT IGNORE INTO lesson_questions
    (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
SELECT id, 'multiple_choice', 'Which revision is more precise?',
    '["The character does things.","The character avoids the trial because fear controls his choices.","The character is there.","The character has stuff happen."]',
    'The character avoids the trial because fear controls his choices.',
    'Precise writing names the action and explains its significance.', 1
FROM lessons WHERE title = 'Revise for Clarity' LIMIT 1;

CREATE TABLE IF NOT EXISTS submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    assignment_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    essay LONGTEXT NOT NULL,
    status ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
    submitted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_submission (assignment_id,student_id),
    KEY idx_submissions_student (student_id),
    KEY idx_submissions_assignment (assignment_id),
    KEY idx_submissions_status (status),
    CONSTRAINT fk_submissions_assignment
        FOREIGN KEY (assignment_id)
        REFERENCES assignments(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_submissions_student
        FOREIGN KEY (student_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_feedback (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    feedback LONGTEXT NOT NULL,
    model_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_feedback_submission (submission_id),
    KEY idx_feedback_student (student_id),
    KEY idx_feedback_created (created_at),
    CONSTRAINT fk_feedback_submission
        FOREIGN KEY (submission_id)
        REFERENCES submissions(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_feedback_student
        FOREIGN KEY (student_id)
        REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


ALTER TABLE submissions
    MODIFY COLUMN status ENUM('draft','submitted','graded','returned')
    NOT NULL DEFAULT 'draft';

ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS grade VARCHAR(10) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS teacher_feedback TEXT NULL AFTER grade,
    ADD COLUMN IF NOT EXISTS graded_at DATETIME NULL AFTER teacher_feedback,
    ADD COLUMN IF NOT EXISTS returned_at DATETIME NULL AFTER graded_at;

CREATE TABLE IF NOT EXISTS teacher_ai_evaluations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    evaluation LONGTEXT NOT NULL,
    recommended_grade TINYINT UNSIGNED NULL,
    needs_extra_help TINYINT(1) NOT NULL DEFAULT 0,
    help_reason TEXT NULL,
    model_name VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teacher_eval_submission (submission_id),
    KEY idx_teacher_eval_submission (submission_id),
    CONSTRAINT fk_teacher_eval_submission
        FOREIGN KEY (submission_id)
        REFERENCES submissions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT ls.id, 'Find the Main Idea', 'Separate a passage’s central idea from its supporting details.', 'Students will state the main idea in their own words and identify details that support it.', 'The main idea is the most important point the author wants readers to understand. A detail may be interesting without being central. Ask what all the important details have in common, then state that idea without copying a sentence from the passage.', 'Read a short paragraph. Write its main idea in one sentence, then list two details that support your answer.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.MAIN_IDEA' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT ls.id, 'Make a Strong Inference', 'Use clues and reasoning to understand what a text implies.', 'Students will distinguish an inference from a guess by connecting it to evidence.', 'An inference combines what the text says with what you already know. A guess has no clear support. To make an inference, name the clue, explain what it suggests, and show why that interpretation fits.', 'Write one inference about a character or event. Underline the text clue that supports it.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.INFERENCE' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT id, 'Summarize Without Retelling Everything', 'Condense a passage while preserving its essential meaning.', 'Students will write objective summaries that include only central ideas and key events.', 'A summary answers who or what, what happened or was explained, and why it matters. Leave out minor details, personal opinions, and repeated information. A summary should be shorter than the original and written in your own words.', 'Summarize a passage in three sentences. Remove one detail that does not change the central meaning.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.SUMMARY' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT id, 'Vocabulary in Context', 'Use surrounding words and ideas to determine meaning.', 'Students will infer a word’s meaning from context and verify it with the sentence.', 'Context clues may include definitions, examples, contrasts, or the overall situation. Do not choose a meaning simply because it is the first dictionary definition you remember. Test the meaning by rereading the sentence.', 'Choose one unfamiliar word from a passage. Circle the context clues and write the meaning that best fits.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.VOCABULARY' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT id, 'Analyze Point of View', 'Explain how perspective shapes what readers know and feel.', 'Students will identify the narrator’s perspective and explain its effect.', 'Point of view controls access to information. A first-person narrator may reveal thoughts but have limited knowledge. A third-person narrator may be close to one character or know more than everyone. Ask what the chosen perspective reveals, hides, or emphasizes.', 'Rewrite one sentence from a different character’s perspective. Note what changes and what stays unknown.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.POV' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT id, 'Track Character Development', 'Use choices, conflicts, and consequences to analyze a character.', 'Students will explain how a character changes or remains consistent and why.', 'Character analysis focuses on patterns, not labels. Track what a character wants, what obstacle they face, what choice they make, and what consequence follows. Then explain what that pattern reveals about the character or theme.', 'Create a four-part character map: goal, obstacle, choice, consequence. Use one scene as evidence.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.CHARACTER' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT id, 'Read Figurative Language', 'Interpret imagery, metaphor, and symbolism beyond their literal meaning.', 'Students will explain how a figurative image contributes to meaning or theme.', 'Figurative language creates a comparison or image that carries extra meaning. First identify the literal image. Then ask what idea, feeling, or relationship the image suggests and how that meaning fits the surrounding passage.', 'Choose one image or symbol. Write its literal meaning, suggested meaning, and effect on the reader.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.FIGURATIVE' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT id, 'Answer a Counterclaim', 'Acknowledge an opposing idea and respond to it with reasoning.', 'Students will make an argument stronger by addressing a reasonable alternative view.', 'A counterclaim is not a random disagreement. It is a reasonable interpretation or concern a reader might raise. State it fairly, then respond with stronger evidence, a limitation, or a distinction that supports your original claim.', 'Write one sentence beginning with “Some readers may argue…” and follow it with a response supported by evidence.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.COUNTERCLAIM' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT id, 'Build Better Transitions', 'Use transitions to show how ideas relate.', 'Students will choose transitions that communicate cause, contrast, example, or continuation.', 'A transition should express the relationship between the previous idea and the next one. However signals contrast; therefore signals result; for example signals illustration; similarly signals connection. Avoid using transitions only because they sound formal.', 'Join two related sentences with a transition. Name the relationship the transition communicates.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.TRANSITIONS' LIMIT 1;

INSERT IGNORE INTO lessons
    (standard_id, title, description, objective, content, activity, created_by, is_public)
SELECT id, 'Edit Grammar in Context', 'Revise grammar and punctuation without losing meaning.', 'Students will edit a sentence by identifying the subject, verb, and relationship between clauses.', 'Edit in passes. First check complete sentences and subject-verb agreement. Next check punctuation between clauses. Finally check pronoun reference and word choice. Read the sentence aloud, but use the sentence’s structure rather than sound alone to decide.', 'Take one sentence from a draft. Mark its subject and verb, then correct one grammar or punctuation issue and explain the change.', NULL, 1
FROM lesson_standards ls WHERE ls.standard_code = 'SKILL.GRAMMAR' LIMIT 1;

INSERT IGNORE INTO lesson_questions
    (lesson_id, question_type, question_text, options_json, correct_answer, explanation, sort_order)
SELECT l.id, 'multiple_choice', CONCAT('Which action best demonstrates ', ls.skill, '?'),
    '["Use a specific clue or detail and explain it.","Make an unsupported guess.","Copy the whole passage.","List unrelated facts."]',
    'Use a specific clue or detail and explain it.',
    'A skill becomes analysis when the response uses relevant evidence and reasoning.', 1
FROM lessons l
INNER JOIN lesson_standards ls ON ls.id = l.standard_id
WHERE l.title IN ('Find the Main Idea', 'Make a Strong Inference', 'Summarize Without Retelling Everything', 'Vocabulary in Context', 'Analyze Point of View', 'Track Character Development', 'Read Figurative Language', 'Answer a Counterclaim', 'Build Better Transitions', 'Edit Grammar in Context');

DELETE q
FROM lesson_questions q
INNER JOIN lessons l ON l.id = q.lesson_id AND l.created_by IS NULL
INNER JOIN lesson_standards ls ON ls.id = l.standard_id
INNER JOIN lesson_question_templates t
    ON q.question_text = REPLACE(REPLACE(t.prompt_template, '{skill}', ls.skill), '{standard}', ls.standard_code);

DROP TABLE IF EXISTS lesson_question_templates;
