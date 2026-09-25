<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }

/**
 * Paystack settings + helpers (single source of truth for the payment flow).
 *
 * Mode precedence: explicit `paystack_mode` setting wins; otherwise inferred
 * from whichever key prefix is present (test/live). Defaults to 'live'.
 */
function paystack_settings(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ('paystack_public_key','paystack_secret_key','paystack_mode')");
    $stmt->execute();
    $vals = [];
    foreach ($stmt->fetchAll() as $r) { $vals[$r['key']] = (string)$r['value']; }

    $pub = trim($vals['paystack_public_key'] ?? '');
    $sec = trim($vals['paystack_secret_key'] ?? '');
    $mode = strtolower(trim($vals['paystack_mode'] ?? ''));

    if (!in_array($mode, ['test', 'live'], true)) {
        $mode = (strpos($pub, 'pk_test_') === 0 || strpos($sec, 'sk_test_') === 0) ? 'test' : 'live';
    }

    $cache = ['public' => $pub, 'secret' => $sec, 'mode' => $mode];
    return $cache;
}

function paystack_configured(PDO $pdo): bool {
    $s = paystack_settings($pdo);
    if ($s['mode'] === 'test') {
        return strpos($s['public'], 'pk_test_') === 0 && strpos($s['secret'], 'sk_test_') === 0;
    }
    return strpos($s['public'], 'pk_live_') === 0 && strpos($s['secret'], 'sk_live_') === 0;
}

/** Validate a Paystack key's format and prefix vs a mode ('test'|'live'). */
function paystack_key_valid(?string $key, string $kind, string $mode): bool {
    $key = trim((string)$key);
    if ($key === '') return false;
    $prefix = ($kind === 'secret' ? 'sk_' : 'pk_') . $mode . '_';
    return strpos($key, $prefix) === 0 && (bool)preg_match('/^[A-Za-z0-9_]+$/', substr($key, strlen($prefix)));
}

/**
 * Call the Paystack API (POST with JSON body or GET).
 * Returns [code, body, data, errno, error] — never throws.
 */
function paystack_api(string $method, string $path, string $secret, ?array $json = null): array {
    $url = 'https://api.paystack.co/' . ltrim($path, '/');
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Content-Type: application/json'],
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($json);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);
    return [
        'code' => $code,
        'body' => $body,
        'data' => ($body !== false) ? json_decode($body, true) : null,
        'errno' => $errno,
        'error' => $error,
    ];
}