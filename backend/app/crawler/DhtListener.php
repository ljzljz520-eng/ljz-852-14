<?php

namespace app\crawler;

use RuntimeException;

class DhtListener
{
    private $sock;
    private string $bindIp;
    private int $bindPort;
    private string $nodeId;
    private Db $db;
    private RedisQueue $queue;
    private array $bootstrap;
    private int $lastBootstrapAt = 0;

    public function __construct(Db $db, RedisQueue $queue, string $bind, string $bootstrap)
    {
        [$ip, $port] = $this->parseBind($bind);
        $this->bindIp = $ip;
        $this->bindPort = $port;
        $this->nodeId = random_bytes(20);
        $this->db = $db;
        $this->queue = $queue;
        $this->bootstrap = array_values(array_filter(array_map('trim', explode(',', $bootstrap))));
    }

    public function run(): void
    {
        $this->sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->sock === false) {
            throw new RuntimeException('socket_create failed');
        }
        socket_set_option($this->sock, SOL_SOCKET, SO_REUSEADDR, 1);
        if (!@socket_bind($this->sock, $this->bindIp, $this->bindPort)) {
            throw new RuntimeException('socket_bind failed');
        }
        socket_set_nonblock($this->sock);

        Log::info('dht_listen', ['bind' => $this->bindIp . ':' . $this->bindPort]);

        while (true) {
            $this->tickBootstrap();

            $r = [$this->sock];
            $w = [];
            $e = [];
            $n = @socket_select($r, $w, $e, 1);
            if ($n === false || $n === 0) {
                continue;
            }

            $buf = '';
            $fromIp = '';
            $fromPort = 0;
            $len = @socket_recvfrom($this->sock, $buf, 65535, 0, $fromIp, $fromPort);
            if ($len === false || $len === 0) {
                continue;
            }
            $this->handlePacket($buf, $fromIp, (int) $fromPort);
        }
    }

    private function handlePacket(string $buf, string $fromIp, int $fromPort): void
    {
        try {
            $msg = Bencode::decode($buf);
            if (!is_array($msg)) {
                return;
            }
            $y = $msg['y'] ?? null;
            $t = $msg['t'] ?? null;
            if (!is_string($y) || !is_string($t)) {
                return;
            }

            if ($y === 'q') {
                $q = $msg['q'] ?? '';
                $a = $msg['a'] ?? [];
                if (!is_string($q) || !is_array($a)) {
                    return;
                }
                if ($q === 'announce_peer' || $q === 'get_peers') {
                    $infoBin = $a['info_hash'] ?? null;
                    if (is_string($infoBin) && strlen($infoBin) === 20) {
                        $infohash = bin2hex($infoBin);
                        $peerPort = $fromPort;
                        if ($q === 'announce_peer') {
                            $implied = (int) ($a['implied_port'] ?? 0);
                            $peerPort = $implied === 1 ? $fromPort : (int) ($a['port'] ?? $fromPort);
                        }
                        if ($peerPort > 0 && $peerPort <= 65535) {
                            $this->db->upsertPeer($infohash, $fromIp, $peerPort);
                        }
                        $isNew = $this->queue->markSeen($infohash);
                        if ($isNew) {
                            $now = time();
                            $this->queue->enqueueHash($infohash, $now, 0);
                            $this->db->markQueue($infohash, 'queued', 0, $now);
                        }
                    }
                }

                if ($q === 'ping' || $q === 'find_node' || $q === 'get_peers' || $q === 'announce_peer') {
                    $this->replyOk($t, $fromIp, $fromPort, $q);
                }
            }
        } catch (Throwable $e) {
            Log::warn('dht_packet_error', ['err' => $e->getMessage()]);
        }
    }

    private function replyOk(string $t, string $ip, int $port, string $q): void
    {
        $r = ['id' => $this->nodeId];
        if ($q === 'get_peers') {
            $r['nodes'] = '';
            $r['token'] = $this->tokenForIp($ip);
        }
        $resp = [
            't' => $t,
            'y' => 'r',
            'r' => $r,
        ];
        $out = Bencode::encode($resp);
        @socket_sendto($this->sock, $out, strlen($out), 0, $ip, $port);
    }

    private function tickBootstrap(): void
    {
        $now = time();
        if ($now - $this->lastBootstrapAt < 5) {
            return;
        }
        $this->lastBootstrapAt = $now;
        foreach ($this->bootstrap as $node) {
            [$host, $port] = $this->parseHostPort($node);
            if ($host === '') {
                continue;
            }
            $this->sendFindNode($host, $port);
        }
    }

    private function sendFindNode(string $host, int $port): void
    {
        $t = random_bytes(2);
        $msg = [
            't' => $t,
            'y' => 'q',
            'q' => 'find_node',
            'a' => [
                'id' => $this->nodeId,
                'target' => random_bytes(20),
            ],
        ];
        $out = Bencode::encode($msg);
        @socket_sendto($this->sock, $out, strlen($out), 0, $host, $port);
    }

    private function tokenForIp(string $ip): string
    {
        return substr(sha1($ip, true), 0, 4);
    }

    private function parseBind(string $bind): array
    {
        $parts = explode(':', $bind, 2);
        $ip = $parts[0] !== '' ? $parts[0] : '0.0.0.0';
        $port = isset($parts[1]) ? (int) $parts[1] : 6881;
        return [$ip, $port];
    }

    private function parseHostPort(string $s): array
    {
        $parts = explode(':', $s, 2);
        $host = $parts[0] ?? '';
        $port = isset($parts[1]) ? (int) $parts[1] : 6881;
        return [$host, $port];
    }
}
