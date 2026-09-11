<?php
namespace support;

use Monolog\Logger;

class Log
{
    private static $_loggers = [];

    /**
     * @param string $name
     * @return Logger
     */
    public static function channel($name = 'default')
    {
        if (!isset(static::$_loggers[$name])) {
            $config = config("log.$name");
            if (!$config) {
                // Fallback to default if named channel not found
                $config = config('log.default');
            }

            $logger = new Logger($name);
            if ($config && isset($config['handlers'])) {
                foreach ($config['handlers'] as $handlerConfig) {
                    $class = $handlerConfig['class'];
                    $constructor = $handlerConfig['constructor'] ?? [];
                    $handler = new $class(...$constructor);

                    if (isset($handlerConfig['formatter'])) {
                        $formatterConfig = $handlerConfig['formatter'];
                        $formatterClass = $formatterConfig['class'];
                        $formatterConstructor = $formatterConfig['constructor'] ?? [];
                        $formatter = new $formatterClass(...$formatterConstructor);
                        $handler->setFormatter($formatter);
                    }

                    $logger->pushHandler($handler);
                }
            }
            static::$_loggers[$name] = $logger;
        }
        return static::$_loggers[$name];
    }

    public static function __callStatic($name, $arguments)
    {
        return static::channel()->{$name}(...$arguments);
    }
}
