<?php

namespace app\crawler;

class IndexerWorker
{
    private Db $db;
    private RedisQueue $queue;
    private TorrentIndexer $indexer;
    private array $spamExtensions;

    public function __construct(Db $db, RedisQueue $queue, TorrentIndexer $indexer)
    {
        $this->db = $db;
        $this->queue = $queue;
        $this->indexer = $indexer;
        $ext = Env::str('SPAM_EXTENSIONS', 'html,htm,url,exe,lnk,apk,bat,cmd,scr,com,js');
        $this->spamExtensions = array_values(array_filter(array_map('trim', explode(',', $ext))));
    }

    public function run(): void
    {
        Log::info('indexer_worker_start');
        while (true) {
            $meta = $this->queue->popMeta();
            if (!$meta) {
                usleep(200000);
                continue;
            }
            $this->process($meta);
        }
    }

    private function process(array $meta): void
    {
        $infohash = (string) ($meta['infohash'] ?? '');
        $name = (string) ($meta['name'] ?? '');
        $sizeTotal = (int) ($meta['size_total'] ?? 0);
        $files = $meta['files'] ?? null;
        $fileCount = (int) ($meta['file_count'] ?? 0);
        $extension = (string) ($meta['extension'] ?? '');
        $tags = (string) ($meta['tags'] ?? '');
        $createdAt = (int) ($meta['created_at'] ?? time());

        if ($infohash === '' || $name === '') {
            return;
        }

        if ($this->isSpam($fileCount, $extension)) {
            $this->db->upsertTorrent($infohash, $name, $sizeTotal, $files, 'spam', $fileCount, $extension, $tags);
            $this->db->markQueue($infohash, 'spam', 0, null);
            return;
        }

        $this->db->upsertTorrent($infohash, $name, $sizeTotal, $files, 'fetched', $fileCount, $extension, $tags);
        $this->indexer->upsert($infohash, $name, $sizeTotal, $createdAt, $tags, $files);
        $this->db->markQueue($infohash, 'done', 0, null);
    }

    private function isSpam(int $fileCount, string $extension): bool
    {
        if ($fileCount !== 1 || $extension === '') {
            return false;
        }
        return in_array(strtolower($extension), $this->spamExtensions, true);
    }
}
