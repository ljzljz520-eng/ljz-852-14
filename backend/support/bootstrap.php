<?php

ini_set('display_errors', config('app.debug', false) ? '1' : '0');
error_reporting((int) config('app.error_reporting', E_ALL));
date_default_timezone_set((string) config('app.default_timezone', 'UTC'));

$container = config('container');
if ($container) {
    \support\Container::instance($container);
}
if (class_exists(\Webman\ThinkOrm\ThinkOrm::class)) {
    \Webman\ThinkOrm\ThinkOrm::start(null);
}
