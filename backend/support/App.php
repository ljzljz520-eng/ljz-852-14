<?php
namespace support;

use Webman\App as BaseApp;
use Workerman\Worker;

class App extends BaseApp
{
    /**
     * Run.
     * @return void
     */
    public static function run()
    {
        // 1. Load helpers and config
        if (!function_exists('config_path')) {
            $helpers = __DIR__ . '/../vendor/workerman/webman-framework/src/support/helpers.php';
            if (file_exists($helpers)) {
                require_once $helpers;
            }
        }

        // Ensure config path exists
        if (function_exists('config_path')) {
            \Webman\Config::load(config_path(), ['route']);
        } else {
            \Webman\Config::load(__DIR__ . '/../config', ['route']);
        }

        // 2. Load autoload files
        $autoload = config('autoload.files', []);
        foreach ($autoload as $file) {
            include_once $file;
        }

        // 3. Load Routes
        $configPath = function_exists('config_path') ? config_path() : __DIR__ . '/../config';
        \Webman\Route::load([$configPath]);

        // 4. Start Server
        $config = config('server');
        if (!$config) {
            throw new \RuntimeException("Server config missing! check config/server.php");
        }

        $worker = new Worker($config['listen'], $config['context']);
        $worker->count = $config['count'];
        $worker->name = $config['name'];
        if (isset($config['user'])) {
            $worker->user = $config['user'];
        }
        if (isset($config['group'])) {
            $worker->group = $config['group'];
        }
        if (isset($config['reuse_port'])) {
            $worker->reusePort = $config['reuse_port'];
        }

        // Instantiate App with required dependencies
        $app = new static(
            \support\Request::class,
            \support\Log::channel('default'),
            app_path(),
            public_path()
        );
        $worker->onWorkerStart = [$app, 'onWorkerStart'];
        $worker->onMessage = [$app, 'onMessage'];

        Worker::runAll();
    }
}
