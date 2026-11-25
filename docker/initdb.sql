-- Runs once on the first start of an empty MariaDB volume (docker compose).
-- Applies the schema and every additive migration in dependency order.
SOURCE /sql/migrations.sql;
SOURCE /sql/account_separation.sql;
SOURCE /sql/edition_features.sql;
SOURCE /sql/institution_moderation.sql;
SOURCE /sql/notifications.sql;
SOURCE /sql/payments_subscriptions.sql;
SOURCE /sql/personal_task_ai_chats.sql;
SOURCE /sql/personal_task_writing.sql;
SOURCE /sql/english_diagnostics.sql;
SOURCE /sql/submission_comments.sql;
SOURCE /sql/user_calendar_events.sql;
SOURCE /sql/lti_canvas.sql;
SOURCE /sql/canvas_publishing.sql;
SOURCE /sql/seed_standards_expanded.sql;
SOURCE /sql/add_video_lessons.sql;
SOURCE /sql/fix_lesson_questions.sql;
