-- Additive billing and platform-entitlement migration.
-- Requires database/institution_moderation.sql (institutions/institution_members).
-- No existing account data is modified or removed.

CREATE TABLE IF NOT EXISTS billing_checkouts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_type ENUM('personal','institution') NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    institution_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    plan_key VARCHAR(40) NOT NULL,
    stripe_price_id VARCHAR(120) NOT NULL,
    stripe_session_id VARCHAR(255) NULL,
    stripe_customer_id VARCHAR(255) NULL,
    metadata_signature CHAR(64) NOT NULL,
    status ENUM('open','completed','expired','failed') NOT NULL DEFAULT 'open',
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_checkout_session (stripe_session_id),
    KEY idx_billing_checkout_user (user_id, status, created_at),
    KEY idx_billing_checkout_institution (institution_id, status, created_at),
    CONSTRAINT fk_billing_checkout_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_checkout_institution
        FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_checkout_creator
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_type ENUM('personal','institution') NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    institution_id BIGINT UNSIGNED NULL,
    checkout_id BIGINT UNSIGNED NOT NULL,
    stripe_customer_id VARCHAR(255) NULL,
    stripe_subscription_id VARCHAR(255) NOT NULL,
    stripe_price_id VARCHAR(120) NOT NULL,
    plan_key VARCHAR(40) NOT NULL,
    seat_limit INT UNSIGNED NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'incomplete',
    cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
    current_period_end DATETIME NULL,
    last_event_created BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_billing_stripe_subscription (stripe_subscription_id),
    KEY idx_billing_subscription_personal (user_id, status, current_period_end),
    KEY idx_billing_subscription_institution (institution_id, status, current_period_end),
    CONSTRAINT fk_billing_subscription_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_subscription_institution
        FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
    CONSTRAINT fk_billing_subscription_checkout
        FOREIGN KEY (checkout_id) REFERENCES billing_checkouts(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(120) NOT NULL,
    event_created BIGINT UNSIGNED NOT NULL DEFAULT 0,
    processing_status ENUM('processing','processed','failed') NOT NULL DEFAULT 'processing',
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    PRIMARY KEY (event_id),
    KEY idx_stripe_webhook_status (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_entitlement_overrides (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    granted_by BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_entitlement_override_user (user_id, revoked_at, expires_at),
    CONSTRAINT fk_entitlement_override_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_entitlement_override_grantor
        FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_entitlement_override_revoker
        FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_entitlement_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    action ENUM('granted','revoked') NOT NULL,
    reason VARCHAR(500) NOT NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entitlement_audit_user_created (user_id, created_at),
    KEY idx_entitlement_audit_actor_created (actor_id, created_at),
    CONSTRAINT fk_entitlement_audit_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_entitlement_audit_actor
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
