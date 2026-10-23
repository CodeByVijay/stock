# Personal Indian Market Research Portal --- Part 1 Specification

## 1. Project Overview

Build a **private, single-admin Indian stock market research portal**
using simple Core PHP and MySQL. The portal will be hosted on the user's
existing cPanel hosting and domain. The goal is to help the owner
research Indian stocks, ETFs, and mutual funds from one place using
available free data sources, local historical storage, technical
indicators, a configurable screener, and basic backtesting.

This is a personal research tool, not a public financial-advice service
and not an automated trading system.

### Primary constraints

-   Keep total additional recurring cost at **₹0 wherever practical**.
-   Use Core PHP (no Laravel or complex framework).
-   Use MySQL/MariaDB with PDO prepared statements.
-   Use HTML, CSS, jQuery, Tailwind CSS CDN, and Chart.js.
-   Keep code straightforward and understandable to a junior PHP
    developer.
-   Do not add unnecessary classes, abstraction layers, build systems,
    or dependencies.
-   Do not implement broker order placement or automated trading.
-   Do not invent prices, API responses, historical values, prediction
    results, or confidence percentages.
-   If data is unavailable, stale, incomplete, or an API fails, show
    that state clearly.
-   All predictions are estimates and must never be represented as
    guaranteed returns.
-   The site is private and single-admin. No public registration or
    multi-role system is required.

## 2. Technology and Hosting

### Required stack

-   PHP 8.2 or newer (use the version supported by cPanel).
-   MySQL/MariaDB.
-   PDO for database access.
-   HTML5, custom CSS, jQuery, Tailwind CSS CDN, Chart.js.
-   cPanel hosting with SSH/Terminal and Cron Jobs, subject to actual
    provider settings.
-   HTTPS enabled for the domain/subdomain.

### Hosting assumptions to verify before deployment

Before writing deployment-specific commands, confirm: - Actual cPanel
username and home directory. - Domain/subdomain document root. - PHP CLI
executable path and version (`php -v`, `which php`). - MySQL database
name and username (cPanel prefixes may apply). - Whether outbound HTTPS
requests are allowed from PHP. - Whether cURL and JSON PHP extensions
are enabled. - Cron Jobs availability and server timezone. - Available
disk space, memory, execution time, and API rate limits.

Never hardcode the user's real cPanel username, passwords, API tokens,
domain, or absolute path into source control or examples.

## 3. Simple Folder Structure

Keep private configuration, job scripts, logs, and service code outside
the public web root whenever the hosting allows it.

Suggested structure:

``` text
/home/CPANEL_USER/
├── stock-research-private/
│   ├── config/
│   │   └── config.php
│   ├── services/
│   │   ├── upstox.php
│   │   ├── mfapi.php
│   │   ├── indicators.php
│   │   └── predictions.php
│   ├── cron/
│   │   ├── process-queue.php
│   │   ├── sync-stocks.php
│   │   ├── sync-funds.php
│   │   └── calculate-signals.php
│   ├── logs/
│   └── database/
│       └── schema.sql
└── public_html/
    └── research/
        ├── index.php
        ├── login.php
        ├── logout.php
        ├── forgot-password.php
        ├── reset-password.php
        ├── profile.php
        ├── stocks.php
        ├── stock-details.php
        ├── mutual-funds.php
        ├── etfs.php
        ├── predictions.php
        ├── backtesting.php
        ├── sync-center.php
        ├── api/
        │   ├── dashboard-data.php
        │   ├── stock-data.php
        │   ├── fund-data.php
        │   ├── start-job.php
        │   └── job-status.php
        ├── includes/
        │   ├── auth.php
        │   ├── csrf.php
        │   ├── header.php
        │   ├── sidebar.php
        │   ├── navbar.php
        │   ├── footer.php
        │   └── functions.php
        └── assets/
            ├── css/style.css
            └── js/
                ├── app.js
                ├── dashboard.js
                └── stocks.js
```

Adjust this layout to the real document root. If the hosting does not
allow all PHP files to live outside the document root, deny direct HTTP
access to private folders using server configuration and ensure no
secrets or logs are exposed.

Do not create every file empty at the start. Build in small, tested
stages.

## 4. Version 1 Features

### 4.1 Secure admin account

Single-admin functionality only: - Login and logout. - Forgot password
and reset password using a one-time, expiring token. - Change profile
display name. - Change login email with current-password verification
and verification of the new email where practical. - Change password
with current-password verification. - Invalidate other sessions after
password reset/change. - Rate-limit failed logins and password-reset
requests. - Do not reveal whether an email exists in forgot-password
responses. - No public signup or user-management UI.

If no free/reliable SMTP is available, do not pretend password-reset
email was sent. Clearly document the missing mail setup and provide a
secure one-time initial admin setup method. Do not add an unsafe public
reset backdoor.

### 4.2 Dashboard

Create a responsive, attractive, dark financial dashboard: - Sidebar
navigation and top navbar. - Summary cards for latest available market
data, watchlist count, last sync status, and latest screener run. -
Stock/ETF/fund module shortcuts. - Historical charts using Chart.js. -
Loading states, empty states, error messages, and last-updated
timestamps. - Subtle CSS animations; avoid excessive animations or
visual clutter. - Use Tailwind CSS CDN as requested. Note that CDN use
requires internet connectivity. - Never show hardcoded sample prices as
real data. If sample UI data is needed during development, label it
clearly as demo data and remove/disable it for production.

### 4.3 Stocks

-   Search and view instruments available in the local database.
-   Store exchange, symbol, provider instrument identifier, instrument
    type, and active status.
-   View daily OHLCV historical prices.
-   View intraday candles only where a provider endpoint and account
    terms permit it.
-   Show data timestamp/source and stale-data warnings.
-   Basic technical indicators: SMA, EMA, RSI, MACD, and volatility.
-   No buy/sell guarantee.

### 4.4 ETFs

-   Use the available instrument and price data source.
-   Store ETFs separately by instrument type or clearly classify them in
    the common instrument table.
-   Show historical prices, volume where available, and technical
    indicators.
-   Do not claim tracking difference, expense ratio, bid/ask spread, or
    underlying index data unless a reliable source actually provides
    those fields.

### 4.5 Mutual funds

-   Search schemes and store scheme code/name/fund house when provided
    by the source.
-   Store historical NAV by scheme and NAV date.
-   Display NAV history and simple returns for supported time periods.
-   Distinguish plan/option only when the source data makes that
    distinction clear.
-   Do not treat NAV as an intraday traded stock price.
-   Cache responses and respect source rate limits.

### 4.6 Data Sync Center

Provide visible buttons: - Fetch/Update Stocks. - Fetch/Update Intraday
Data (only if supported and enabled). - Fetch/Update ETF Data. - Sync
Mutual Fund Schemes/NAV. - Recalculate Indicators. - Run Prediction
Screener. - Run Backtest. - Sync All (queue supported jobs rather than
making a long synchronous request).

Show job status: - Queued - Running - Completed - Failed

Also show start/end time, records processed, concise error message, and
last successful sync. Use jQuery AJAX to start jobs and poll job status
every 2--5 seconds while a job is active. Stop polling when terminal
status is reached.

### 4.7 Scheduled/background processing

-   Use MySQL as a simple job queue; do not require Redis or paid queue
    infrastructure.
-   A button must validate authentication, authorization, CSRF token,
    and job type, then create a job row.
-   A PHP CLI worker processes queued jobs in bounded batches.
-   Cron may run the worker periodically if the hosting supports it.
-   Prevent duplicate simultaneous sync jobs for the same provider/job
    type.
-   Add a lock/lease or atomic status update so two workers cannot
    process the same job.
-   Add a maximum retry count and retry only transient failures.
-   Never let a browser request run a large data download synchronously.
-   Do not expose worker scripts as public web endpoints.
-   If shared hosting restrictions prevent reliable background
    execution, document the limitation and use the safest supported
    alternative rather than claiming true background processing.

### 4.8 Core Feature: Custom Target-Growth Prediction Screener

This is one of the portal's primary features and must not be skipped or reduced to a simple stock list. The admin must be able to ask a question such as: **"From the available stocks, which Top 5 / Top 10 / Top 3 candidates have the strongest data-supported technical setup for a user-defined target growth of 8% within 30 days?"** The number of candidates, target percentage, and time horizon must all be editable by the admin.

#### Admin inputs

Provide a clear prediction form with these controls:

1. **Asset category:** Stocks, ETFs, or mutual funds. Run one category at a time because their pricing and behaviour differ.
2. **How many results:** quick choices Top 3, Top 5, Top 10, plus a custom numeric input. The admin can enter any positive integer within a documented safe maximum. Validate on both client and server.
3. **Target increase (%):** numeric input entered by the admin, e.g. `5`, `8`, or `12.5`. Do not hardcode a fixed target percentage.
4. **Time horizon:** a positive numeric value entered by the admin plus a unit selector: Days, Months, or Years. Examples: `15 Days`, `3 Months`, `1 Year`. Store the numeric value and unit separately. Convert the horizon into an evaluation/end date consistently; document the calendar-day/trading-day convention used for each asset type.
5. **Candidate universe:** all active instruments available in the local database, or only the admin's watchlist. Never claim complete NSE/BSE coverage unless the stored instrument universe is verified as complete.
6. **Optional filters:** only expose filters backed by real stored data, such as minimum liquidity/volume or a minimum amount of history. Hide unsupported filters rather than showing controls that do nothing.
7. **Run prediction button:** enqueue a background job and show queued/running/completed/failed status and progress. Do not perform a long market-data scan inside the normal browser request.

The admin must be able to change these inputs and run the screener again at any time. Save a snapshot of the settings with every run so old results remain understandable even after settings change.

#### What the screener should calculate and show

For each completed run, display up to the requested Top N candidates, ranked from strongest to weakest under the documented scoring rules. Include:

- Rank, company/scheme name, symbol or scheme code, and asset type.
- Latest available price or mutual fund NAV, its market/NAV date, source, and last-fetch timestamp.
- Admin's target growth percentage and selected horizon.
- **Calculated target value:** `target_value = base_value * (1 + target_percent / 100)`. Show the arithmetic clearly; this target is a benchmark to evaluate, not a promise that the asset will reach it.
- Technical score and its scale, for example 0–100, with the scoring method documented. Explain the factors that contributed (such as trend versus moving averages, RSI, MACD, momentum, and volatility) and show unavailable factors as missing—not as zero or fabricated values.
- A short reason/summary for why the candidate ranked where it did, plus risk/volatility and data-quality warnings where supported.
- Forecast start date and evaluation/end date, with the horizon convention clearly stated.
- Data freshness and sufficient-history status. Exclude or clearly flag instruments with stale, incomplete, or insufficient data.
- Historical target-hit rate or calibrated probability **only** if enough valid out-of-sample/backtest evidence exists. Otherwise show `Not established` rather than inventing a probability.

#### Ranking and prediction rules

- Version 1 should use transparent, explainable, rule-based technical scoring; no paid AI API or paid ML service is required. Do not imply that an indicator score is a probability of profit.
- Build and document separate scoring/validation rules for stocks, ETFs, and mutual funds. Mutual fund NAVs are generally daily data and must not be treated like intraday traded stock prices.
- Rank eligible instruments by the calculated score and return at most the requested N results. If fewer than N instruments pass data-quality checks, show fewer and explain why; do not fill the list with fake or invalid candidates.
- Do not simply choose assets because their target price is higher. The user-entered percentage defines the target benchmark; the ranking logic evaluates historical data/technical conditions for the chosen horizon.
- Use only information available on the prediction run date. No look-ahead leakage in indicators, scoring, evaluation, or backtests.
- Save every run's inputs, timestamp, data timestamps, ranking, scores, base/target values, horizon end date, and reasons. Results must be reproducible from stored inputs/data where possible.
- After the horizon has elapsed, evaluate the saved prediction against subsequent real market data and mark outcomes such as `Target reached`, `Target not reached by horizon`, or `Not evaluable` (for missing/invalid data). Store realized return and, where feasible, maximum adverse movement. Define whether target-hit evaluation uses daily high or closing price; state that convention in the UI and apply it consistently.
- A target being touched intraday is not the same as closing above the target. The evaluation convention must be explicit. Do not label an unevaluated prediction as successful.
- Make clear that these are research estimates, not guaranteed returns or personalised financial advice. Do not place trades or send broker orders.

#### Example of expected admin workflow

1. Select `Stocks`.
2. Select `Top 5` (or enter `3`, `10`, `20`, etc.).
3. Enter target growth `8%`.
4. Enter horizon `30` and choose `Days`.
5. Choose `Watchlist` or `All available stocks in database`.
6. Click `Run Prediction`.
7. The portal processes the request in the background and presents the ranked candidates, target values, technical-score explanations, data timestamps, and risk/data warnings.
8. Save the run and later evaluate which candidates actually reached the target within the selected horizon.

The same workflow must work for ETFs and mutual funds with asset-appropriate data handling. The inputs must not be limited to the example values above.

### 4.9 Basic backtesting

-   Let the user select an instrument/category, strategy rules, date
    range, and forecast horizon.
-   Calculate outcomes only from data that would have been available on
    the prediction date.
-   Include fees/slippage assumptions as configurable inputs where
    applicable.
-   Report number of cases/trades, target-hit rate, average return,
    negative outcomes, and maximum drawdown where the method supports
    it.
-   Avoid look-ahead bias and survivorship bias where possible; document
    limitations, particularly if historical universe membership is not
    available.
-   Clearly label backtest results as historical, not a guarantee of
    future performance.

## 5. Data Sources and Cost Policy

Initial sources: - Upstox developer API/Analytics Token for available
stock and ETF data, subject to current endpoint access, rate limits,
authentication, and usage terms. - MFapi.in for mutual fund scheme/NAV
data, subject to current documentation and rate limits. - Official
exchange/AMFI sources for reference and validation where appropriate and
permitted.

Rules: - Verify current API documentation before implementing
endpoints. - Do not scrape broker websites as a workaround for
unavailable APIs. - Do not assume free access grants unrestricted
redistribution; the portal is personal/private, but still respect
provider terms. - Cache responses and use incremental updates. - Store
provider name, retrieval time, data timestamp, and sync status. - Do not
claim guaranteed real-time or unlimited historical data. - Never commit
API keys, tokens, passwords, or private config files to a repository. -
No paid API, paid AI model, or paid third-party queue service in Version
1.

## 6. Suggested MySQL Tables

Use simple tables and sensible indexes. Adjust exact fields to the
verified data sources.

1.  `admin_users`
    -   id, display_name, email, password_hash, email_verified_at,
        is_active, created_at, updated_at
2.  `password_reset_tokens`
    -   id, admin_user_id, token_hash, expires_at, used_at, created_at
3.  `instruments`
    -   id, symbol, name, exchange, instrument_type, provider,
        provider_instrument_key, is_active, created_at, updated_at
4.  `daily_prices`
    -   id, instrument_id, trade_date, open, high, low, close, volume,
        source, fetched_at
    -   Unique index on instrument_id + trade_date.
5.  `intraday_prices`
    -   id, instrument_id, interval_minutes, candle_time, open, high,
        low, close, volume, source, fetched_at
    -   Unique index on instrument_id + interval_minutes + candle_time.
6.  `mutual_fund_schemes`
    -   id, scheme_code, scheme_name, fund_house, scheme_category,
        is_active, updated_at
    -   Unique index on scheme_code.
7.  `mutual_fund_nav`
    -   id, scheme_id, nav_date, nav, source, fetched_at
    -   Unique index on scheme_id + nav_date.
8.  `watchlist`
    -   id, instrument_id, notes, created_at
    -   Single-admin app; no need for user_id unless the app later
        supports multiple users.
9.  `technical_indicators`
    -   id, instrument_id, timeframe, calculated_at, sma_20, sma_50,
        sma_200, ema_20, rsi_14, macd, macd_signal, volatility
10. `prediction_settings`
    -   id, asset_type, result_count, target_percent, horizon_value,
        horizon_unit, universe_type, created_at, updated_at
11. `prediction_runs`
    -   id, settings_snapshot_json, started_at, completed_at, status,
        error_message
12. `prediction_results`
    -   id, run_id, instrument_id or scheme_id as appropriate,
        rank_position, base_value, target_value, technical_score,
        risk_note, prediction_date, horizon_end_date, data_timestamp
13. `prediction_outcomes`
    -   id, prediction_result_id, evaluated_at, outcome_status,
        realized_return_percent, max_drawdown_percent, notes
14. `backtest_runs`
    -   id, strategy_name, asset_type, start_date, end_date, horizon,
        settings_json, summary_json, status, created_at
15. `sync_jobs`
    -   id, job_type, status, requested_at, started_at, finished_at,
        progress_total, progress_done, records_processed, attempts,
        max_attempts, lock_until, error_message
16. `security_logs`
    -   id, event_type, created_at, ip_address (only if appropriate),
        details_json
    -   Do not log passwords, reset tokens, API secrets, or session IDs.

Avoid overcomplicated polymorphic relationships where a simple separate
field/table would be clearer. Use transactions where multiple related
writes must succeed together.

## 7. Security Requirements

-   Use `password_hash()` and `password_verify()`; never store
    plain-text passwords.
-   Regenerate session ID after login and privilege changes.
-   Use secure session cookie settings: `HttpOnly`, `Secure` on HTTPS,
    and `SameSite=Lax` or stricter as compatible.
-   Add CSRF tokens to all state-changing forms/AJAX requests.
-   Use PDO prepared statements for every value-bearing SQL query.
-   Escape output with `htmlspecialchars()` using UTF-8.
-   Validate all inputs on the server.
-   Rate-limit login and reset requests.
-   Use one-time cryptographically random password-reset tokens; store
    only their hashes and expire them.
-   Invalidate reset tokens after use and invalidate existing sessions
    after password reset/change.
-   Require current password for sensitive profile/email/password
    changes.
-   Verify new email ownership where possible.
-   Use generic forgot-password responses to prevent email enumeration.
-   Do not expose exception details, SQL errors, file paths, tokens, or
    secrets to the browser.
-   Protect config, logs, cron scripts, SQL backups, and source files
    from direct HTTP access.
-   Use least-privilege MySQL account permissions.
-   Avoid `777` permissions.
-   Create an initial admin securely with a one-time CLI/setup script
    and remove/disable that script after use. Do not create public
    registration.
-   If SMTP is not available, do not implement an insecure reset bypass.

## 8. UI and Code Style

-   Use clear variable and function names.
-   Keep PHP files small and focused.
-   Prefer simple functions over complex class hierarchies.
-   Keep API fetch code, indicator calculations, database access, and
    page templates separate.
-   Use shared includes for header, sidebar, navbar, footer, auth, and
    helper functions.
-   Use consistent success/error messages.
-   Add loading indicators and disable buttons while the same job is
    being submitted.
-   Use responsive layout for desktop and mobile.
-   Use Chart.js for charts.
-   Use subtle animations only.
-   Ensure controls perform real actions; no decorative buttons that
    pretend to work.
-   Add comments for non-obvious logic, not every line.
-   Follow a small staged workflow and test each feature before
    proceeding.

## 9. Implementation Milestones

### Milestone 1 --- Project foundation

-   Confirm hosting paths, PHP CLI, cURL, JSON, MySQL, HTTPS, cron and
    SMTP status.
-   Create project directories.
-   Create database schema and connection.
-   Implement secure initial admin creation, login, logout, shared
    layout.
-   Build responsive dashboard shell.
-   Test access restrictions and database connection.

### Milestone 2 --- Data integration

-   Implement and test MFapi.in scheme/NAV fetch.
-   Implement and test Upstox authentication and one historical candle
    request.
-   Store responses in MySQL using upserts.
-   Add source timestamps, duplicate protection, and sync logs.
-   Test API errors and rate-limit handling.

### Milestone 3 --- Manual and scheduled sync

-   Add `sync_jobs` queue.
-   Implement authenticated AJAX job creation and status polling.
-   Implement bounded PHP CLI queue worker.
-   Configure Cron Jobs only after manual CLI execution succeeds.
-   Test duplicate requests, timeouts, retries and failure states.

### Milestone 4 --- Research UI

-   Stock/ETF list and details.
-   Mutual fund search and NAV history.
-   Chart.js history charts.
-   Technical indicators.
-   Watchlist and last-sync indicators.

### Milestone 5 --- Screener and backtesting

-   Configurable target percentage and duration.
-   Top N ranking per asset type.
-   Transparent scoring rules.
-   Prediction history and outcome tracking.
-   Basic backtesting with fees/limitations documented.

### Milestone 6 --- Account settings and hardening

-   Profile name/email/password change.
-   Forgot/reset password using verified email.
-   Security logs and session invalidation.
-   Backups, permission checks, deployment verification.

Do not try to implement all milestones in one code-generation pass.
Complete, test, and review one milestone at a time.

## 10. Acceptance Checklist

The project is ready for personal use when:

-   [ ] Login is required for all private pages and endpoints.
-   [ ] Admin can change profile name and password securely.
-   [ ] Email change requires appropriate verification.
-   [ ] Password reset uses expiring, single-use tokens.
-   [ ] No secrets are exposed in public files or source control.
-   [ ] Dashboard never shows fake live prices.
-   [ ] Data source and last update time are visible.
-   [ ] Stock/ETF prices and mutual fund NAV data are stored without
    duplicates.
-   [ ] Manual fetch buttons create background jobs and show real
    status.
-   [ ] Cron runs only tested CLI scripts and logs failures.
-   [ ] Duplicate jobs and overlapping workers are controlled.
-   [ ] Indicator values are calculated from stored history.
-   [ ] Admin can choose Top 3/5/10 or enter a custom Top N result count.
-   [ ] Admin can enter any valid target-growth percentage and a numeric horizon in days/months/years.
-   [ ] Prediction results include calculated target value, ranking score/reasons, price/NAV timestamp, horizon end date, and data-quality/risk warnings.
-   [ ] Each prediction run saves its settings and ranked results; later outcomes are evaluated from subsequent real data using a documented target-hit convention.
-   [ ] The screener never fabricates candidates, probabilities, prices, or successful outcomes.
-   [ ] Historical outcomes are tracked without look-ahead bias.
-   [ ] Backtesting limitations and transaction costs are disclosed.
-   [ ] Mobile layout and dashboard interactions work.
-   [ ] Backup and restore have been tested.
-   [ ] The site remains private and no order execution is enabled.

## 11. Instructions for Cursor / Claude

Work as a careful senior PHP developer guiding a junior developer.

1.  Read this entire specification before editing.
2.  Inspect the existing project first; do not overwrite existing files
    or invent the server layout.
3.  Ask for missing non-secret environment facts when required. Never
    request or print real passwords/API tokens in chat or source code.
4.  Implement only the current milestone.
5.  Before editing, list the files you intend to create/change and why.
6.  Use simple Core PHP, PDO, MySQL, jQuery, Tailwind CDN, and Chart.js
    as specified.
7.  Do not add paid APIs, paid AI services, frameworks, or unnecessary
    dependencies.
8.  Verify current provider API documentation before coding against it.
9.  Do not invent API endpoints, response schemas, availability, prices,
    or usage limits.
10. After each milestone, provide changed files, database changes, setup
    commands, tests run, test results, and remaining limitations.
11. Do not claim a feature is complete unless it has been implemented
    and tested.
12. Do not run destructive SQL, delete files, change production data, or
    modify hosting configuration without explicit approval.
13. Do not commit secrets, config files containing secrets, logs, or
    backups.
14. Use fake/demo data only when clearly labelled and ensure it is not
    mistaken for actual market data.
15. Stop after the milestone is completed and wait for the user's
    approval before proceeding to the next milestone.

## 12. Financial and Operational Disclaimer

This is a personal research and analysis tool. Technical signals, target
prices, historical hit rates, and backtests do not guarantee future
returns. Do not make automated trading decisions or place broker orders
in Version 1. Data coverage, timeliness, provider access, and usage
terms can change. The portal should clearly show stale or missing data
and should not fabricate replacements.

## Mandatory Setup Requirement: No Installer or Setup Wizard

The project must run directly after the source code is placed in the local project directory and the required PHP extensions, database, and web server are available. Do not build a browser-based installer, installation wizard, or `install.php` page.

The developer should only need to edit these configuration files:

- `config/database.php` — database host, database name, username, password, and charset.
- `config/mail.php` — SMTP host, port, username, password, encryption mode, sender email, and sender name.

Keep configuration names and setup instructions clear. Do not require users to edit application logic or scatter credentials across multiple files. Do not commit real credentials to source control. If any additional setting is genuinely required, document why and keep it in one clearly named configuration file; avoid unnecessary configuration files.

### Database setup

- Provide `database/schema.sql` containing the complete Version 1 database schema.
- The user will create the database and import `schema.sql` manually. The application must not silently create, drop, reset, or overwrite tables on normal startup.
- Fail with a clear, non-sensitive error message if the database connection or required tables are unavailable. Do not display database passwords, stack traces, or other secrets in the browser.

### Initial administrator

- Do not provide public registration or a browser-based installer for creating the first administrator.
- Provide a documented, one-time PHP CLI command/script to create the initial administrator securely. It must use `password_hash()`, validate input, and refuse to overwrite an existing administrator by default.
- Keep the CLI-only setup script inaccessible through HTTP requests.

### SMTP and password recovery

- All SMTP details must be configurable in `config/mail.php`; do not hardcode SMTP credentials or provider-specific settings in application code.
- Password recovery email must use these settings. If SMTP is not configured or sending fails, show a safe error/log entry without exposing reset tokens or credentials publicly.
- For local development, provide a documented safe testing method for password recovery that does not introduce a production-accessible token-disclosure bypass.

### Direct-run acceptance criteria

1. Edit `config/database.php` and `config/mail.php` only for environment-specific connection settings.
2. Create the database and import `database/schema.sql` once.
3. Run the documented one-time CLI command to create the initial administrator.
4. Start the local PHP/Apache server and open the project; no installer or additional code edits should be required.
5. The application must not run destructive schema changes, reset data, or recreate the administrator automatically during page loads.
