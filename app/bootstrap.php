<?php
declare(strict_types=1);

session_name('command_cabinet');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);

function env(string $key, ?string $default = null): ?string {
    static $values = null;
    if ($values === null) {
        $values = [];
        $path = dirname(__DIR__) . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) continue;
                [$name, $value] = explode('=', $line, 2);
                $values[trim($name)] = trim($value, " \t\n\r\0\x0B\"");
            }
        }
    }
    return $_ENV[$key] ?? $_SERVER[$key] ?? $values[$key] ?? $default;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO((string) env('DB_DSN'), env('DB_USERNAME'), env('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function csrf_token(): string {
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_token'] ?? '')) {
        http_response_code(419); exit('Invalid request token. Please reload and try again.');
    }
}
function current_user(): ?array {
    static $user = false;
    if ($user !== false) return $user;
    if (empty($_SESSION['user_id'])) return $user = null;
    $s = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
    $s->execute([$_SESSION['user_id']]);
    return $user = $s->fetch() ?: null;
}
function require_auth(): array {
    $user = current_user();
    if (!$user) { header('Location: /login'); exit; }
    return $user;
}
function require_admin(): array {
    $user = require_auth();
    if ($user['role'] !== 'admin') { http_response_code(403); exit('Administrator access required.'); }
    return $user;
}
function require_editor(): array {
    $user = require_auth();
    if ($user['role'] === 'viewer') { http_response_code(403); exit('View-only access does not allow changes.'); }
    return $user;
}
function flash(string $key, ?string $value = null): ?string {
    if ($value !== null) { $_SESSION['flash'][$key] = $value; return null; }
    $value = $_SESSION['flash'][$key] ?? null; unset($_SESSION['flash'][$key]); return $value;
}
function redirect(string $path): never { header('Location: ' . $path); exit; }
function e(?string $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

function send_invitation_email(string $recipient, string $invitationUrl): bool {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromAddress = str_replace(["\r", "\n"], '', (string) env('MAIL_FROM_ADDRESS', 'commands@localhost'));
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        $fromAddress = 'commands@localhost';
    }
    $fromName = str_replace(["\r", "\n"], '', (string) env('MAIL_FROM_NAME', 'Command Cabinet'));
    $message = "You’ve been invited to join Command Cabinet.\n\n"
        . "Set up your account using this link:\n{$invitationUrl}\n\n"
        . "This invitation expires in seven days.\n";
    $headers = implode("\r\n", [
        "From: {$fromName} <{$fromAddress}>",
        "Reply-To: {$fromAddress}",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    return mail($recipient, 'You’re invited to Command Cabinet', $message, $headers, '-f' . escapeshellarg($fromAddress));
}

function send_password_reset_email(string $recipient, string $resetUrl): bool {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) return false;
    $fromAddress = str_replace(["\r", "\n"], '', (string) env('MAIL_FROM_ADDRESS', 'commands@localhost'));
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) $fromAddress = 'commands@localhost';
    $fromName = str_replace(["\r", "\n"], '', (string) env('MAIL_FROM_NAME', 'Command Cabinet'));
    $message = "A password reset was requested for your Command Cabinet account.\n\n"
        . "Choose a new password using this link:\n{$resetUrl}\n\n"
        . "This link expires in one hour. If you did not request it, you can ignore this email.\n";
    $headers = implode("\r\n", ["From: {$fromName} <{$fromAddress}>", "Reply-To: {$fromAddress}", 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8']);
    return mail($recipient, 'Reset your Command Cabinet password', $message, $headers, '-f' . escapeshellarg($fromAddress));
}
