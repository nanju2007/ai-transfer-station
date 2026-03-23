<?php

return [
    'redis_consumer' => [
        'handler'     => Webman\RedisQueue\Process\Consumer::class,
        'count'       => 2,
        'constructor' => [
            'consumer_dir' => app_path() . '/queue/redis',
        ],
    ],
];
