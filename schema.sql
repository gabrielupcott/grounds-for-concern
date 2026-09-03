-- Grounds for Concern — schema
-- Money is stored as integer cents. Rules are stored as JSON documents.

CREATE TABLE IF NOT EXISTS transactions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    occurred_on DATE NOT NULL,
    merchant    VARCHAR(120) NOT NULL,
    category    ENUM('home_made','bought') NOT NULL,
    amount_cents INT NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transactions_date (occurred_on),
    INDEX idx_transactions_category_date (category, occurred_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rules (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    definition JSON NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alerts (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id            INT UNSIGNED NOT NULL,
    triggered_on       DATE NOT NULL,
    window_total_cents INT NOT NULL,
    transaction_count  INT UNSIGNED NOT NULL,
    summary            VARCHAR(255) NOT NULL,
    seen               TINYINT(1) NOT NULL DEFAULT 0,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alerts_rule FOREIGN KEY (rule_id) REFERENCES rules(id) ON DELETE CASCADE,
    -- One alert per rule per day: even if the app evaluates twice, the inbox can't spam.
    UNIQUE KEY uniq_alerts_rule_day (rule_id, triggered_on),
    INDEX idx_alerts_seen (seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
