<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once PRIVATE_ROOT . '/services/upstox_oauth.php';

require_login();
auth_release_session_lock();

$pageTitle = 'Integrations';
$activeNav = 'settings_upstox';
$settingsNav = 'upstox';
$loadDashboardCss = true;
$loadSettingsJs = true;

try {
    $public = settings_public_upstox(upstox_config());
} catch (Throwable $e) {
    $public = settings_public_upstox([]);
}

$defaultRedirect = upstox_default_redirect_uri();
$flashOk = flash_get('success');
$flashErr = flash_get('error');
$oauthNotice = isset($_GET['oauth']) ? (string) $_GET['oauth'] : '';
$oauthError = $oauthNotice === 'error' ? (string) flash_get('oauth_error') : '';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column dashboard-shell">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained max-w-3xl space-y-6">
        <?php
        $ux = portal_page_copy('settings_upstox');
        portal_render_page_header($ux['title'], $ux['subtitle']);
        ?>

        <?php include __DIR__ . '/includes/settings_nav.php'; ?>

        <div id="settings-flash" class="hidden rounded-lg px-4 py-3 text-sm" role="status"></div>
        <?php if ($flashOk): ?>
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100"><?= e($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr): ?>
            <div class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><?= e($flashErr) ?></div>
        <?php endif; ?>
        <?php if ($oauthNotice === 'success'): ?>
            <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">Upstox authorization completed. Access token saved securely.</div>
        <?php elseif ($oauthNotice === 'error'): ?>
            <div class="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100"><?= e($oauthError !== '' ? $oauthError : 'Authorization failed.') ?></div>
        <?php endif; ?>

        <section class="dash-panel p-6 space-y-5" id="settings-upstox-root"
                 data-csrf="<?= e(csrf_token()) ?>"
                 data-default-redirect="<?= e($defaultRedirect) ?>"
                 data-public="<?= e(json_encode($public, JSON_THROW_ON_ERROR)) ?>">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-medium text-slate-200">Broker credentials</h3>
                <span id="upstox-status-badge" class="text-xs px-2 py-0.5 rounded-full border border-surface-border text-slate-400">Loading…</span>
            </div>

            <p class="text-xs text-slate-500">Register this redirect URI in your Upstox developer app (must match how you open this portal): <code class="text-slate-300"><?= e($defaultRedirect) ?></code></p>
            <div id="upstox-redirect-warn" class="hidden rounded-lg border border-amber-500/35 bg-amber-500/10 px-3 py-2 text-xs text-amber-100">
                Saved redirect URI does not match this browser URL.
                Use <code class="text-amber-50" data-recommended></code> in both Upstox developer settings and the field below.
                <button type="button" id="btn-upstox-use-redirect" class="ml-1 underline text-amber-200 hover:text-white">Apply suggested URI</button>
            </div>

            <form id="form-upstox-settings" class="space-y-4" autocomplete="off">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="upstox-client-id">API key / Client ID</label>
                        <input id="upstox-client-id" name="client_id" type="text" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm" autocomplete="off">
                    </div>
                    <div>
                        <label class="block text-xs text-slate-500 mb-1" for="upstox-client-secret">API secret / Client secret</label>
                        <input id="upstox-client-secret" name="client_secret" type="password" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm" placeholder="Leave blank to keep saved secret" autocomplete="new-password">
                        <p id="upstox-secret-hint" class="text-[10px] text-slate-500 mt-1"></p>
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-slate-500 mb-1" for="upstox-redirect">Redirect URI</label>
                    <input id="upstox-redirect" name="redirect_uri" type="url" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm">
                    <p class="text-[10px] text-slate-500 mt-1">Must exactly match your Upstox app registration.</p>
                </div>

                <div>
                    <label class="block text-xs text-slate-500 mb-1" for="upstox-token">Access token (optional manual paste)</label>
                    <input id="upstox-token" name="access_token" type="password" class="w-full rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm" placeholder="Prefer Authorize button — leave blank to keep saved token" autocomplete="off">
                    <p id="upstox-token-hint" class="text-[10px] text-slate-500 mt-1"></p>
                </div>

                <details class="text-sm text-slate-400">
                    <summary class="cursor-pointer text-slate-300">Default instrument (CLI / manual sync)</summary>
                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <input name="default_instrument_key" id="upstox-default-key" type="text" placeholder="Instrument key" class="rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm">
                        <input name="default_symbol" id="upstox-default-symbol" type="text" placeholder="Symbol" class="rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm">
                        <input name="default_name" id="upstox-default-name" type="text" placeholder="Name" class="rounded-lg border border-surface-border bg-slate-900/80 px-3 py-2 text-sm sm:col-span-2">
                    </div>
                </details>

                <p id="upstox-meta" class="text-xs text-slate-500"></p>

                <div class="flex flex-wrap gap-2 pt-2">
                    <button type="submit" class="px-4 py-2 rounded-lg bg-violet-600 hover:bg-violet-500 text-sm font-medium">Save credentials</button>
                    <a href="upstox-oauth-start.php" class="px-4 py-2 rounded-lg border border-violet-500/40 text-sm text-violet-100 hover:bg-violet-500/10 inline-flex items-center">Authorize with Upstox</a>
                    <button type="button" id="btn-upstox-test" class="portal-btn portal-btn--ghost">Test connection</button>
                    <button type="button" id="btn-upstox-clear" class="px-4 py-2 rounded-lg border border-red-500/30 text-sm text-red-200 hover:bg-red-500/10">Clear secrets…</button>
                </div>
            </form>

            <p class="text-xs text-slate-500 border-t border-surface-border pt-4">Standard Upstox access tokens expire at <strong class="text-slate-400">3:30 AM IST</strong> the next trading day. Re-authorize when expired. Automatic refresh is not available for the standard token.</p>
        </section>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
