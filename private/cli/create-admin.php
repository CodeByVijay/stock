<?php

declare(strict_types=1);

/**
 * One-time CLI script to create the initial administrator.
 *
 * Usage:
 *   php /path/to/private/cli/create-admin.php --email=you@example.com --name="Your Name"
 *
 * Options:
 *   --email=   Required. Login email.
 *   --name=    Required. Display name.
 *   --force    Allow creating another admin only if you intentionally need a second row (default: refuse when any admin exists).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$appRoot = dirname(__DIR__, 2);
define('APP_ROOT', $appRoot);
define('PRIVATE_ROOT', $appRoot . '/private');
require_once $appRoot . '/includes/functions.php';

$options = getopt('', ['email:', 'name:', 'force']);

$email = isset($options['email']) ? trim((string) $options['email']) : '';
$name = isset($options['name']) ? trim((string) $options['name']) : '';
$force = array_key_exists('force', $options);

if ($email === '' || $name === '') {
    fwrite(STDERR, "Error: --email and --name are required.\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: invalid email address.\n");
    exit(1);
}

$email = mb_strtolower($email);

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if ($count > 0 && !$force) {
    fwrite(STDERR, "Error: an administrator already exists. Refusing to create another (use --force to override).\n");
    exit(1);
}

echo "Enter password for new admin (min 10 characters): ";
$password = read_password_stdin();
if (strlen($password) < 10) {
    fwrite(STDERR, "Error: password must be at least 10 characters.\n");
    exit(1);
}

echo "Confirm password: ";
$confirm = read_password_stdin();
if ($password !== $confirm) {
    fwrite(STDERR, "Error: passwords do not match.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare(
    'INSERT INTO admin_users (display_name, email, password_hash, email_verified_at)
     VALUES (:name, :email, :hash, NOW())'
);

try {
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'hash' => $hash,
    ]);
} catch (PDOException $e) {
    if ((int) $e->errorInfo[1] === 1062) {
        fwrite(STDERR, "Error: that email is already registered.\n");
        exit(1);
    }
    fwrite(STDERR, "Error: could not create administrator.\n");
    exit(1);
}

echo "Administrator created successfully for {$email}.\n";
exit(0);

function read_password_stdin(): string
{
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && function_exists('shell_exec') && trim((string) shell_exec('which stty 2>/dev/null')) !== '') {
        shell_exec('stty -echo 2>/dev/null');
        $line = fgets(STDIN);
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;

        return rtrim((string) $line, "\r\n");
    }

    return rtrim((string) fgets(STDIN), "\r\n");
}
