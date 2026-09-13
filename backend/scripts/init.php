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

function ensure_manticore_table(string $baseUrl, string $table): void
{
    // 与 manticore.conf 中的 charset_table 保持一致；SQL 方式 CREATE 不会读取配置文件里
    // plain 模式的 index 定义，不显式声明 CJK 区段的话 jieba 分词了但中文不进索引。
    $charset = 'non_cjk, U+4E00..U+9FFF, U+3400..U+4DBF, U+20000..U+2A6DF, U+2A700..U+2B73F, U+2B740..U+2B81F, U+2B820..U+2CEAF';
    $sql = "CREATE TABLE IF NOT EXISTS {$table}(name text, file_names text, tags text, infohash string, size_total bigint, created_at timestamp) morphology='jieba_chinese' min_prefix_len='1' charset_table='{$charset}'";
    // 统一走 /sql JSON 接口：/cli 依赖 buddy 侧车且 SQL 报错也返回 HTTP 200，无法判定成败。
    manticore_sql_exec($baseUrl, $sql);
    // 旧环境可能在 file_names / tags 字段上线前就已建好实时索引，在线补齐列。
    // ALTER 走 /sql JSON 接口：/cli 即使 SQL 报错也返回 HTTP 200（正文为 ERROR ... 文本），
    // 无法靠状态码判断成败。
    $columns = manticore_columns($baseUrl, $table);
    foreach (['file_names', 'tags'] as $column) {
        if (in_array($column, $columns, true)) {
            continue;
        }
        try {
            manticore_sql_exec($baseUrl, "ALTER TABLE {$table} ADD COLUMN {$column} text");
        } catch (RuntimeException $e) {
            // 并发启动或老版本错误码差异时，可能已被其它进程补过，复查一次即可。
            if (!in_array($column, manticore_columns($baseUrl, $table), true)) {
                throw $e;
            }
        }
    }
    // 老表创建时没有开启前缀展开，在线补上（仅对之后写入/重写的文档生效）。
    if (!manticore_setting_ge($baseUrl, $table, 'min_prefix_len', 1)) {
        try {
            manticore_sql_exec($baseUrl, "ALTER TABLE {$table} min_prefix_len='1'");
        } catch (RuntimeException $e) {
            // 部分老版本 Manticore 不支持在线修改 FT 设置；新建表已在 CREATE 中带上该设置。
            if (!manticore_setting_ge($baseUrl, $table, 'min_prefix_len', 1)) {
                throw $e;
            }
        }
    }
    // 老表可能没有 CJK 字符集（旧 CREATE 未声明 charset_table），中文无法进索引，在线补齐。
    if (!manticore_charset_covers_cjk($baseUrl, $table)) {
        try {
            manticore_sql_exec($baseUrl, "ALTER TABLE {$table} charset_table='{$charset}'");
        } catch (RuntimeException $e) {
            if (!manticore_charset_covers_cjk($baseUrl, $table)) {
                throw $e;
            }
        }
    }
}

/**
 * SHOW TABLE SETTINGS 的多行 Value 文本（29.x）；旧版本逐行形态则拼成 key = value。
 */
function manticore_settings_blob(string $baseUrl, string $table): string
{
    $ch = curl_init(rtrim($baseUrl, '/') . '/sql?mode=raw');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['query' => "SHOW TABLE {$table} SETTINGS"]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return '';
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || !empty($data['error']) || !empty($data[0]['error'])) {
        return '';
    }
    $rows = $data['data'] ?? ($data[0]['data'] ?? []);
    if (!is_array($rows)) {
        return '';
    }
    $blob = '';
    foreach ($rows as $row) {
        $name = (string) ($row['Setting'] ?? $row['setting'] ?? $row['Variable_name'] ?? '');
        $value = (string) ($row['Value'] ?? $row['value'] ?? '');
        $blob .= $name === 'settings' ? "\n{$value}" : "\n{$name} = {$value}";
    }
    return $blob;
}

function manticore_charset_covers_cjk(string $baseUrl, string $table): bool
{
    $blob = manticore_settings_blob($baseUrl, $table);
    if (!preg_match('/^\s*charset_table\s*=\s*(.*)$/m', $blob, $m)) {
        return false;
    }
    $charset = $m[1];
    return str_contains($charset, 'U+4E00') || str_contains($charset, 'chinese')
        || str_contains($charset, 'cjk') || str_contains($charset, 'cont');
}

/**
 * 通过 /sql JSON 接口执行 DDL/DML，响应体带 error 字段时抛异常。
 */
function manticore_sql_exec(string $baseUrl, string $sql): void
{
    $ch = curl_init(rtrim($baseUrl, '/') . '/sql?mode=raw');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['query' => $sql]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException($err ?: 'curl error');
    }
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("manticore sql http {$code}: {$resp}");
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException('invalid json response from manticore sql');
    }
    if (isset($data['error']) && $data['error'] !== '' && $data['error'] !== null) {
        $message = is_array($data['error']) ? json_encode($data['error'], JSON_UNESCAPED_UNICODE) : (string) $data['error'];
        throw new RuntimeException("manticore sql error: {$message}");
    }
}

/**
 * 读取实时索引的整型 FT 设置，判断是否 >= $min。
 */
function manticore_setting_ge(string $baseUrl, string $table, string $setting, int $min): bool
{
    $blob = manticore_settings_blob($baseUrl, $table);
    if (preg_match('/^\s*' . preg_quote($setting, '/') . '\s*=\s*(\d+)\s*$/m', $blob, $m)) {
        return (int) $m[1] >= $min;
    }
    return false;
}

/**
 * @return string[]
 */
function manticore_columns(string $baseUrl, string $table): array
{
    $ch = curl_init(rtrim($baseUrl, '/') . '/sql?mode=raw');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['query' => "DESC {$table}"]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) {
        return [];
    }
    $data = json_decode($resp, true);
    $rows = $data[0]['data'] ?? ($data['data'] ?? []);
    if (!is_array($rows)) {
        return [];
    }
    return array_values(array_filter(array_map(static fn($r) => (string) ($r['Field'] ?? ''), $rows)));
}

function manticore_replace(string $baseUrl, string $table, int $id, string $infohash, string $name, int $sizeTotal, int $createdAt, string $tags = '', string $fileNames = ''): void
{
    // 走 JSON /replace：幂等（同 id 更新），由 JSON 负责转义，避免文件名/标题带单引号时出错。
    $payload = [
        'index' => $table,
        'id' => $id,
        'doc' => [
            'name' => $name,
            'file_names' => $fileNames,
            'tags' => $tags,
            'infohash' => $infohash,
            'size_total' => $sizeTotal,
            'created_at' => $createdAt,
        ],
    ];
    $ch = curl_init(rtrim($baseUrl, '/') . '/replace');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException($err ?: 'curl error');
    }
    if ($code !== 200 && $code !== 201) {
        throw new RuntimeException("manticore replace http {$code}: {$resp}");
    }
    $data = json_decode((string) $resp, true);
    if (is_array($data) && isset($data['error']) && $data['error'] !== '' && $data['error'] !== null) {
        throw new RuntimeException('manticore replace error: ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
    }
}

/**
 * 从 files_json 中提取全部文件路径，拼成空格分隔的全文索引内容。
 * files_json 缺失或为空时退回资源名。
 */
function file_names_from_json(?string $filesJson, string $fallback): string
{
    if ($filesJson === null || $filesJson === '') {
        return $fallback;
    }
    $files = json_decode($filesJson, true);
    if (!is_array($files)) {
        return $fallback;
    }
    $paths = [];
    foreach ($files as $f) {
        if (is_array($f) && isset($f['path']) && is_string($f['path']) && $f['path'] !== '') {
            $paths[] = $f['path'];
        }
    }
    return $paths ? implode(' ', $paths) : $fallback;
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
  tags VARCHAR(255) NOT NULL DEFAULT '',
  files_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

add_column_if_missing($pdo, $dbName, 'torrents', 'file_count', 'file_count INT UNSIGNED NOT NULL DEFAULT 0');
add_column_if_missing($pdo, $dbName, 'torrents', 'extension', "extension VARCHAR(16) NOT NULL DEFAULT ''");
add_column_if_missing($pdo, $dbName, 'torrents', 'tags', "tags VARCHAR(255) NOT NULL DEFAULT ''");

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
            'tags' => 'Linux,Ubuntu,操作系统,安装镜像',
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
            'tags' => 'Linux,Debian,操作系统,安装镜像',
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
            'tags' => 'Linux,Fedora,操作系统,安装镜像',
            'files_json' => json_encode([
                ['path' => 'Fedora-Workstation-Live-x86_64-40-1.14.iso', 'size' => 2264924160],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'fetched',
        ],
    ];

    $stmt = $pdo->prepare('INSERT INTO torrents(infohash,name,size_total,file_count,extension,tags,files_json,status) VALUES (:infohash,:name,:size_total,:file_count,:extension,:tags,:files_json,:status)');
    foreach ($seed as $row) {
        $stmt->execute($row);
    }
}

$manticoreBase = env_str('MANTICORE_HTTP', 'http://127.0.0.1:9308');
$manticoreIndex = env_str('MANTICORE_INDEX', 'torrents_rt');

function wait_manticore(string $baseUrl, int $maxAttempts, int $sleepMs): void
{
    // 用原生 /sql 接口探活（不依赖 buddy 侧车）：SHOW STATUS 始终可用。
    $url = rtrim($baseUrl, '/') . '/sql?mode=raw';
    $attempt = 0;
    while (true) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['query' => 'SHOW STATUS']));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($resp !== false && $code >= 200 && $code < 500 && str_contains((string) $resp, 'uptime')) {
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

$rows = $pdo->query('SELECT infohash,name,size_total,tags,files_json,UNIX_TIMESTAMP(created_at) AS created_ts FROM torrents ORDER BY created_at DESC LIMIT 50')->fetchAll();
foreach ($rows as $row) {
    $id = (int) hexdec(substr($row['infohash'], 0, 15));
    manticore_replace(
        $manticoreBase,
        $manticoreIndex,
        $id,
        $row['infohash'],
        $row['name'],
        (int) $row['size_total'],
        (int) $row['created_ts'],
        (string) ($row['tags'] ?? ''),
        file_names_from_json($row['files_json'] ?? null, (string) $row['name'])
    );
}

fwrite(STDOUT, "bootstrap done\n");
