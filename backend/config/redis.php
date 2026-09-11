<?php

return [
    'default' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => (int) (getenv('REDIS_DB') ?: 0),
        'pool' => [
            'max_connections' => 20,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 50,
            'heartbeat_interval' => 50,
        ],
    ],
    'queue_hash' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => (int) (getenv('REDIS_QUEUE_DB') ?: 1),
        'pool' => [
            'max_connections' => 10,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 50,
            'heartbeat_interval' => 50,
        ],
    ],
    'queue_meta' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'database' => (int) (getenv('REDIS_META_DB') ?: 2),
        'pool' => [
            'max_connections' => 10,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 50,
            'heartbeat_interval' => 50,
        ],
    ],
];
