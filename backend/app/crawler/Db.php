<?php

namespace app\crawler;

use PDO;
use PDOException;
use Throwable;

class Db
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function connectFromEnv(): self
    {
        $host = Env::str('DB_HOST', '127.0.0.1');
        $port = Env::int('DB_PORT', 3306);
        $db = Env::str('DB_DATABASE', 'dht_search');
        $user = Env::str('DB_USERNAME', 'root');
        $pass = Env::str('DB_PASSWORD', 'root');
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return new self($pdo);
    }

    public function waitReady(int $maxAttempts, int $sleepMs): void
    {
        $attempt = 0;
        while (true) {
            try {
                $this->pdo->query('SELECT 1');
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

    public function ensureSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
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

        $dbName = $this->currentDatabase();
        if ($dbName !== '') {
            $this->addColumnIfMissing($dbName, 'torrents', 'file_count', 'file_count INT UNSIGNED NOT NULL DEFAULT 0');
            $this->addColumnIfMissing($dbName, 'torrents', 'extension', "extension VARCHAR(16) NOT NULL DEFAULT ''");
            $this->addColumnIfMissing($dbName, 'torrents', 'tags', "tags VARCHAR(255) NOT NULL DEFAULT ''");
        }

        $this->pdo->exec(<<<'SQL'
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

        $this->pdo->exec(<<<'SQL'
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
    }

    private function currentDatabase(): string
    {
        $row = $this->pdo->query('SELECT DATABASE() AS db')->fetch();
        return (string) ($row['db'] ?? '');
    }

    private function columnExists(string $dbName, string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = :db AND table_name = :tbl AND column_name = :col');
        $stmt->execute([
            'db' => $dbName,
            'tbl' => $table,
            'col' => $column,
        ]);
        $row = $stmt->fetch();
        return ((int) ($row['c'] ?? 0)) > 0;
    }

    private function addColumnIfMissing(string $dbName, string $table, string $column, string $definition): void
    {
        if ($this->columnExists($dbName, $table, $column)) {
            return;
        }
        try {
            $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            if ($code !== 1060) {
                throw $e;
            }
        }
    }

    public function upsertPeer(string $infohash, string $ip, int $port): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO torrent_peers(infohash,ip,port) VALUES (:infohash,:ip,:port) ON DUPLICATE KEY UPDATE last_seen_at=CURRENT_TIMESTAMP');
        $stmt->execute([
            'infohash' => $infohash,
            'ip' => $ip,
            'port' => $port,
        ]);
    }

    public function getPeers(string $infohash, int $limit): array
    {
        $stmt = $this->pdo->prepare('SELECT ip,port FROM torrent_peers WHERE infohash=:infohash ORDER BY last_seen_at DESC LIMIT :lim');
        $stmt->bindValue(':infohash', $infohash);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function upsertTorrent(string $infohash, string $name, int $sizeTotal, ?array $files, string $status, int $fileCount, string $extension, string $tags = ''): void
    {
        $tags = mb_substr($tags, 0, 255);
        $filesJson = $files ? json_encode($files, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt = $this->pdo->prepare('INSERT INTO torrents(infohash,name,size_total,file_count,extension,tags,files_json,status) VALUES (:infohash,:name,:size_total,:file_count,:extension,:tags,:files_json,:status) ON DUPLICATE KEY UPDATE name=VALUES(name), size_total=VALUES(size_total), file_count=VALUES(file_count), extension=VALUES(extension), tags=VALUES(tags), files_json=VALUES(files_json), status=VALUES(status)');
        $stmt->execute([
            'infohash' => $infohash,
            'name' => $name,
            'size_total' => $sizeTotal,
            'file_count' => $fileCount,
            'extension' => $extension,
            'tags' => $tags,
            'files_json' => $filesJson,
            'status' => $status,
        ]);
    }

    public function markQueue(string $infohash, string $state, int $retryCount, ?int $nextRunAtTs): void
    {
        $next = $nextRunAtTs ? date('Y-m-d H:i:s', $nextRunAtTs) : null;
        $stmt = $this->pdo->prepare('INSERT INTO crawl_queue(infohash,state,retry_count,next_run_at) VALUES (:infohash,:state,:retry_count,:next_run_at) ON DUPLICATE KEY UPDATE state=VALUES(state), retry_count=VALUES(retry_count), next_run_at=VALUES(next_run_at)');
        $stmt->execute([
            'infohash' => $infohash,
            'state' => $state,
            'retry_count' => $retryCount,
            'next_run_at' => $next,
        ]);
    }
}
