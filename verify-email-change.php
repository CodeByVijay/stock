<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/account.php';

$token = trim((string) ($_GET['token'] ?? ''));
$result = ['ok' => false, 'error' => 'Invalid link.'];

try {
    $result = account_confirm_email_change(db(), $token);
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => 'Could not verify email change.'];
}

$pageTitle = 'Verify email';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(page_title($pageTitle)) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#0f1419] text-slate-100 min-h-screen flex items-center justify-center p-4">
<div class="max-w-md text-center">
    <?php if ($result['ok']): ?>
        <p class="text-emerald-300 mb-4">Email address updated. Please sign in with your new email.</p>
        <a href="login.php" class="text-emerald-400 underline">Go to sign in</a>
    <?php else: ?>
        <p class="text-red-300 mb-4"><?= e($result['error'] ?? 'Verification failed.') ?></p>
        <a href="profile.php" class="text-slate-400 underline">Profile</a>
    <?php endif; ?>
</div>
</body>
</html>
