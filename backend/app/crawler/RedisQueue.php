<?php

namespace app\crawler;

use Redis;

class RedisQueue
{
    private Redis $redis;
    private string $hashQueueKey;
    private string $metaQueueKey;
    private string $seenKey;
    private bool $useBloom;
    private string $bloomKey;
    private int $bloomBits;
    private int $bloomHashes;
    private bool $useSet;

    private string $host;
    private int $port;
    private int $dbIndex;
    private float $timeout;

    public function __construct(
        Redis $redis,
        string $hashQueueKey = 'queue:hash',
        string $metaQueueKey = 'queue:meta',
        string $seenKey = 'seen:infohash',
        bool $useBloom = false,
        string $bloomKey = 'bloom:infohash',
        int $bloomBits = 134217728,
        int $bloomHashes = 7,
        bool $useSet = true,
        string $host = '127.0.0.1',
        int $port = 6379,
        int $dbIndex = 0,
        float $timeout = 2.0
    ) {
        $this->redis = $redis;
        $this->hashQueueKey = $hashQueueKey;
        $this->metaQueueKey = $metaQueueKey;
        $this->seenKey = $seenKey;
        $this->useBloom = $useBloom;
        $this->bloomKey = $bloomKey;
        $this->bloomBits = max(1024, $bloomBits);
        $this->bloomHashes = max(1, $bloomHashes);
        $this->useSet = $useSet;
        $this->host = $host;
        $this->port = $port;
        $this->dbIndex = $dbIndex;
        $this->timeout = $timeout;
    }

    private function ensureConnected(): void
    {
        try {
            if (!$this->redis->isConnected()) {
                $this->redis->connect($this->host, $this->port, $this->timeout);
                $this->redis->select($this->dbIndex);
            } else {
                // Heartbeat to ensure connection is still alive
                @$this->redis->ping();
            }
        } catch (\Throwable $e) {
            $this->redis->connect($this->host, $this->port, $this->timeout);
            $this->redis->select($this->dbIndex);
        }
    }

    private function execWithRetry(callable $fn)
    {
        $attempts = 0;
        while ($attempts < 2) {
            try {
                $this->ensureConnected();
                return $fn($this->redis);
            } catch (\RedisException $e) {
                $attempts++;
                if ($attempts >= 2) {
                    throw $e;
                }
                // Force reconnect
                try {
                    @$this->redis->close();
                } catch (\Throwable $t) {
                }
            }
        }
    }

    public function markSeen(string $infohash): bool
    {
        return $this->execWithRetry(function (Redis $r) use ($infohash) {
            if ($this->useBloom) {
                $already = $this->bloomHasInternal($r, $infohash);
                $this->bloomAddInternal($r, $infohash);
                if (!$this->useSet) {
                    return !$already;
                }
            }
            return (bool) $r->sAdd($this->seenKey, $infohash);
        });
    }

    public function enqueueHash(string $infohash, int $runAtTs, int $priority = 0): void
    {
        $this->execWithRetry(function (Redis $r) use ($infohash, $runAtTs, $priority) {
            $score = $runAtTs * 1000 + max(-999, min(999, $priority));
            $r->zAdd($this->hashQueueKey, $score, $infohash);
        });
    }

    public function popHashDue(int $nowTs): ?string
    {
        return $this->execWithRetry(function (Redis $r) use ($nowTs) {
            $max = $nowTs * 1000 + 999;
            $lua = <<<'LUA'
local q = KEYS[1]
local max = ARGV[1]
local items = redis.call('ZRANGEBYSCORE', q, '-inf', max, 'LIMIT', 0, 1)
if not items[1] then
  return nil
end
redis.call('ZREM', q, items[1])
return items[1]
LUA;
            $res = $r->eval($lua, [$this->hashQueueKey, (string) $max], 1);
            if (!is_string($res) || $res === '') {
                return null;
            }
            return $res;
        });
    }

    public function pushMeta(array $meta): void
    {
        $payload = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        $this->execWithRetry(function (Redis $r) use ($payload) {
            $r->rPush($this->metaQueueKey, $payload);
        });
    }

    public function popMeta(): ?array
    {
        $payload = $this->execWithRetry(function (Redis $r) {
            return $r->lPop($this->metaQueueKey);
        });
        if (!is_string($payload) || $payload === '') {
            return null;
        }
        $data = json_decode($payload, true);
        return is_array($data) ? $data : null;
    }

    public function rateLimit(string $key, int $limit, int $windowSec): bool
    {
        return $this->execWithRetry(function (Redis $r) use ($key, $limit, $windowSec) {
            $k = 'rl:' . $key;
            $n = (int) $r->incr($k);
            if ($n === 1) {
                $r->expire($k, $windowSec);
            }
            return $n <= $limit;
        });
    }

    private function bloomHasInternal(Redis $r, string $value): bool
    {
        foreach ($this->bloomIndexes($value) as $idx) {
            if ((int) $r->getBit($this->bloomKey, $idx) === 0) {
                return false;
            }
        }
        return true;
    }

    private function bloomAddInternal(Redis $r, string $value): void
    {
        foreach ($this->bloomIndexes($value) as $idx) {
            $r->setBit($this->bloomKey, $idx, 1);
        }
    }

    private function bloomIndexes(string $value): array
    {
        $h1 = (int) sprintf('%u', crc32($value));
        $h2 = (int) hexdec(substr(sha1($value), 0, 8));
        $indexes = [];
        for ($i = 0; $i < $this->bloomHashes; $i++) {
            $idx = ($h1 + $i * $h2) % $this->bloomBits;
            $indexes[] = $idx;
        }
        return $indexes;
    }
}
