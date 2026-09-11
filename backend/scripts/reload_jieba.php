<?php

require_once __DIR__ . '/../vendor/autoload.php';

function env_str(string $key, string $default = ''): string
{
    $val = getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    return $val;
}

function http_post(string $url, string $body): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException($err ?: 'curl error');
    }
    return [$code, $resp];
}

$manticoreBase = env_str('MANTICORE_HTTP', 'http://127.0.0.1:9308');
$sql = 'RELOAD DICTIONARIES';
[$code, $resp] = http_post(rtrim($manticoreBase, '/') . '/cli', $sql);
if ($code < 200 || $code >= 300) {
    throw new RuntimeException("manticore reload {$code}: {$resp}");
}
fwrite(STDOUT, "jieba dictionary reloaded\n");
