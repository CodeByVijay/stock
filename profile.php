<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/account.php';

require_login();

$pageTitle = 'Account';
$activeNav = 'profile';
$message = flash_get('success');
$error = flash_get('error');
auth_release_session_lock();

try {
    $pdo = db();
    $user = account_current($pdo);
    if (!$user) {
        flash_set('error', 'Account not found.');
        redirect('logout.php');
    }
} catch (Throwable $e) {
    flash_set('error', 'Database unavailable.');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string) ($_POST['action'] ?? '');
    $adminId = (int) $user['id'];

    try {
        if ($action === 'name') {
            account_update_display_name($pdo, $adminId, (string) ($_POST['display_name'] ?? ''));
            flash_set('success', 'Display name updated.');
        } elseif ($action === 'password') {
            account_change_password(
                $pdo,
                $adminId,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['confirm_password'] ?? '')
            );
            flash_set('success', 'Password updated. Other sessions have been signed out.');
        } elseif ($action === 'email') {
            account_request_email_change(
                $pdo,
                $adminId,
                (string) ($_POST['current_password_email'] ?? ''),
                (string) ($_POST['new_email'] ?? '')
            );
            flash_set('success', 'Verification email sent to the new address. Other sessions signed out after you confirm.');
        } else {
            flash_set('error', 'Unknown action.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('profile.php');
}

$user = account_current($pdo);
$logs = account_recent_security_logs($pdo, 30);
$smtpOk = mail_is_configured();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="portal-main-column">
    <?php include __DIR__ . '/includes/navbar.php'; ?>
    <main class="portal-main portal-main--constrained space-y-6">
        <?php if ($message): ?>
            <div class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200"><?= e($message) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="portal-card max-w-xl">
            <h2 class="text-sm font-medium text-slate-200 mb-3">Display name</h2>
            <form method="post" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="name">
                <input type="text" name="display_name" required maxlength="100"
                       value="<?= e($user['display_name']) ?>"
                       class="portal-form-input">
                <button type="submit" class="portal-btn portal-btn--primary">Save name</button>
            </form>
        </section>

        <section class="portal-card max-w-xl">
            <h2 class="text-sm font-medium text-slate-200 mb-1">Login email</h2>
            <p class="text-sm text-slate-400 mb-3">Current: <?= e($user['email']) ?></p>
            <?php if (!empty($user['pending_email'])): ?>
                <p class="text-xs text-amber-300 mb-3">Pending verification for: <?= e($user['pending_email']) ?></p>
            <?php endif; ?>
            <?php if (!$smtpOk): ?>
                <p class="text-xs text-slate-500">SMTP is not configured. Configure it under <a class="text-cyan-300 hover:underline" href="settings-email.php">Settings → Email</a>.</p>
            <?php else: ?>
                <form method="post" class="space-y-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="email">
                    <input type="email" name="new_email" required placeholder="New email"
                           class="portal-form-input">
                    <input type="password" name="current_password_email" required placeholder="Current password"
                           class="portal-form-input">
                    <button type="submit" class="portal-btn portal-btn--primary">Send verification email</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="portal-card max-w-xl">
            <h2 class="text-sm font-medium text-slate-200 mb-3">Change password</h2>
            <form method="post" class="space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <input type="password" name="current_password" required autocomplete="current-password" placeholder="Current password"
                       class="portal-form-input">
                <input type="password" name="new_password" required autocomplete="new-password" placeholder="New password (min 10 chars)"
                       class="portal-form-input">
                <input type="password" name="confirm_password" required autocomplete="new-password" placeholder="Confirm new password"
                       class="portal-form-input">
                <button type="submit" class="portal-btn portal-btn--primary">Update password</button>
            </form>
        </section>

        <section class="rounded-xl border border-surface-border bg-surface-card p-5 overflow-x-auto">
            <h2 class="text-sm font-medium text-slate-200 mb-3">Recent security activity</h2>
            <table class="w-full text-sm">
                <thead class="text-slate-400 border-b border-surface-border">
                    <tr><th class="text-left py-2">Event</th><th class="text-left py-2">Time (UTC)</th><th class="text-left py-2">IP</th></tr>
                </thead>
                <tbody class="text-slate-300">
                <?php if ($logs === []): ?>
                    <tr><td colspan="3" class="py-3 text-slate-500">No events logged yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr class="border-b border-surface-border/40">
                            <td class="py-2"><?= e($log['event_type']) ?></td>
                            <td class="py-2"><?= e($log['created_at']) ?></td>
                            <td class="py-2"><?= e((string) ($log['ip_address'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="rounded-xl border border-surface-border bg-surface-card p-5 text-sm text-slate-400 max-w-2xl">
            <h2 class="text-sm font-medium text-slate-200 mb-2">Deployment &amp; backup reminders</h2>
            <ul class="list-disc pl-5 space-y-1 text-xs">
                <li>Keep <code class="text-slate-300">private/</code> outside web root when hosting allows; HTTP access is denied via .htaccess.</li>
                <li>Do not commit <code class="text-slate-300">private/config/database.php</code>, mail credentials, or API tokens.</li>
                <li>Back up MySQL regularly: <code class="text-slate-300">mysqldump -u USER -p DBNAME &gt; backup.sql</code></li>
                <li>See <code class="text-slate-300">private/docs/DEPLOYMENT.md</code> for a short checklist.</li>
            </ul>
        </section>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
