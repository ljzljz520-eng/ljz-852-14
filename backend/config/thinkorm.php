<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'type' => 'mysql',
            'hostname' => getenv('DB_HOST') ?: '127.0.0.1',
            'database' => getenv('DB_DATABASE') ?: 'dht_search',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: 'root',
            'hostport' => (int) (getenv('DB_PORT') ?: 3306),
            'charset' => 'utf8mb4',
            'prefix' => '',
            'debug' => false,
            'break_reconnect' => true,
        ],
    ],
];

