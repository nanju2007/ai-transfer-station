<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Webman\Config;
use support\App;

ini_set('display_errors', 'on');
error_reporting(E_ALL);

if (is_callable('opcache_reset')) {
    opcache_reset();
}

if (!$appConfigFile = config_path('app.php')) {
    throw new RuntimeException('Config file not found: app.php');
}
$appConfig = require $appConfigFile;
if ($timezone = $appConfig['default_timezone'] ?? '') {
    date_default_timezone_set($timezone);
}

App::loadAllConfig(['route']);

worker_start('plugin.webman.redis-queue.redis_consumer', config('plugin.webman.redis-queue.process')['redis_consumer']);

if (DIRECTORY_SEPARATOR != "/") {
    Worker::$logFile = config('server')['log_file'] ?? Worker::$logFile;
    TcpConnection::$defaultMaxPackageSize = config('server')['max_package_size'] ?? 10*1024*1024;
}

Worker::runAll();
