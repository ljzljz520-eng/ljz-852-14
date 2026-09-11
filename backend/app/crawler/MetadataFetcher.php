<?php

namespace app\crawler;

use RuntimeException;

class MetadataFetcher
{
    private Db $db;
    private RedisQueue $queue;
    private int $connectTimeoutMs;
    private int $ioTimeoutMs;
    private int $fetchDeadlineSec;

    public function __construct(Db $db, RedisQueue $queue)
    {
        $this->db = $db;
        $this->queue = $queue;
        $this->connectTimeoutMs = Env::int('FETCH_CONNECT_TIMEOUT_MS', 2500);
        $this->ioTimeoutMs = Env::int('FETCH_IO_TIMEOUT_MS', 5000);
        $this->fetchDeadlineSec = Env::int('FETCH_DEADLINE_SEC', 15);
    }

    public function run(): void
    {
        Log::info('metadata_worker_start');
        while (true) {
            $infohash = $this->queue->popHashDue(time());
            if (!$infohash) {
                usleep(200000);
                continue;
            }
            $this->process($infohash);
        }
    }

    private function process(string $infohash): void
    {
        $this->db->markQueue($infohash, 'processing', 0, null);
        $peers = $this->db->getPeers($infohash, 30);
        if (!$peers) {
            $next = time() + 30;
            $this->queue->enqueue($infohash, $next, -1);
            $this->db->markQueue($infohash, 'retry', 1, $next);
            return;
        }

        foreach ($peers as $peer) {
            $ip = (string) ($peer['ip'] ?? '');
            $port = (int) ($peer['port'] ?? 0);
            if ($ip === '' || $port <= 0 || $port > 65535) {
                continue;
            }
            if (!$this->queue->rateLimit('peer:' . $ip, 30, 60)) {
                continue;
            }
            try {
                $meta = $this->fetchFromPeer($infohash, $ip, $port);
                $meta['infohash'] = $infohash;
                $meta['created_at'] = time();
                $this->queue->pushMeta($meta);
                $this->db->markQueue($infohash, 'meta_ready', 0, null);
                return;
            } catch (Throwable $e) {
                Log::warn('metadata_fetch_failed', ['infohash' => $infohash, 'ip' => $ip, 'port' => $port, 'err' => $e->getMessage()]);
            }
        }

        $next = time() + 60;
        $this->queue->enqueueHash($infohash, $next, -1);
        $this->db->markQueue($infohash, 'retry', 2, $next);
    }

    private function fetchFromPeer(string $infohashHex, string $ip, int $port): array
    {
        $infohashBin = hex2bin($infohashHex);
        if ($infohashBin === false || strlen($infohashBin) !== 20) {
            throw new RuntimeException('invalid infohash');
        }

        $addr = "tcp://{$ip}:{$port}";
        $timeoutSec = max(1, (int) ceil($this->connectTimeoutMs / 1000));
        $fp = @stream_socket_client($addr, $errno, $errstr, $timeoutSec, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            throw new RuntimeException($errstr ?: 'connect failed');
        }
        stream_set_timeout($fp, max(1, (int) ceil($this->ioTimeoutMs / 1000)));
        stream_set_blocking($fp, true);

        $peerId = '-WD0001-' . bin2hex(random_bytes(6));
        $peerId = substr($peerId, 0, 20);
        $reserved = "\x00\x00\x00\x00\x00\x10\x00\x00";
        $pstr = 'BitTorrent protocol';
        $hs = chr(strlen($pstr)) . $pstr . $reserved . $infohashBin . $peerId;
        $this->writeAll($fp, $hs);
        $resp = $this->readExactly($fp, 68);
        if (strlen($resp) !== 68) {
            throw new RuntimeException('handshake short');
        }

        $utId = null;
        $metaSize = null;
        $pieces = [];
        $totalPieces = null;

        $this->sendExtendedHandshake($fp);

        $started = time();
        while (time() - $started < $this->fetchDeadlineSec) {
            $msg = $this->readMessage($fp);
            if ($msg === null) {
                continue;
            }
            [$id, $payload] = $msg;
            if ($id !== 20 || $payload === '') {
                continue;
            }
            $extId = ord($payload[0]);
            $extPayload = substr($payload, 1);
            if ($extId === 0) {
                $dict = Bencode::decode($extPayload);
                $m = $dict['m'] ?? [];
                $utId = is_array($m) ? ($m['ut_metadata'] ?? null) : null;
                $metaSize = $dict['metadata_size'] ?? null;
                if (is_int($utId) && is_int($metaSize) && $metaSize > 0) {
                    $totalPieces = (int) ceil($metaSize / 16384);
                    for ($p = 0; $p < $totalPieces; $p++) {
                        $this->requestPiece($fp, $utId, $p);
                    }
                }
                continue;
            }

            if ($utId !== null && $extId === (int) $utId) {
                [$dict, $consumed] = Bencode::decodeWithConsumed($extPayload);
                if (!is_array($dict)) {
                    continue;
                }
                $msgType = (int) ($dict['msg_type'] ?? -1);
                $piece = (int) ($dict['piece'] ?? -1);
                if ($msgType === 1 && $piece >= 0) {
                    $data = substr($extPayload, $consumed);
                    $pieces[$piece] = $data;
                    if ($totalPieces !== null && count($pieces) >= $totalPieces) {
                        $raw = '';
                        for ($i = 0; $i < $totalPieces; $i++) {
                            $raw .= $pieces[$i] ?? '';
                        }
                        $raw = substr($raw, 0, (int) $metaSize);
                        fclose($fp);
                        return $this->parseInfoDict($raw);
                    }
                }
            }
        }

        fclose($fp);
        throw new RuntimeException('metadata timeout');
    }

    private function parseInfoDict(string $raw): array
    {
        $info = Bencode::decode($raw);
        if (!is_array($info)) {
            throw new RuntimeException('invalid info dict');
        }
        $name = $info['name.utf-8'] ?? $info['name'] ?? '';
        if (!is_string($name) || $name === '') {
            $name = 'unknown';
        }

        $files = [];
        $sizeTotal = 0;
        if (isset($info['files']) && is_array($info['files'])) {
            foreach ($info['files'] as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $len = (int) ($f['length'] ?? 0);
                $pathParts = $f['path.utf-8'] ?? $f['path'] ?? [];
                $path = is_array($pathParts) ? implode('/', array_map(fn($p) => is_string($p) ? $p : '', $pathParts)) : '';
                $files[] = ['path' => $path, 'size' => $len];
                $sizeTotal += $len;
            }
        } else {
            $len = (int) ($info['length'] ?? 0);
            $files[] = ['path' => $name, 'size' => $len];
            $sizeTotal = $len;
        }

        $fileCount = count($files);
        $extension = '';
        if ($fileCount === 1) {
            $path = (string) ($files[0]['path'] ?? '');
            $pos = strrpos($path, '.');
            if ($pos !== false && $pos + 1 < strlen($path)) {
                $extension = strtolower(substr($path, $pos + 1));
            }
        }

        return [
            'name' => $name,
            'files' => $files,
            'size_total' => $sizeTotal,
            'file_count' => $fileCount,
            'extension' => $extension,
        ];
    }

    private function sendExtendedHandshake($fp): void
    {
        $dict = ['m' => ['ut_metadata' => 1]];
        $payload = Bencode::encode($dict);
        $msg = chr(20) . chr(0) . $payload;
        $this->writeAll($fp, pack('N', strlen($msg)) . $msg);
    }

    private function requestPiece($fp, int $utId, int $piece): void
    {
        $dict = ['msg_type' => 0, 'piece' => $piece];
        $payload = Bencode::encode($dict);
        $msg = chr(20) . chr($utId) . $payload;
        $this->writeAll($fp, pack('N', strlen($msg)) . $msg);
    }

    private function readMessage($fp): ?array
    {
        $lenBuf = $this->readExactly($fp, 4);
        if ($lenBuf === '') {
            return null;
        }
        if (strlen($lenBuf) !== 4) {
            throw new RuntimeException('msg len short');
        }
        $len = unpack('N', $lenBuf)[1];
        if ($len === 0) {
            return null;
        }
        $msg = $this->readExactly($fp, $len);
        if (strlen($msg) !== $len) {
            throw new RuntimeException('msg short');
        }
        $id = ord($msg[0]);
        $payload = substr($msg, 1);
        return [$id, $payload];
    }

    private function readExactly($fp, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($fp, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (!empty($meta['timed_out'])) {
                    return '';
                }
                break;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function writeAll($fp, string $data): void
    {
        $off = 0;
        $len = strlen($data);
        while ($off < $len) {
            $w = fwrite($fp, substr($data, $off));
            if ($w === false || $w === 0) {
                throw new RuntimeException('write failed');
            }
            $off += $w;
        }
    }
}
