<?php

use Webman\Route;

// ============================================
// 系统安装路由（无需认证）
// ============================================
Route::get('/install', [app\controller\InstallController::class, 'index']);
Route::post('/install/check-env', [app\controller\InstallController::class, 'checkEnv']);
Route::post('/install/test-db', [app\controller\InstallController::class, 'testDb']);
Route::post('/install/test-redis', [app\controller\InstallController::class, 'testRedis']);
Route::post('/install/execute', [app\controller\InstallController::class, 'execute']);

// ============================================
// 管理端API认证路由（无需AdminAuth中间件）
// ============================================
Route::group('/api/admin', function () {
    Route::post('/auth/login', [app\controller\admin\AuthController::class, 'login']);
});

// ============================================
// 管理端API路由 /api/admin/*
// ============================================
Route::group('/api/admin', function () {
    // 认证信息
    Route::post('/auth/logout', [app\controller\admin\AuthController::class, 'logout']);
    Route::get('/auth/info', [app\controller\admin\AuthController::class, 'info']);

    // 渠道管理
    Route::get('/channels', [app\controller\admin\ChannelController::class, 'index']);
    Route::post('/channels', [app\controller\admin\ChannelController::class, 'store']);
    Route::get('/channels/rate-limit-status', [app\controller\admin\ChannelController::class, 'rateLimitStatus']);
    Route::get('/channels/{id}', [app\controller\admin\ChannelController::class, 'show']);
    Route::put('/channels/{id}', [app\controller\admin\ChannelController::class, 'update']);
    Route::delete('/channels/{id}', [app\controller\admin\ChannelController::class, 'destroy']);
    Route::post('/channels/{id}/test', [app\controller\admin\ChannelController::class, 'test']);
    Route::put('/channels/{id}/status', [app\controller\admin\ChannelController::class, 'updateStatus']);
    Route::get('/channels/{id}/balance', [app\controller\admin\ChannelController::class, 'balance']);

    // 模型管理
    Route::get('/models', [app\controller\admin\ModelController::class, 'index']);
    Route::post('/models', [app\controller\admin\ModelController::class, 'store']);
    Route::get('/models/channel-models', [app\controller\admin\ModelController::class, 'channelModels']);
    Route::post('/models/batch-add', [app\controller\admin\ModelController::class, 'batchAdd']);
    Route::post('/models/batch-create', [app\controller\admin\ModelController::class, 'batchCreate']);
    Route::post('/models/import', [app\controller\admin\ModelController::class, 'importFromChannel']);
    Route::get('/models/{id}', [app\controller\admin\ModelController::class, 'show']);
    Route::put('/models/{id}', [app\controller\admin\ModelController::class, 'update']);
    Route::delete('/models/{id}', [app\controller\admin\ModelController::class, 'destroy']);

    // 模型计费
    Route::get('/pricing', [app\controller\admin\ModelPricingController::class, 'index']);
    Route::post('/pricing', [app\controller\admin\ModelPricingController::class, 'store']);
    Route::put('/pricing/{id}', [app\controller\admin\ModelPricingController::class, 'update']);
    Route::delete('/pricing/{id}', [app\controller\admin\ModelPricingController::class, 'destroy']);

    // 兑换码管理
    Route::get('/redemptions', [app\controller\admin\RedemptionCodeController::class, 'index']);
    Route::post('/redemptions', [app\controller\admin\RedemptionCodeController::class, 'store']);
    Route::delete('/redemptions/{id}', [app\controller\admin\RedemptionCodeController::class, 'destroy']);
    Route::post('/redemptions/batch-destroy', [app\controller\admin\RedemptionCodeController::class, 'batchDestroy']);
    Route::put('/redemptions/{id}/status', [app\controller\admin\RedemptionCodeController::class, 'updateStatus']);

    // 用户管理
    Route::get('/users', [app\controller\admin\UserController::class, 'index']);
    Route::post('/users', [app\controller\admin\UserController::class, 'store']);
    Route::get('/users/{id}', [app\controller\admin\UserController::class, 'show']);
    Route::put('/users/{id}', [app\controller\admin\UserController::class, 'update']);
    Route::delete('/users/{id}', [app\controller\admin\UserController::class, 'destroy']);
    Route::put('/users/{id}/status', [app\controller\admin\UserController::class, 'updateStatus']);
    Route::put('/users/{id}/quota', [app\controller\admin\UserController::class, 'updateQuota']);

    // 日志管理
    Route::get('/logs', [app\controller\admin\LogController::class, 'index']);
    Route::get('/logs/search', [app\controller\admin\LogController::class, 'search']);
    Route::get('/logs/statistics', [app\controller\admin\LogController::class, 'statistics']);
    Route::delete('/logs/cleanup', [app\controller\admin\LogController::class, 'cleanup']);
    Route::delete('/logs/{id}', [app\controller\admin\LogController::class, 'destroy']);

    // 厂商管理
    Route::get('/providers', [app\controller\admin\ProviderController::class, 'index']);
    Route::post('/providers', [app\controller\admin\ProviderController::class, 'store']);
    Route::put('/providers/{id}', [app\controller\admin\ProviderController::class, 'update']);
    Route::delete('/providers/{id}', [app\controller\admin\ProviderController::class, 'destroy']);

    // 分组管理
    Route::get('/groups', [app\controller\admin\GroupController::class, 'index']);
    Route::post('/groups', [app\controller\admin\GroupController::class, 'store']);
    Route::put('/groups/{id}', [app\controller\admin\GroupController::class, 'update']);
    Route::delete('/groups/{id}', [app\controller\admin\GroupController::class, 'destroy']);

    // 模型分类管理
    Route::get('/model-categories/list', [app\controller\admin\ModelCategoryController::class, 'index']);
    Route::post('/model-categories/save', [app\controller\admin\ModelCategoryController::class, 'save']);
    Route::post('/model-categories/delete', [app\controller\admin\ModelCategoryController::class, 'delete']);
    Route::get('/model-categories/all', [app\controller\admin\ModelCategoryController::class, 'all']);
    Route::get('/model-categories/channels', [app\controller\admin\ModelCategoryController::class, 'channels']);
    Route::post('/model-categories/save-channel', [app\controller\admin\ModelCategoryController::class, 'saveChannel']);
    Route::post('/model-categories/delete-channel', [app\controller\admin\ModelCategoryController::class, 'deleteChannel']);
    Route::post('/model-categories/update-status', [app\controller\admin\ModelCategoryController::class, 'updateStatus']);

    // 公告管理
    Route::get('/announcements', [app\controller\admin\AnnouncementController::class, 'index']);
    Route::post('/announcements', [app\controller\admin\AnnouncementController::class, 'store']);
    Route::put('/announcements/{id}', [app\controller\admin\AnnouncementController::class, 'update']);
    Route::delete('/announcements/{id}', [app\controller\admin\AnnouncementController::class, 'destroy']);

    // 系统设置
    Route::get('/options', [app\controller\admin\OptionController::class, 'index']);
    Route::put('/options', [app\controller\admin\OptionController::class, 'update']);

    // 屏蔽词管理（通过OptionController）
    Route::get('/blocked-words', [app\controller\admin\OptionController::class, 'blockedWords']);
    Route::post('/blocked-words', [app\controller\admin\OptionController::class, 'addBlockedWord']);
    Route::put('/blocked-words/{id}', [app\controller\admin\OptionController::class, 'updateBlockedWord']);
    Route::delete('/blocked-words/{id}', [app\controller\admin\OptionController::class, 'deleteBlockedWord']);

    // 仪表盘
    Route::get('/dashboard', [app\controller\admin\DashboardController::class, 'index']);

    // 工单管理
    Route::get('/tickets/list', [app\controller\admin\TicketController::class, 'index']);
    Route::get('/tickets/detail', [app\controller\admin\TicketController::class, 'detail']);
    Route::post('/tickets/reply', [app\controller\admin\TicketController::class, 'reply']);
    Route::post('/tickets/update-status', [app\controller\admin\TicketController::class, 'updateStatus']);
    Route::post('/tickets/update-priority', [app\controller\admin\TicketController::class, 'updatePriority']);
    Route::post('/tickets/delete', [app\controller\admin\TicketController::class, 'delete']);
    Route::get('/tickets/stats', [app\controller\admin\TicketController::class, 'stats']);
})->middleware([
    app\middleware\AdminAuth::class,
]);

// ============================================
// 用户端API路由 /api/user/*
// ============================================

// 认证路由（无需UserAuth中间件）
Route::group('/api/user', function () {
    Route::post('/auth/login', [app\controller\user\AuthController::class, 'login']);
    Route::post('/auth/register', [app\controller\user\AuthController::class, 'register']);
    Route::post('/auth/forgot-password', [app\controller\user\AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [app\controller\user\AuthController::class, 'resetPassword']);
    Route::post('/auth/send-email-code', [app\controller\user\AuthController::class, 'sendEmailCode']);
});

// 需要登录的用户端路由
Route::group('/api/user', function () {
    // 认证信息
    Route::post('/auth/logout', [app\controller\user\AuthController::class, 'logout']);
    Route::get('/auth/info', [app\controller\user\AuthController::class, 'info']);

    // 签到
    Route::post('/checkin', [app\controller\user\CheckinController::class, 'checkin']);
    Route::get('/checkin/status', [app\controller\user\CheckinController::class, 'status']);

    // 数据看板
    Route::get('/dashboard', [app\controller\user\DashboardController::class, 'index']);
    Route::get('/dashboard/recent-logs', [app\controller\user\DashboardController::class, 'recentLogs']);

    // 令牌管理
    Route::get('/tokens', [app\controller\user\TokenController::class, 'index']);
    Route::post('/tokens', [app\controller\user\TokenController::class, 'store']);
    Route::get('/tokens/{id}', [app\controller\user\TokenController::class, 'show']);
    Route::put('/tokens/{id}', [app\controller\user\TokenController::class, 'update']);
    Route::delete('/tokens/{id}', [app\controller\user\TokenController::class, 'destroy']);
    Route::put('/tokens/{id}/status', [app\controller\user\TokenController::class, 'toggleStatus']);
    Route::post('/tokens/{id}/reset-key', [app\controller\user\TokenController::class, 'resetKey']);

    // 使用日志
    Route::get('/logs', [app\controller\user\LogController::class, 'index']);
    Route::get('/logs/statistics', [app\controller\user\LogController::class, 'statistics']);
    Route::get('/logs/{id:\d+}/billing', [app\controller\user\LogController::class, 'billingDetail']);

    // 钱包管理
    Route::get('/wallet', [app\controller\user\WalletController::class, 'index']);
    Route::get('/wallet/transactions', [app\controller\user\WalletController::class, 'transactions']);
    Route::post('/wallet/redeem', [app\controller\user\WalletController::class, 'redeem']);
    Route::post('/wallet/pay', [app\controller\user\WalletController::class, 'pay']);

    // 个人设置
    Route::get('/profile', [app\controller\user\ProfileController::class, 'index']);
    Route::put('/profile', [app\controller\user\ProfileController::class, 'update']);
    Route::put('/profile/password', [app\controller\user\ProfileController::class, 'changePassword']);

    // 操练场
    Route::get('/playground/models', [app\controller\user\PlaygroundController::class, 'models']);
    Route::get('/playground/token', [app\controller\user\PlaygroundController::class, 'token']);
    Route::get('/playground/tokens', [app\controller\user\PlaygroundController::class, 'tokens']);

    // 工单管理
    Route::get('/tickets/list', [app\controller\user\TicketController::class, 'index']);
    Route::get('/tickets/detail', [app\controller\user\TicketController::class, 'detail']);
    Route::post('/tickets/create', [app\controller\user\TicketController::class, 'create']);
    Route::post('/tickets/reply', [app\controller\user\TicketController::class, 'reply']);
    Route::post('/tickets/close', [app\controller\user\TicketController::class, 'close']);
})->middleware([
    app\middleware\UserAuth::class,
]);

// ============================================
// AI中转路由 /v1/*
// ============================================
Route::group('/v1', function () {
    // OpenAI兼容接口
    Route::post('/chat/completions', [app\relay\controller\RelayController::class, 'chatCompletions']);
    Route::post('/completions', [app\relay\controller\RelayController::class, 'completions']);
    Route::post('/embeddings', [app\relay\controller\RelayController::class, 'embeddings']);
    Route::post('/images/generations', [app\relay\controller\RelayController::class, 'imageGenerations']);
    Route::post('/audio/transcriptions', [app\relay\controller\RelayController::class, 'audioTranscriptions']);
    Route::post('/audio/speech', [app\relay\controller\RelayController::class, 'audioSpeech']);
    Route::post('/moderations', [app\relay\controller\RelayController::class, 'moderations']);
    Route::get('/models', [app\relay\controller\RelayController::class, 'models']);
    Route::get('/models/{model}', [app\relay\controller\RelayController::class, 'modelDetail']);

    // Anthropic兼容接口
    Route::post('/messages', [app\relay\controller\RelayController::class, 'messages']);
})->middleware([
    app\middleware\ApiAuth::class,
    app\middleware\RateLimit::class,
    app\middleware\BlockedWordFilter::class,
]);

// ============================================
// 管理端页面路由 - 无需认证
// ============================================
Route::get('/admin', function () { return redirect('/admin/login'); });
Route::get('/admin/login', [app\controller\PageController::class, 'adminLogin']);

// 管理端页面路由 - 需要认证
Route::group('/admin', function () {
    Route::get('/dashboard', [app\controller\PageController::class, 'adminDashboard']);
    Route::get('/channels', [app\controller\PageController::class, 'adminChannels']);
    Route::get('/models', [app\controller\PageController::class, 'adminModels']);
    Route::get('/pricing', [app\controller\PageController::class, 'adminPricing']);
    Route::get('/providers', [app\controller\PageController::class, 'adminProviders']);
    Route::get('/redemptions', [app\controller\PageController::class, 'adminRedemptions']);
    Route::get('/users', [app\controller\PageController::class, 'adminUsers']);
    Route::get('/logs', [app\controller\PageController::class, 'adminLogs']);
    Route::get('/groups', [app\controller\PageController::class, 'adminGroups']);
    Route::get('/announcements', [app\controller\PageController::class, 'adminAnnouncements']);
    Route::get('/model-categories', [app\controller\PageController::class, 'adminModelCategories']);
    Route::get('/settings', [app\controller\PageController::class, 'adminSettings']);
    Route::get('/tickets', [app\controller\PageController::class, 'adminTickets']);
})->middleware([
    app\middleware\AdminAuth::class,
]);

// ============================================
// 用户端页面路由 - 无需认证
// ============================================
Route::get('/user', function () { return redirect('/user/login'); });
Route::get('/user/login', [app\controller\PageController::class, 'userLogin']);
Route::get('/user/register', [app\controller\PageController::class, 'userRegister']);

// 用户端页面路由 - 需要认证
Route::group('/user', function () {
    Route::get('/dashboard', [app\controller\PageController::class, 'userDashboard']);
    Route::get('/playground', [app\controller\PageController::class, 'userPlayground']);
    Route::get('/tokens', [app\controller\PageController::class, 'userTokens']);
    Route::get('/logs', [app\controller\PageController::class, 'userLogs']);
    Route::get('/wallet', [app\controller\PageController::class, 'userWallet']);
    Route::get('/profile', [app\controller\PageController::class, 'userProfile']);
    Route::get('/tickets', [app\controller\PageController::class, 'userTickets']);
})->middleware([
    app\middleware\UserAuth::class,
]);

// ============================================
// 前台公开页面路由 - 无需认证
// ============================================
Route::get('/', [app\controller\PageController::class, 'home']);
Route::get('/pricing', [app\controller\PageController::class, 'pricing']);

// 验证码（公开，不需要登录）
Route::get('/api/captcha', [app\controller\user\AuthController::class, 'captcha']);

// 支付回调（公开，不需要登录，支付平台直接回调）
Route::get('/api/user/wallet/pay/notify', [app\controller\user\WalletController::class, 'payNotify']);
Route::get('/api/user/wallet/pay/return', [app\controller\user\WalletController::class, 'payReturn']);

// ============================================
// 公开API路由（无需登录）
// ============================================
Route::get('/api/public/providers', [app\controller\PublicController::class, 'providerList']);
Route::get('/api/public/models', [app\controller\PublicController::class, 'models']);
Route::get('/api/public/models/providers', [app\controller\PublicController::class, 'providers']);
Route::get('/api/public/models/tags', [app\controller\PublicController::class, 'tags']);
Route::get('/api/public/models/endpoints', [app\controller\PublicController::class, 'endpoints']);
Route::get('/api/public/models/{id:\d+}', [app\controller\PublicController::class, 'modelDetail']);
Route::get('/api/public/settings', [app\controller\PublicController::class, 'settings']);
Route::get('/api/public/announcements', [app\controller\PublicController::class, 'announcements']);
Route::get('/api/categories', [app\controller\PublicController::class, 'categories']);
Route::get('/api/categories/{id:\d+}', [app\controller\PublicController::class, 'categoryDetail']);
