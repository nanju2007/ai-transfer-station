<?php

return [
    // 全局中间件
    '' => [
        app\middleware\AccessControl::class,
        app\middleware\RequestLog::class,
        app\middleware\ThrottleSubmit::class,
    ],
    // 管理端中间件
    'admin' => [
        app\middleware\AdminAuth::class,
    ],
    // 用户端中间件
    'api' => [
        app\middleware\UserAuth::class,
    ],
];
