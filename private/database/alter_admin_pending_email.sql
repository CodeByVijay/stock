-- Run once if admin_users was created before pending email columns existed.
ALTER TABLE admin_users
    ADD COLUMN pending_email VARCHAR(255) NULL AFTER email_verified_at,
    ADD COLUMN pending_email_token_hash VARCHAR(255) NULL AFTER pending_email,
    ADD COLUMN pending_email_expires_at DATETIME NULL AFTER pending_email_token_hash;
