<?php

require_once __DIR__ . '/../vendor/autoload.php';

if ((getenv('APP_RUN_MODE') ?: 'http') === 'crawler') {
    exit(0);
}

fwrite(STDOUT, "bootstrap begin\n");

function env_str(string $key, string $default = ''): string
{
    $val = getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    return $val;
}

function wait_mysql(PDO $pdo, int $maxAttempts, int $sleepMs): void
{
    $attempt = 0;
    while (true) {
        try {
            $pdo->query('SELECT 1');
            return;
        } catch (Throwable $e) {
            $attempt++;
            if ($attempt >= $maxAttempts) {
                throw $e;
            }
            usleep($sleepMs * 1000);
        }
    }
}

function column_exists(PDO $pdo, string $dbName, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = :db AND table_name = :tbl AND column_name = :col');
    $stmt->execute([
        'db' => $dbName,
        'tbl' => $table,
        'col' => $column,
    ]);
    $row = $stmt->fetch();
    return ((int) ($row['c'] ?? 0)) > 0;
}

function add_column_if_missing(PDO $pdo, string $dbName, string $table, string $column, string $definition): void
{
    if (column_exists($pdo, $dbName, $table, $column)) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    } catch (PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        if ($code !== 1060) {
            throw $e;
        }
    }
}

function http_post(string $url, string $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException($err ?: 'curl error');
    }
    return [$code, $resp];
}

function ensure_manticore_table(string $baseUrl, string $table): void
{
    $sql = "CREATE TABLE IF NOT EXISTS {$table}(name text, infohash string, size_total bigint, created_at timestamp) morphology='jieba_chinese'";
    [$code, $resp] = http_post(rtrim($baseUrl, '/') . '/cli', $sql);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("manticore cli http {$code}: {$resp}");
    }
}

function manticore_replace(string $baseUrl, string $table, int $id, string $infohash, string $name, int $sizeTotal, int $createdAt): void
{
    $escapedName = str_replace("'", "''", $name);
    $sql = "REPLACE INTO {$table}(id, name, infohash, size_total, created_at) VALUES ({$id}, '{$escapedName}', '{$infohash}', {$sizeTotal}, {$createdAt})";
    [$code, $resp] = http_post(rtrim($baseUrl, '/') . '/cli', $sql);
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("manticore replace http {$code}: {$resp}");
    }
}

$dbHost = env_str('DB_HOST', '127.0.0.1');
$dbPort = (int) env_str('DB_PORT', '3306');
$dbName = env_str('DB_DATABASE', 'dht_search');
$dbUser = env_str('DB_USERNAME', 'root');
$dbPass = env_str('DB_PASSWORD', 'root');

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
$pdo = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

wait_mysql($pdo, 60, 1000);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS torrents (
  infohash CHAR(40) PRIMARY KEY,
  name VARCHAR(1024) NOT NULL,
  size_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_count INT UNSIGNED NOT NULL DEFAULT 0,
  extension VARCHAR(16) NOT NULL DEFAULT '',
  files_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

add_column_if_missing($pdo, $dbName, 'torrents', 'file_count', 'file_count INT UNSIGNED NOT NULL DEFAULT 0');
add_column_if_missing($pdo, $dbName, 'torrents', 'extension', "extension VARCHAR(16) NOT NULL DEFAULT ''");

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS torrent_peers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  infohash CHAR(40) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  port INT UNSIGNED NOT NULL,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_infohash_peer (infohash, ip, port),
  KEY idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS crawl_queue (
  infohash CHAR(40) PRIMARY KEY,
  priority INT NOT NULL DEFAULT 0,
  state VARCHAR(32) NOT NULL DEFAULT 'queued',
  retry_count INT NOT NULL DEFAULT 0,
  next_run_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_next_run (next_run_at, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$count = (int) $pdo->query('SELECT COUNT(*) AS c FROM torrents')->fetch()['c'];
if ($count === 0) {
    $seed = [
        [
            'infohash' => '0123456789abcdef0123456789abcdef01234567',
            'name' => 'Ubuntu 22.04.4 LTS Desktop ISO',
            'size_total' => 4865392640,
            'file_count' => 1,
            'extension' => 'iso',
            'files_json' => json_encode([
                ['path' => 'ubuntu-22.04.4-desktop-amd64.iso', 'size' => 4865392640],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'fetched',
        ],
        [
            'infohash' => '89abcdef0123456789abcdef0123456789abcdef',
            'name' => 'Debian 12.5.0 netinst amd64 ISO',
            'size_total' => 661651456,
            'file_count' => 1,
            'extension' => 'iso',
            'files_json' => json_encode([
                ['path' => 'debian-12.5.0-amd64-netinst.iso', 'size' => 661651456],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'fetched',
        ],
        [
            'infohash' => 'fedcba9876543210fedcba9876543210fedcba98',
            'name' => 'Fedora Workstation 40 x86_64 ISO',
            'size_total' => 2264924160,
            'file_count' => 1,
            'extension' => 'iso',
            'files_json' => json_encode([
                ['path' => 'Fedora-Workstation-Live-x86_64-40-1.14.iso', 'size' => 2264924160],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'fetched',
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO torrents(infohash,name,size_total,file_count,extension,files_json,status) VALUES (:infohash,:name,:size_total,:file_count,:extension,:files_json,:status)');
    foreach ($seed as $row) {
        $stmt->execute($row);
    }
}

$manticoreBase = env_str('MANTICORE_HTTP', 'http://127.0.0.1:9308');
$manticoreIndex = env_str('MANTICORE_INDEX', 'torrents_rt');

function wait_manticore(string $baseUrl, int $maxAttempts, int $sleepMs): void
{
    $url = rtrim($baseUrl, '/') . '/search'; // Using search endpoint to check readiness
    $attempt = 0;
    while (true) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        // We just check if we get a response, even 404 is fine as long as server talks
        // But Manticore HTTP usually returns something or we can check /sql
        // Let's use /search which is standard HTTP endpoint, or just check connectivity
        // Actually the code uses /cli endpoint which is basically SQL over HTTP

        // Let's use a simple query to check connectivity
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "SHOW TABLES");
        curl_setopt($ch, CURLOPT_URL, rtrim($baseUrl, '/') . '/cli');

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 500) {
            return;
        }

        $attempt++;
        if ($attempt >= $maxAttempts) {
            throw new RuntimeException("Manticore connection failed after {$maxAttempts} attempts");
        }
        fwrite(STDOUT, "Waiting for Manticore... attempt {$attempt}\n");
        usleep($sleepMs * 1000);
    }
}

// Wait for Manticore to be ready (up to 60 seconds)
wait_manticore($manticoreBase, 60, 1000);

ensure_manticore_table($manticoreBase, $manticoreIndex);

$rows = $pdo->query('SELECT infohash,name,size_total,UNIX_TIMESTAMP(created_at) AS created_ts FROM torrents ORDER BY created_at DESC LIMIT 50')->fetchAll();
foreach ($rows as $row) {
    $id = (int) hexdec(substr($row['infohash'], 0, 15));
    manticore_replace(
        $manticoreBase,
        $manticoreIndex,
        $id,
        $row['infohash'],
        $row['name'],
        (int) $row['size_total'],
        (int) $row['created_ts']
    );
}

fwrite(STDOUT, "bootstrap done\n");
