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

function env_int(string $key, int $default): int
{
    $val = getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    return (int) $val;
}

function http_post(string $url, string $body): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException($err ?: 'curl error');
    }
    return [$code, $resp];
}

$count = env_int('PRESSURE_COUNT', 10000);
$batch = env_int('PRESSURE_BATCH', 200);
$dbHost = env_str('DB_HOST', '127.0.0.1');
$dbPort = env_int('DB_PORT', 3306);
$dbName = env_str('DB_DATABASE', 'dht_search');
$dbUser = env_str('DB_USERNAME', 'root');
$dbPass = env_str('DB_PASSWORD', 'root');
$manticoreBase = env_str('MANTICORE_HTTP', 'http://127.0.0.1:9308');
$manticoreIndex = env_str('MANTICORE_INDEX', 'torrents_rt');

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$stmt = $pdo->prepare('INSERT INTO torrents(infohash,name,size_total,file_count,extension,files_json,status) VALUES (:infohash,:name,:size_total,:file_count,:extension,:files_json,:status) ON DUPLICATE KEY UPDATE name=VALUES(name), size_total=VALUES(size_total), file_count=VALUES(file_count), extension=VALUES(extension), files_json=VALUES(files_json), status=VALUES(status)');

$start = microtime(true);
$rows = [];
$now = time();

for ($i = 0; $i < $count; $i++) {
    $infohash = bin2hex(random_bytes(20));
    $name = '测试资源 ' . $i . ' 星际旅行 ' . substr($infohash, 0, 6);
    $size = random_int(10_000_000, 8_000_000_000);
    $rows[] = [
        'infohash' => $infohash,
        'name' => $name,
        'size_total' => $size,
        'file_count' => 1,
        'extension' => 'mkv',
        'files_json' => json_encode([['path' => $name . '.mkv', 'size' => $size]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'status' => 'fetched',
        'created_at' => $now,
    ];

    if (count($rows) >= $batch) {
        foreach ($rows as $row) {
            $stmt->execute([
                'infohash' => $row['infohash'],
                'name' => $row['name'],
                'size_total' => $row['size_total'],
                'file_count' => $row['file_count'],
                'extension' => $row['extension'],
                'files_json' => $row['files_json'],
                'status' => $row['status'],
            ]);
        }
        $sql = 'REPLACE INTO ' . $manticoreIndex . '(id,name,infohash,size_total,created_at) VALUES ';
        $values = [];
        foreach ($rows as $row) {
            $id = (int) hexdec(substr($row['infohash'], 0, 15));
            $safeName = str_replace("'", "''", $row['name']);
            $values[] = "({$id}, '{$safeName}', '{$row['infohash']}', {$row['size_total']}, {$row['created_at']})";
        }
        $sql .= implode(',', $values);
        [$code, $resp] = http_post(rtrim($manticoreBase, '/') . '/cli', $sql);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("manticore replace http {$code}: {$resp}");
        }
        $rows = [];
    }
}

if ($rows) {
    foreach ($rows as $row) {
        $stmt->execute([
            'infohash' => $row['infohash'],
            'name' => $row['name'],
            'size_total' => $row['size_total'],
            'file_count' => $row['file_count'],
            'extension' => $row['extension'],
            'files_json' => $row['files_json'],
            'status' => $row['status'],
        ]);
    }
    $sql = 'REPLACE INTO ' . $manticoreIndex . '(id,name,infohash,size_total,created_at) VALUES ';
    $values = [];
    foreach ($rows as $row) {
        $id = (int) hexdec(substr($row['infohash'], 0, 15));
        $safeName = str_replace("'", "''", $row['name']);
        $values[] = "({$id}, '{$safeName}', '{$row['infohash']}', {$row['size_total']}, {$row['created_at']})";
    }
    $sql .= implode(',', $values);
    [$code, $resp] = http_post(rtrim($manticoreBase, '/') . '/cli', $sql);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("manticore replace http {$code}: {$resp}");
    }
}

$elapsed = microtime(true) - $start;
fwrite(STDOUT, "pressure_test_inserted={$count} elapsed=" . number_format($elapsed, 2) . "s\n");
