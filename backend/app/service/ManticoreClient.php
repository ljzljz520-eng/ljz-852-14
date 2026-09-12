<?php

namespace app\service;

use RuntimeException;

class ManticoreClient
{
    private string $baseUrl;
    private string $table;
    private int $timeoutSeconds;

    public function __construct(?string $baseUrl = null, ?string $table = null, int $timeoutSeconds = 5)
    {
        $this->baseUrl = rtrim($baseUrl ?: (getenv('MANTICORE_HTTP') ?: 'http://127.0.0.1:9308'), '/');
        $this->table = $table ?: (getenv('MANTICORE_INDEX') ?: 'torrents_rt');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function search(string $q, int $page, int $pageSize, string $sort): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $matchQuery = $this->buildFuzzyQuery($q);
        $query = [
            'table' => $this->table,
            'query' => [
                'match' => [
                    '*' => $matchQuery,
                ],
            ],
            'limit' => $pageSize,
            'offset' => $offset,
            'highlight' => [
                'fields' => ['name'],
            ],
        ];

        if ($sort === 'new') {
            $query['sort'] = [['created_at' => 'desc']];
        } elseif ($sort === 'size') {
            $query['sort'] = [['size_total' => 'desc']];
        }

        $resp = $this->postJson('/search', $query);

        $total = (int) ($resp['hits']['total'] ?? 0);
        $hits = $resp['hits']['hits'] ?? [];

        $items = [];
        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? ($hit['source'] ?? []);
            $highlight = $hit['highlight'] ?? [];
            $nameSnippets = $highlight['name'] ?? [];
            $items[] = [
                'infohash' => (string) ($source['infohash'] ?? ''),
                'name' => (string) ($source['name'] ?? ''),
                'name_highlight' => $nameSnippets ? (string) $nameSnippets[0] : null,
                'tags' => (string) ($source['tags'] ?? ''),
                'size_total' => (int) ($source['size_total'] ?? 0),
                'created_at' => (int) ($source['created_at'] ?? 0),
            ];
        }

        return [
            'total' => $total,
            'items' => $items,
        ];
    }

    private function buildFuzzyQuery(string $q): string
    {
        $q = trim($q);
        if ($q === '' || str_contains($q, '"')) {
            return $q;
        }

        $parts = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return $q;
        }

        $fuzzy = [];
        foreach ($parts as $part) {
            if (preg_match('/[\*\?\~]/', $part)) {
                $fuzzy[] = $part;
                continue;
            }
            if (strlen($part) <= 1) {
                $fuzzy[] = $part;
                continue;
            }
            $fuzzy[] = $part . '*';
        }

        return implode(' ', $fuzzy);
    }

    private function postJson(string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('json encode failed');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new RuntimeException($err ?: 'curl error');
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("manticore http {$code}: {$resp}");
        }
        $data = json_decode($resp, true);
        if (!is_array($data)) {
            throw new RuntimeException('invalid json response');
        }
        return $data;
    }
}
