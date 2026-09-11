<?php

return [
    'debug' => true,
    'error_reporting' => E_ALL,
    'default_timezone' => getenv('APP_TZ') ?: 'Asia/Shanghai',
    'public_path' => base_path() . DIRECTORY_SEPARATOR . 'public',
    'runtime_path' => base_path(false) . DIRECTORY_SEPARATOR . 'runtime',
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
];

