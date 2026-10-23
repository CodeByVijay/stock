# Deployment checklist (personal hosting)

## Before go-live

1. PHP 8.2+ with extensions: `pdo_mysql`, `curl`, `json`, `openssl`, `mbstring`.  
   **Ubuntu Apache often uses PHP 7.x by default** (`libapache2-mod-php7.4`). Either enable PHP 8 for Apache (`a2dismod php7.4 && a2enmod php8.4` and restart Apache) or ensure the codebase stays PHP 7.4-compatible. CLI `php -v` may differ from the web SAPI.
2. HTTPS enabled for the site; session cookies use `Secure` on HTTPS.
3. Edit `private/config/database.php` and `private/config/mail.php` (do not commit secrets).
4. Import `private/database/schema.sql` once.
5. Create admin: `php private/cli/create-admin.php --email=... --name="..."`
6. Set `base_url` in `private/config/app.php` if auto-detect is wrong behind proxies.

## Permissions

- `private/` not web-readable (use `.htaccess` Deny or place above document root).
- `private/logs/` writable by PHP (750/770 as appropriate); never world-readable `777`.
- MySQL user: least privilege on this database only.

## Sync Center (admin panel — no manual CLI required)

When you click **Sync** in the browser:

1. A row is inserted into `sync_jobs`.
2. The app tries to start a **background drain worker** (`proc_open` → PHP CLI running `private/cron/process-queue.php --drain`).
3. If spawn is blocked (`disable_functions`, permissions, shared hosting), **Sync Center** falls back to **`api/worker-tick.php`** (same tab, periodic POSTs, one bounded job per tick).
4. Progress is polled via **`api/job-status.php`** / **`api/queue-status.php`**.

Configure CLI binary in `private/config/sync.php` → `worker.php_cli_binary` (default `/usr/bin/php`). Use **PHP 8+ CLI** even if Apache still serves PHP 7, so worker code matches what you test on the command line.

Optional local launcher (one terminal, drains the whole queue):

```bash
chmod +x private/bin/start-sync-worker.sh
./private/bin/start-sync-worker.sh
```

Logs: `private/logs/sync-worker.log`, lock: `private/logs/sync-worker.lock`.

**Apache user** (`www-data`) must be allowed to run `proc_open` and write `private/logs/`. If spawn fails, the UI shows an explicit warning instead of pretending sync started.

## Cron (optional safety net)

```bash
*/5 * * * * php /path/to/private/cron/process-queue.php --max-jobs=2 >> /path/to/private/logs/cron.log 2>&1
0 6 * * * php /path/to/private/cron/evaluate-predictions.php >> /path/to/private/logs/cron.log 2>&1
```

## Backup

```bash
mysqldump -u USER -p DBNAME > stock-research-$(date +%F).sql
```

Test restore on a non-production database before relying on backups.

## Verify

- [ ] All private pages require login.
- [ ] Password change signs out other sessions (`session_version`).
- [ ] Forgot password does not reveal account existence.
- [ ] No secrets in public git history.
