<?php

namespace app\crawler;

use RuntimeException;

class TorrentIndexer
{
    // 与 manticore.conf 中的 charset_table 保持一致：non_cjk 之外补全 CJK 统一汉字区段。
    // SQL 方式 CREATE TABLE 不会读取配置文件里 plain 模式的 index 定义，必须在语句里显式声明，
    // 否则 jieba_chinese 分词器生效了但中文字符根本不进索引，中文查询全部零命中。
    private const CHARSET_TABLE = "non_cjk, U+4E00..U+9FFF, U+3400..U+4DBF, U+20000..U+2A6DF, U+2A700..U+2B73F, U+2B740..U+2B81F, U+2B820..U+2CEAF";

    private string $baseUrl;
    private string $table;

    public function __construct(?string $baseUrl = null, ?string $table = null)
    {
        $this->baseUrl = rtrim($baseUrl ?: Env::str('MANTICORE_HTTP', 'http://127.0.0.1:9308'), '/');
        $this->table = $table ?: Env::str('MANTICORE_INDEX', 'torrents_rt');
    }

    public function ensureTable(): void
    {
        // min_prefix_len=1：让查询端的“词干*”前缀展开（ManticoreClient::buildFuzzyQuery
        // 给每个词自动补 *）可用；否则任何带 * 的查询都无法前缀展开，自测必然全 FAIL。
        // file_names：把种子内文件路径列表也放入全文索引，支持按文件名片段（如 ubuntu-24.04、
        // big_buck_bunny_1080p）检索。
        // 统一走 /sql JSON 接口：/cli 是给 curl/浏览器人工维护用的端点（依赖 buddy 侧车，
        // 官方文档明确“不建议自动化脚本使用”），且它即使 SQL 报错也返回 HTTP 200，
        // 响应正文为 ERROR ... 文本，无法靠状态码判断成败。
        $charset = self::CHARSET_TABLE;
        $this->sqlQuery("CREATE TABLE IF NOT EXISTS {$this->table}(name text, file_names text, tags text, infohash string, size_total bigint, created_at timestamp) morphology='jieba_chinese' min_prefix_len='1' charset_table='{$charset}'");
        // 兼容在 file_names / tags 字段加入之前就已创建的实时索引：缺列时在线补齐。
        foreach (['file_names', 'tags'] as $column) {
            if (!$this->columnExists($column)) {
                try {
                    $this->sqlQuery("ALTER TABLE {$this->table} ADD COLUMN {$column} text");
                } catch (RuntimeException $e) {
                    // 并发启动或老版本错误码差异时，可能已被其它进程补过，复查一次即可。
                    if (!$this->columnExists($column)) {
                        throw $e;
                    }
                }
            }
        }
        // 老表创建时没有开启前缀展开，在线补上（仅对之后写入/重写的文档生效）。
        if (!$this->tableSettingSatisfiesPrefixLen()) {
            try {
                $this->sqlQuery("ALTER TABLE {$this->table} min_prefix_len='1'");
            } catch (RuntimeException $e) {
                // 部分老版本 Manticore 不支持在线修改 FT 设置；新建表已在 CREATE 中带上该设置。
                if (!$this->tableSettingSatisfiesPrefixLen()) {
                    throw $e;
                }
            }
        }
        // 老表可能没有 CJK 字符集（旧 CREATE 未显式声明 charset_table），中文无法进索引，在线补齐。
        if (!$this->tableCharsetCoversCjk()) {
            try {
                $this->sqlQuery("ALTER TABLE {$this->table} charset_table='" . self::CHARSET_TABLE . "'");
            } catch (RuntimeException $e) {
                if (!$this->tableCharsetCoversCjk()) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @param array<int,array{path?:string,size?:int}>|null $files
     */
    public function upsert(string $infohash, string $name, int $sizeTotal, int $createdAtTs, string $tags = '', ?array $files = null): void
    {
        $id = (int) hexdec(substr($infohash, 0, 15));
        // 走 JSON /replace 接口：天然幂等（同 id 更新），且由 JSON 负责转义，
        // 避免手工把单引号翻倍在文件名 / 标题带 ' 时触发 SQL 语法错误。
        $payload = [
            'index' => $this->table,
            'id' => $id,
            'doc' => [
                'name' => $name,
                'file_names' => $this->buildFileNames($files, $name),
                'tags' => $tags,
                'infohash' => $infohash,
                'size_total' => $sizeTotal,
                'created_at' => $createdAtTs,
            ],
        ];
        $this->jsonExec('/replace', $payload, [200, 201]);
    }

    /**
     * 把种子内全部文件路径拼成一个以空格分隔的字符串供全文索引。
     * 单文件种子没有显式 files 时退回资源名（真实 BitTorrent 元数据中单文件名即 name）。
     *
     * @param array<int,array{path?:string,size?:int}>|null $files
     */
    private function buildFileNames(?array $files, string $fallback): string
    {
        if (!$files) {
            return $fallback;
        }
        $paths = [];
        foreach ($files as $f) {
            $path = (string) ($f['path'] ?? '');
            if ($path !== '') {
                $paths[] = $path;
            }
        }
        return $paths ? implode(' ', $paths) : $fallback;
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
                $this->sqlQuery("DELETE FROM {$this->table} WHERE id IN (" . implode(',', $chunk) . ')');
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
     * 读取 SHOW TABLE SETTINGS 的多行 Value 文本（29.x 形态），旧版本逐行形态则拼成 key = value。
     */
    private function tableSettingsBlob(): string
    {
        try {
            $rows = $this->sqlQuery("SHOW TABLE {$this->table} SETTINGS");
        } catch (RuntimeException $e) {
            return '';
        }
        $blob = '';
        foreach ($rows as $row) {
            $setting = (string) ($row['Setting'] ?? $row['setting'] ?? $row['Variable_name'] ?? '');
            $value = (string) ($row['Value'] ?? $row['value'] ?? '');
            if ($setting === 'settings') {
                $blob .= "\n" . $value;
            } else {
                $blob .= "\n{$setting} = {$value}";
            }
        }
        return $blob;
    }

    /**
     * 检查实时索引当前的 min_prefix_len 是否已开启（>=1）。
     * 查询本身失败时按“未开启”处理，调用方会尝试 ALTER 补齐。
     */
    private function tableSettingSatisfiesPrefixLen(): bool
    {
        $blob = $this->tableSettingsBlob();
        if (preg_match('/^\s*min_prefix_len\s*=\s*(\d+)\s*$/m', $blob, $m)) {
            return (int) $m[1] >= 1;
        }
        return false;
    }

    /**
     * 判断 charset_table 是否覆盖 CJK 统一汉字区段。
     * 旧表完全没声明 charset_table（blob 里没有该键）时返回 false，触发 ALTER 补齐。
     */
    private function tableCharsetCoversCjk(): bool
    {
        $blob = $this->tableSettingsBlob();
        if (!preg_match('/^\s*charset_table\s*=\s*(.*)$/m', $blob, $m)) {
            return false;
        }
        $charset = $m[1];
        // 显式 CJK 区段，或内置的 chinese / cjk / cont 预设，均视为已覆盖
        return str_contains($charset, 'U+4E00') || str_contains($charset, 'chinese')
            || str_contains($charset, 'cjk') || str_contains($charset, 'cont');
    }

    /**
     * 通过 /insert、/replace 等 JSON 写入接口提交文档。
     * 传输层故障重试；4xx / 响应体 error 直接抛出。
     *
     * @param int[] $acceptedCodes
     */
    private function jsonExec(string $path, array $payload, array $acceptedCodes = [200]): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $maxAttempts = 6;
        $sleepMs = 1000;
        $attempt = 0;
        while (true) {
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            $transportFailed = $resp === false || $code === 0 || ($code >= 500 && $code < 600);
            if ($transportFailed) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException($resp === false ? ($err ?: 'curl error') : "manticore {$path} http {$code}: {$resp}");
                }
                usleep($sleepMs * 1000);
                continue;
            }
            if (!in_array($code, $acceptedCodes, true)) {
                throw new RuntimeException("manticore {$path} http {$code}: {$resp}");
            }
            $data = json_decode((string) $resp, true);
            if (is_array($data) && isset($data['error']) && $data['error'] !== '' && $data['error'] !== null) {
                $message = is_array($data['error']) ? json_encode($data['error'], JSON_UNESCAPED_UNICODE) : (string) $data['error'];
                throw new RuntimeException("manticore {$path} error: {$message}");
            }
            return;
        }
    }

    /**
     * 通过 /sql JSON 接口执行语句，返回 data 数组。
     * 连接失败 / HTTP 5xx 等传输层故障会重试；SQL 本身的错误（响应体 error 字段）直接抛出。
     */
    public function sqlQuery(string $sql): array
    {
        $maxAttempts = 6;
        $sleepMs = 1000;
        $attempt = 0;
        while (true) {
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

            $transportFailed = $resp === false || $code === 0 || ($code >= 500 && $code < 600);
            if ($transportFailed) {
                $attempt++;
                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException($resp === false ? ($err ?: 'curl error') : "manticore sql http {$code}: {$resp}");
                }
                usleep($sleepMs * 1000);
                continue;
            }
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException("manticore sql {$code}: {$resp}");
            }
            $data = json_decode($resp, true);
            if (!is_array($data)) {
                throw new RuntimeException('invalid json response from manticore sql');
            }
            $error = $data['error'] ?? ($data[0]['error'] ?? '');
            if (is_array($error)) {
                $error = json_encode($error, JSON_UNESCAPED_UNICODE);
            }
            if ($error !== '' && $error !== null) {
                throw new RuntimeException("manticore sql error: {$error}");
            }
            if (is_array($data['data'] ?? null)) {
                return $data['data'];
            }
            return is_array($data[0]['data'] ?? null) ? $data[0]['data'] : [];
        }
    }
}
