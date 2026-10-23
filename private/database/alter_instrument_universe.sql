-- Instrument universe & sync stats (run once on existing databases).

ALTER TABLE instruments
    ADD COLUMN isin VARCHAR(20) NULL AFTER provider_instrument_key,
    ADD COLUMN listing_status ENUM('active','inactive','suspended','delisted','unknown') NOT NULL DEFAULT 'unknown' AFTER is_active,
    ADD COLUMN security_category VARCHAR(64) NULL AFTER listing_status,
    ADD COLUMN master_source VARCHAR(64) NULL AFTER security_category,
    ADD COLUMN master_synced_at DATETIME NULL AFTER master_source;

ALTER TABLE instruments
    ADD UNIQUE KEY uk_instruments_exchange_symbol (exchange, symbol);

ALTER TABLE mutual_fund_schemes
    ADD COLUMN plan_option VARCHAR(128) NULL AFTER scheme_category,
    ADD COLUMN master_source VARCHAR(64) NULL AFTER plan_option,
    ADD COLUMN master_synced_at DATETIME NULL AFTER master_source;

ALTER TABLE sync_jobs
    ADD COLUMN result_json JSON NULL AFTER error_message;

ALTER TABLE prediction_runs
    ADD COLUMN scan_stats_json JSON NULL AFTER error_message;

CREATE TABLE IF NOT EXISTS instrument_data_coverage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    instrument_id INT UNSIGNED NOT NULL,
    earliest_trade_date DATE NULL,
    latest_trade_date DATE NULL,
    bar_count INT UNSIGNED NOT NULL DEFAULT 0,
    source VARCHAR(64) NULL,
    last_fetched_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_coverage_instrument (instrument_id),
    CONSTRAINT fk_coverage_instrument FOREIGN KEY (instrument_id) REFERENCES instruments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mf_nav_coverage (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scheme_id INT UNSIGNED NOT NULL,
    earliest_nav_date DATE NULL,
    latest_nav_date DATE NULL,
    nav_count INT UNSIGNED NOT NULL DEFAULT 0,
    source VARCHAR(64) NULL,
    last_fetched_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_mf_coverage_scheme (scheme_id),
    CONSTRAINT fk_mf_coverage_scheme FOREIGN KEY (scheme_id) REFERENCES mutual_fund_schemes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
