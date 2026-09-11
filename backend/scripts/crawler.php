<?php

require_once __DIR__ . '/../vendor/autoload.php';

use app\crawler\Db;
use app\crawler\DhtListener;
use app\crawler\Env;
use app\crawler\IndexerWorker;
use app\crawler\Log;
use app\crawler\MetadataFetcher;
use app\crawler\RedisQueue;
use app\crawler\TorrentIndexer;

$db = Db::connectFromEnv();
$db->waitReady(60, 1000);
$db->ensureSchema();

$redis = new Redis();
$redisHost = Env::str('REDIS_HOST', '127.0.0.1');
$redisPort = Env::int('REDIS_PORT', 6379);
$redisDb = Env::int('REDIS_DB', 0);

$hashQueueKey = Env::str('REDIS_QUEUE_HASH', 'queue:hash');
$metaQueueKey = Env::str('REDIS_QUEUE_META', 'queue:meta');
$seenKey = Env::str('REDIS_SEEN_KEY', 'seen:infohash');
$useBloom = Env::int('BLOOM_ENABLED', 0) === 1;
$bloomKey = Env::str('BLOOM_KEY', 'bloom:infohash');
$bloomBits = Env::int('BLOOM_BITS', 134217728);
$bloomHashes = Env::int('BLOOM_HASHES', 7);
$useSet = Env::int('BLOOM_USE_SET', 1) === 1;
$queue = new RedisQueue($redis, $hashQueueKey, $metaQueueKey, $seenKey, $useBloom, $bloomKey, $bloomBits, $bloomHashes, $useSet, $redisHost, $redisPort, $redisDb, 2.0);
$indexer = new TorrentIndexer();
$indexer->ensureTable();

$bind = Env::str('DHT_BIND', '0.0.0.0:6881');
$bootstrap = Env::str('DHT_BOOTSTRAP', 'router.bittorrent.com:6881,dht.transmissionbt.com:6881,router.utorrent.com:6881');

$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('fork failed');
}

if ($pid === 0) {
    $dht = new DhtListener($db, $queue, $bind, $bootstrap);
    $dht->run();
    exit(0);
}

$metaPid = pcntl_fork();
if ($metaPid === -1) {
    throw new RuntimeException('fork failed');
}

if ($metaPid === 0) {
    $worker = new MetadataFetcher($db, $queue);
    $worker->run();
    exit(0);
}

Log::info('crawler_master_start', ['dht_pid' => $pid, 'meta_pid' => $metaPid]);

$indexWorker = new IndexerWorker($db, $queue, $indexer);
$indexWorker->run();
