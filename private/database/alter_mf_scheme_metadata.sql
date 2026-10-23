-- MFapi.in meta fields from GET /mf/{scheme_code} (run once on existing databases).

ALTER TABLE mutual_fund_schemes
    ADD COLUMN scheme_type VARCHAR(255) NULL AFTER scheme_category,
    ADD COLUMN isin_growth VARCHAR(32) NULL AFTER scheme_type,
    ADD COLUMN isin_div_reinvestment VARCHAR(32) NULL AFTER isin_growth,
    ADD COLUMN meta_source VARCHAR(64) NULL AFTER isin_div_reinvestment,
    ADD COLUMN meta_synced_at DATETIME NULL AFTER meta_source;
