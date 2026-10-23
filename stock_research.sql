-- Adminer 5.4.4 MySQL 8.0.46-0ubuntu0.24.04.4 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `session_version` int unsigned NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admin_users` (`id`, `display_name`, `email`, `password_hash`, `email_verified_at`, `session_version`, `is_active`, `created_at`, `updated_at`) VALUES
(1,	'Vijay Amule',	'admin@gmail.com',	'$2y$12$j/Gi.wDMkJ3SU2A.npGkQengsILHzpc2vYn//irQNgdPPhq/2HoxG',	'2026-10-21 15:23:29',	1,	1,	'2026-10-21 15:23:29',	'2026-10-21 15:23:29');

DROP TABLE IF EXISTS `backtest_runs`;
CREATE TABLE `backtest_runs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `strategy_name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_type` enum('stock','etf','mutual_fund') COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `horizon_value` int unsigned NOT NULL,
  `horizon_unit` enum('days','months','years') COLLATE utf8mb4_unicode_ci NOT NULL,
  `settings_json` json NOT NULL,
  `summary_json` json DEFAULT NULL,
  `status` enum('queued','running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `daily_prices`;
CREATE TABLE `daily_prices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int unsigned NOT NULL,
  `trade_date` date NOT NULL,
  `open` decimal(18,4) DEFAULT NULL,
  `high` decimal(18,4) DEFAULT NULL,
  `low` decimal(18,4) DEFAULT NULL,
  `close` decimal(18,4) NOT NULL,
  `volume` bigint unsigned DEFAULT NULL,
  `source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_daily_instrument_date` (`instrument_id`,`trade_date`),
  KEY `idx_daily_trade_date` (`trade_date`),
  CONSTRAINT `fk_daily_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `instrument_data_coverage`;
CREATE TABLE `instrument_data_coverage` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int unsigned NOT NULL,
  `earliest_trade_date` date DEFAULT NULL,
  `latest_trade_date` date DEFAULT NULL,
  `bar_count` int unsigned NOT NULL DEFAULT '0',
  `source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_fetched_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_coverage_instrument` (`instrument_id`),
  CONSTRAINT `fk_coverage_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `instruments`;
CREATE TABLE `instruments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `symbol` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exchange` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instrument_type` enum('stock','etf','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stock',
  `provider` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `provider_instrument_key` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `isin` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `listing_status` enum('active','inactive','suspended','delisted','unknown') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `security_category` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `master_source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `master_synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_instruments_exchange_symbol` (`exchange`,`symbol`),
  KEY `idx_instruments_symbol` (`symbol`),
  KEY `idx_instruments_type_active` (`instrument_type`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `intraday_prices`;
CREATE TABLE `intraday_prices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int unsigned NOT NULL,
  `interval_minutes` smallint unsigned NOT NULL,
  `candle_time` datetime NOT NULL,
  `open` decimal(18,4) DEFAULT NULL,
  `high` decimal(18,4) DEFAULT NULL,
  `low` decimal(18,4) DEFAULT NULL,
  `close` decimal(18,4) NOT NULL,
  `volume` bigint unsigned DEFAULT NULL,
  `source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_intraday_candle` (`instrument_id`,`interval_minutes`,`candle_time`),
  CONSTRAINT `fk_intraday_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `mf_nav_coverage`;
CREATE TABLE `mf_nav_coverage` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scheme_id` int unsigned NOT NULL,
  `earliest_nav_date` date DEFAULT NULL,
  `latest_nav_date` date DEFAULT NULL,
  `nav_count` int unsigned NOT NULL DEFAULT '0',
  `source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_fetched_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mf_coverage_scheme` (`scheme_id`),
  CONSTRAINT `fk_mf_coverage_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `mutual_fund_schemes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `mutual_fund_nav`;
CREATE TABLE `mutual_fund_nav` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `scheme_id` int unsigned NOT NULL,
  `nav_date` date NOT NULL,
  `nav` decimal(18,6) NOT NULL,
  `source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fetched_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mf_nav_scheme_date` (`scheme_id`,`nav_date`),
  CONSTRAINT `fk_nav_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `mutual_fund_schemes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `mutual_fund_schemes`;
CREATE TABLE `mutual_fund_schemes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scheme_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scheme_name` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fund_house` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheme_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheme_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `isin_growth` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `isin_div_reinvestment` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_synced_at` datetime DEFAULT NULL,
  `plan_option` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `master_source` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `master_synced_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mf_scheme_code` (`scheme_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `admin_user_id` int unsigned NOT NULL,
  `token_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reset_user` (`admin_user_id`),
  KEY `idx_reset_expires` (`expires_at`),
  CONSTRAINT `fk_reset_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `prediction_outcomes`;
CREATE TABLE `prediction_outcomes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prediction_result_id` bigint unsigned NOT NULL,
  `evaluated_at` datetime NOT NULL,
  `outcome_status` enum('target_reached','target_not_reached','not_evaluable') COLLATE utf8mb4_unicode_ci NOT NULL,
  `realized_return_percent` decimal(10,4) DEFAULT NULL,
  `max_drawdown_percent` decimal(10,4) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `idx_po_result` (`prediction_result_id`),
  CONSTRAINT `fk_po_result` FOREIGN KEY (`prediction_result_id`) REFERENCES `prediction_results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `prediction_results`;
CREATE TABLE `prediction_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `run_id` int unsigned NOT NULL,
  `instrument_id` int unsigned DEFAULT NULL,
  `scheme_id` int unsigned DEFAULT NULL,
  `rank_position` int unsigned NOT NULL,
  `base_value` decimal(18,6) NOT NULL,
  `target_value` decimal(18,6) NOT NULL,
  `technical_score` decimal(10,4) DEFAULT NULL,
  `risk_note` text COLLATE utf8mb4_unicode_ci,
  `prediction_date` date NOT NULL,
  `horizon_end_date` date NOT NULL,
  `data_timestamp` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pr_run_rank` (`run_id`,`rank_position`),
  KEY `fk_pr_instrument` (`instrument_id`),
  KEY `fk_pr_scheme` (`scheme_id`),
  CONSTRAINT `fk_pr_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pr_run` FOREIGN KEY (`run_id`) REFERENCES `prediction_runs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pr_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `mutual_fund_schemes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `prediction_runs`;
CREATE TABLE `prediction_runs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `settings_snapshot_json` json NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `status` enum('queued','running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `scan_stats_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prediction_runs_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `prediction_settings`;
CREATE TABLE `prediction_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_type` enum('stock','etf','mutual_fund') COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_count` int unsigned NOT NULL,
  `target_percent` decimal(10,4) NOT NULL,
  `horizon_value` int unsigned NOT NULL,
  `horizon_unit` enum('days','months','years') COLLATE utf8mb4_unicode_ci NOT NULL,
  `universe_type` enum('all','watchlist') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `security_logs`;
CREATE TABLE `security_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_security_event_time` (`event_type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `sync_jobs`;
CREATE TABLE `sync_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `job_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` json DEFAULT NULL,
  `status` enum('queued','running','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `progress_total` int unsigned DEFAULT NULL,
  `progress_done` int unsigned DEFAULT NULL,
  `records_processed` int unsigned NOT NULL DEFAULT '0',
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `max_attempts` tinyint unsigned NOT NULL DEFAULT '3',
  `lock_until` datetime DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `result_json` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sync_jobs_status_type` (`status`,`job_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `technical_indicators`;
CREATE TABLE `technical_indicators` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int unsigned NOT NULL,
  `timeframe` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'daily',
  `calculated_at` datetime NOT NULL,
  `sma_20` decimal(18,6) DEFAULT NULL,
  `sma_50` decimal(18,6) DEFAULT NULL,
  `sma_200` decimal(18,6) DEFAULT NULL,
  `ema_20` decimal(18,6) DEFAULT NULL,
  `rsi_14` decimal(10,4) DEFAULT NULL,
  `macd` decimal(18,6) DEFAULT NULL,
  `macd_signal` decimal(18,6) DEFAULT NULL,
  `volatility` decimal(18,6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ti_instrument_time` (`instrument_id`,`calculated_at`),
  CONSTRAINT `fk_ti_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `watchlist`;
CREATE TABLE `watchlist` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `instrument_id` int unsigned NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_watchlist_instrument` (`instrument_id`),
  CONSTRAINT `fk_watchlist_instrument` FOREIGN KEY (`instrument_id`) REFERENCES `instruments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2026-10-21 11:48:12 UTC
