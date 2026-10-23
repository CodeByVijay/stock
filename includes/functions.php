<?php

declare(strict_types=1);

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (@session_start() === false) {
        throw new RuntimeException(
            'Could not start a PHP session. Check session.save_path permissions on the server.'
        );
    }
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function app_base_url(): string
{
    $path = PRIVATE_ROOT . '/config/app.php';
    $configured = '';
    if (is_readable($path)) {
        $app = require $path;
        $configured = trim((string) ($app['base_url'] ?? ''));
    }
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

    return $scheme . '://' . $host . ($dir === '' || $dir === '.' ? '' : $dir);
}

/** @return array{host:string,dbname:string,username:string,password:string,charset:string} */
function db_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = PRIVATE_ROOT . '/config/database.php';
        if (!is_readable($path)) {
            throw new RuntimeException('Database configuration is missing. Create private/config/database.php.');
        }
        $config = require $path;
        $localPath = PRIVATE_ROOT . '/config/database.local.php';
        if (is_readable($localPath)) {
            $local = require $localPath;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = db_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['dbname'],
        $cfg['charset']
    );

    try {
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException(
            'Unable to connect to the database. Check private/config/database.php and import private/database/schema.sql.'
        );
    }

    return $pdo;
}

function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ip === null || $ip === '') {
        return null;
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

function log_security_event(string $eventType, array $details = []): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO security_logs (event_type, ip_address, details_json) VALUES (:event_type, :ip, :details)'
        );
        $stmt->execute([
            'event_type' => $eventType,
            'ip' => client_ip(),
            'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    } catch (Throwable $e) {
        // Do not expose logging failures to the user.
    }
}

function flash_set(string $key, string $message): void
{
    $_SESSION['_flash'][$key] = $message;
}

function flash_get(string $key): ?string
{
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }
    $message = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);

    return $message;
}

function page_title(string $title): string
{
    return $title . ' — Stock Research';
}
