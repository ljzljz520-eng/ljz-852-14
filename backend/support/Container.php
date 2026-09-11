<?php
namespace support;

use Webman\Container as BaseContainer;
use Webman\App;

class Container extends BaseContainer
{
    /**
     * @var Container
     */
    protected static $instance = null;

    /**
     * @param Container $container
     * @return Container|null
     */
    public static function instance($container = null)
    {
        if ($container) {
            static::$instance = $container;
        }
        return static::$instance;
    }
}
