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
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table}(name text, tags text, infohash string, size_total bigint, created_at timestamp) morphology='jieba_chinese'";
        $this->cli($sql);
        // 兼容在 tags 字段加入之前就已创建的实时索引：缺列时在线补齐。
        if (!$this->columnExists('tags')) {
            try {
                $this->cli("ALTER TABLE {$this->table} ADD COLUMN tags text");
            } catch (RuntimeException $e) {
                // 并发启动或老版本错误码差异时，可能已被其它进程补过，复查一次即可。
                if (!$this->columnExists('tags')) {
                    throw $e;
                }
            }
        }
    }

    public function upsert(string $infohash, string $name, int $sizeTotal, int $createdAtTs, string $tags = ''): void
    {
        $id = (int) hexdec(substr($infohash, 0, 15));
        $safeName = str_replace("'", "''", $name);
        $safeTags = str_replace("'", "''", $tags);
        $sql = "REPLACE INTO {$this->table}(id, name, tags, infohash, size_total, created_at) VALUES ({$id}, '{$safeName}', '{$safeTags}', '{$infohash}', {$sizeTotal}, {$createdAtTs})";
        $this->cli($sql);
    }

    /**
     * 按文档 id 批量删除（Manticore 单条 DELETE 仅支持等值条件，分批发送）。
     *
     * @param int[] $ids
     */
    public function deleteIds(array $ids): void
    {
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            if ($chunk) {
                $this->cli("DELETE FROM {$this->table} WHERE id IN (" . implode(',', $chunk) . ')');
            }
        }
    }

    private function columnExists(string $column): bool
    {
        $rows = $this->sqlQuery("DESC {$this->table}");
        foreach ($rows as $row) {
            if (($row['Field'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * 通过 /sql JSON 接口执行查询语句，返回 data 数组。
     */
    public function sqlQuery(string $sql): array
    {
        $ch = curl_init($this->baseUrl . '/sql?mode=raw');
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
            throw new RuntimeException("manticore sql {$code}: {$resp}");
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('invalid json response from manticore sql');
        }
        if (isset($data['error']) && $data['error'] !== '') {
            throw new RuntimeException("manticore sql error: {$data['error']}");
        }
        if (is_array($data['data'] ?? null)) {
            return $data['data'];
        }
        return is_array($data[0]['data'] ?? null) ? $data[0]['data'] : [];
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
