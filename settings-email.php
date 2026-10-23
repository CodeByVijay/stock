<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once PRIVATE_ROOT . '/services/settings_mail.php';

require_login();
auth_release_session_lock();

$pageTitle = 'Notifications';
$activeNav = 'settings_email';
$settingsNav = 'email';
$loadDashboardCss = true;
$loadSettingsJs = true;

$public = settings_public_mail(settings_mail_full());
$testRecipient = (string) ($_SESSION['admin_email'] ?? '');
$flashOk = flash_get('success');
$flashErr = flash_get('error');

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column dashboard-shell">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained max-w-3xl space-y-6">
        <?php
        $mailCopy = portal_page_copy('settings_email');
        portal_render_page_header($mailCopy['title'], $mailCopy['subtitle']);
        ?>

        <?php include __DIR__ . '/includes/settings_nav.php'; ?>

        <div id="settings-flash" class="hidden rounded-lg px-4 py-3 text-sm" role="status"></div>
        <?php if ($flashOk): ?>
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"><?= e($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr): ?>
            <div class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><?= e($flashErr) ?></div>
        <?php endif; ?>

        <section class="dash-panel p-6 space-y-5" id="settings-email-root"
                 data-csrf="<?= e(csrf_token()) ?>"
                 data-test-recipient="<?= e($testRecipient) ?>"
                 data-public="<?= e(json_encode($public, JSON_THROW_ON_ERROR)) ?>">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-medium text-slate-200">SMTP configuration</h3>
                <span id="mail-status-badge" class="text-xs px-2 py-0.5 rounded-full border border-surface-border text-slate-400">Loading…</span>
            </div>

            <form id="form-mail-settings" class="space-y-4" autocomplete="off">
                <label class="flex items-center gap-2 text-sm text-slate-300">
                    <input type="checkbox" name="enabled" id="mail-enabled" class="rounded border-surface-border">
                    Enable outbound email
                </label>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="mail-host">SMTP host</label>
                        <input id="mail-host" name="host" type="text" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm" placeholder="smtp.example.com">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="mail-port">SMTP port</label>
                        <input id="mail-port" name="port" type="number" min="1" max="65535" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm" placeholder="587">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="mail-user">SMTP username</label>
                        <input id="mail-user" name="username" type="text" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm" autocomplete="off">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="mail-pass">SMTP password</label>
                        <input id="mail-pass" name="password" type="password" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm" placeholder="Leave blank to keep saved password" autocomplete="new-password">
                        <p id="mail-pass-hint" class="text-[10px] text-slate-500 mt-1"></p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-slate-500 mb-1" for="mail-encryption">Encryption</label>
                    <select id="mail-encryption" name="encryption" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm">
                        <option value="none">None</option>
                        <option value="tls">STARTTLS</option>
                        <option value="ssl">SSL/TLS</option>
                    </select>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="mail-from-email">Sender email</label>
                        <input id="mail-from-email" name="from_email" type="email" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="mail-from-name">Sender name</label>
                        <input id="mail-from-name" name="from_name" type="text" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-slate-500 mb-1" for="mail-reply">Reply-To (optional)</label>
                    <input id="mail-reply" name="reply_to" type="email" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm">
                </div>

                <p id="mail-meta" class="text-xs text-slate-500"></p>

                <div class="flex flex-wrap gap-2 pt-2">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-sm font-medium">Save settings</button>
                    <button type="button" id="btn-mail-test-conn" class="px-4 py-2 rounded-lg border border-surface-border text-sm hover:bg-slate-800">Test connection</button>
                    <button type="button" id="btn-mail-test-send" class="px-4 py-2 rounded-lg border border-surface-border text-sm hover:bg-slate-800">Send test email…</button>
                </div>
            </form>
        </section>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
