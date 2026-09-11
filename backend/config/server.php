<?php

return [
    'listen' => getenv('HTTP_LISTEN') ?: 'http://0.0.0.0:8787',
    'transport' => 'tcp',
    'context' => [],
    'name' => 'webman',
    'count' => cpu_count() * 2,
    'user' => '',
    'group' => '',
    'reuse_port' => false,
    'daemonize' => false,
    'pid_file' => runtime_path() . '/webman.pid',
    'stdout_file' => runtime_path() . '/logs/stdout.log',
    'log_file' => runtime_path() . '/logs/workerman.log',
    'max_package_size' => 10 * 1024 * 1024,
];
