<?php

namespace app\crawler;

use RuntimeException;

class TorrentIndexer
{
    private string $baseUrl;
    private string $table;

    public function __construct(?string $baseUrl = null, ?string $table = null)
    {
        $this->baseUrl = rtrim($baseUrl ?: Env::str('MANTICORE_HTTP', 'http://127.0.0.1:9308'), '/');
        $this->table = $table ?: Env::str('MANTICORE_INDEX', 'torrents_rt');
    }

    public function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table}(name text, infohash string, size_total bigint, created_at timestamp) morphology='jieba_chinese'";
        $this->cli($sql);
    }

    public function upsert(string $infohash, string $name, int $sizeTotal, int $createdAtTs): void
    {
        $id = (int) hexdec(substr($infohash, 0, 15));
        $safeName = str_replace("'", "''", $name);
        $sql = "REPLACE INTO {$this->table}(id, name, infohash, size_total, created_at) VALUES ({$id}, '{$safeName}', '{$infohash}', {$sizeTotal}, {$createdAtTs})";
        $this->cli($sql);
    }

    private function cli(string $sql): void
    {
        $attempt = 0;
        $maxAttempts = 6;
        $sleepMs = 1000;
        while (true) {
            $ch = curl_init($this->baseUrl . '/cli');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sql);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($resp !== false && $code >= 200 && $code < 300) {
                return;
            }
            $attempt++;
            if ($attempt >= $maxAttempts) {
                if ($resp === false) {
                    throw new RuntimeException($err ?: 'curl error');
                }
                throw new RuntimeException("manticore cli {$code}: {$resp}");
            }
            usleep($sleepMs * 1000);
        }
    }
}
