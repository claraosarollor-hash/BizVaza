<?php
if (__FILE__ === $_SERVER['SCRIPT_FILENAME']) { header($_SERVER['SERVER_PROTOCOL'].' 403 Forbidden'); exit; }

function rl_dir(string $bucket): string {
    return sys_get_temp_dir() . '/bizvaza_rl_' . preg_replace('/[^a-z0-9_\-]/i', '', $bucket);
}

function rl_file(string $bucket, string $identity): string {
    return rl_dir($bucket) . '/' . md5($identity);
}

/** Returns [count, fresh] — fresh = within the current window (caller decides window). */
function rl_state(string $bucket, string $identity, int $windowSec): array {
    $file = rl_file($bucket, $identity);
    if (!is_file($file)) return [0, false];
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) return [0, false];
    if (time() - (int)($data['ts'] ?? 0) >= $windowSec) return [0, false];
    return [(int)($data['count'] ?? 0), true];
}

function rl_record(string $bucket, string $identity, int $windowSec): void {
    $dir = rl_dir($bucket);
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $file = rl_file($bucket, $identity);
    [$count, $fresh] = rl_state($bucket, $identity, $windowSec);
    $count = $fresh ? $count + 1 : 1;
    @file_put_contents($file, json_encode(['ts' => time(), 'count' => $count]));
}

function rl_reset(string $bucket, string $identity): void {
    $file = rl_file($bucket, $identity);
    if (is_file($file)) { @unlink($file); }
}
