<?php
declare(strict_types=1);

/**
 * Finds literal values that are often personal, confidential, or environment-specific.
 * This is deliberately conservative: callers should treat its results as a warning that
 * a placeholder is safer, not as a definitive classification of the value.
 *
 * @param array<string, mixed> $fields
 * @return list<string>
 */
function sensitive_command_value_warnings(array $fields): array {
    $values = [];
    $collect = static function (mixed $value) use (&$values, &$collect): void {
        if (is_array($value)) { foreach ($value as $item) $collect($item); return; }
        if (is_string($value) && $value !== '') $values[] = $value;
    };
    $collect($fields);
    $text = implode("\n", $values);
    // Placeholders are the supported way to retain a variable value and must not trigger a warning.
    $text = preg_replace('/{{\s*[a-zA-Z][a-zA-Z0-9_.-]*\s*}}/', '', $text) ?? $text;

    $warnings = [];
    if (preg_match('/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i', $text)) $warnings[] = 'email address';
    if (preg_match('#\b[a-z][a-z0-9+.-]*://[^\s/@:]+:[^\s/@]+@#i', $text)) $warnings[] = 'username and password embedded in a URL';
    if (preg_match('/(?:--?(?:password|passwd|pwd|token|secret|api[_-]?key|access[_-]?key|client[_-]?secret)|\b(?:password|passwd|token|secret|api[_-]?key|access[_-]?key)\b)\s*(?:=|\s+)\s*(?!\$[A-Za-z_]|\$\{|%[A-Za-z_][A-Za-z0-9_]*%|<[^>]+>)(?:["\'][^"\']+["\']|[^\s;|&]+)/i', $text)) $warnings[] = 'password, token, or API secret';
    if (preg_match('/(?:[?&](?:token|access_token|api[_-]?key|key|password)=)[^&\s]+/i', $text)) $warnings[] = 'secret in a URL query string';
    if (preg_match('/(?:--(?:user(?:name)?|login)|-(?:u|U)|-(?:UserName|User|Login))\s+(?!\$[A-Za-z_]|\$\{|%[A-Za-z_][A-Za-z0-9_]*%|<[^>]+>)(?:["\'][^"\']+["\']|[A-Za-z][A-Za-z0-9._-]*)/i', $text)) $warnings[] = 'hardcoded login name';
    if (preg_match('/-(?:DisplayName|Name)\s+["\'][^"\']*\s+[^"\']*["\']/i', $text)) $warnings[] = 'possible personal name';
    if (preg_match('#(?:[A-Za-z]:)?[\\/](?:Users|home)[\\/](?!\$|%|<)[A-Za-z][A-Za-z0-9._-]*#i', $text)) $warnings[] = 'user-profile name in a path';
    if (preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $text, $matches)) {
        foreach ($matches[0] as $address) if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { $warnings[] = 'IP address'; break; }
    }
    return array_values(array_unique($warnings));
}
