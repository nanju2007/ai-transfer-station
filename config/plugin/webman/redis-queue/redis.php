<?php

return [
    'default' => [
        'host' => 'redis://127.0.0.1:6379',
        'options' => [
            'auth'          => '',
            'db'            => 0,
            'max_attempts'  => 5,
            'retry_seconds' => 5,
        ],
    ],
];
