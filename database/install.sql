-- MySQL 5.7+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 1. users - 用户表
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '用户名',
    `password` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '密码哈希',
    `display_name` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '显示名称',
    `email` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '邮箱',
    `role` TINYINT NOT NULL DEFAULT 1 COMMENT '角色：1=普通用户 10=管理员 100=超级管理员',
    `group_name` VARCHAR(50) DEFAULT 'default' COMMENT '所属分组',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 2=禁用',
    `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '余额(元)',
    `quota` BIGINT NOT NULL DEFAULT 0 COMMENT '剩余额度（最小单位：厘）',
    `used_quota` BIGINT NOT NULL DEFAULT 0 COMMENT '已用额度',
    `request_count` INT NOT NULL DEFAULT 0 COMMENT '请求总次数',
    `access_token` CHAR(32) NULL DEFAULT NULL COMMENT '管理访问令牌',
    `aff_code` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '邀请码',
    `aff_count` INT NOT NULL DEFAULT 0 COMMENT '邀请人数',
    `inviter_id` INT NOT NULL DEFAULT 0 COMMENT '邀请人ID',
    `invite_code` VARCHAR(20) DEFAULT NULL COMMENT '邀请码（用户端）',
    `invited_by` INT UNSIGNED DEFAULT NULL COMMENT '邀请人用户ID',
    `last_login_at` TIMESTAMP NULL DEFAULT NULL COMMENT '最后登录时间',
    `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_access_token` (`access_token`),
    UNIQUE KEY `uk_aff_code` (`aff_code`),
    KEY `idx_invite_code` (`invite_code`),
    KEY `idx_email` (`email`),
    KEY `idx_inviter_id` (`inviter_id`),
    KEY `idx_invited_by` (`invited_by`),
    KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表';

-- ----------------------------
-- 2. channels - 渠道表
-- ----------------------------
DROP TABLE IF EXISTS `channels`;
CREATE TABLE `channels` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '渠道名称',
    `type` TINYINT NOT NULL DEFAULT 1 COMMENT '渠道类型：1=OpenAI 2=Anthropic 3=Gemini 4=DeepSeek 5=Ollama 6=OpenRouter 99=自定义',
    `key` TEXT NULL COMMENT 'API密钥（支持多key，换行分隔）',
    `base_url` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '自定义API地址',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 2=禁用 3=自动禁用',
    `weight` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '权重',
    `priority` INT NOT NULL DEFAULT 0 COMMENT '优先级',
    `pass_through` TINYINT NOT NULL DEFAULT 0 COMMENT '请求体透传开关：0=关闭 1=开启',
    `models` TEXT NULL COMMENT '支持的模型列表（逗号分隔）',
    `model_mapping` TEXT NULL COMMENT '模型映射JSON',
    `test_model` VARCHAR(64) NULL DEFAULT NULL COMMENT '测试用模型名',
    `test_time` TIMESTAMP NULL DEFAULT NULL COMMENT '最后测试时间',
    `response_time` INT NOT NULL DEFAULT 0 COMMENT '最后测试响应时间（毫秒）',
    `balance` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT '上游余额（USD）',
    `balance_updated_at` TIMESTAMP NULL DEFAULT NULL COMMENT '余额更新时间',
    `used_quota` BIGINT NOT NULL DEFAULT 0 COMMENT '已消耗额度',
    `max_input_tokens` INT NOT NULL DEFAULT 0 COMMENT '最大输入token数，0=不限',
    `auto_ban` TINYINT NOT NULL DEFAULT 1 COMMENT '自动禁用：0=关闭 1=开启',
    `rate_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每分钟最大请求数，0=不限制',
    `rate_limit_window` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT '限流时间窗口（秒）',
    `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间',
    PRIMARY KEY (`id`),
    KEY `idx_name` (`name`),
    KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='渠道表';

-- ----------------------------
-- 3. channel_models - 渠道模型关联表
-- ----------------------------
DROP TABLE IF EXISTS `channel_models`;
CREATE TABLE `channel_models` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '渠道ID',
    `model_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '模型ID',
    `custom_model_name` VARCHAR(128) NULL DEFAULT NULL COMMENT '在该渠道中的自定义模型名',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 2=禁用',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_channel_model` (`channel_id`, `model_id`),
    KEY `idx_channel_id` (`channel_id`),
    KEY `idx_model_id` (`model_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='渠道模型关联表';

-- ----------------------------
-- 4. models - 模型表
-- ----------------------------
DROP TABLE IF EXISTS `models`;
CREATE TABLE `models` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `model_name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '模型标识名',
    `display_name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '模型显示名',
    `vendor` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '供应商：openai / anthropic',
    `type` TINYINT NOT NULL DEFAULT 1 COMMENT '模型类型：1=文本 2=图像 3=音频 4=嵌入 5=审核',
    `provider` VARCHAR(50) DEFAULT '' COMMENT '供应商（如 OpenAI, Anthropic, Google, Meta 等，纯显示用）',
    `description` TEXT DEFAULT NULL COMMENT '模型描述',
    `tags` VARCHAR(500) DEFAULT '[]' COMMENT '标签（JSON数组，如["推荐","高性能"]）',
    `endpoints` VARCHAR(500) DEFAULT '[]' COMMENT 'API端点（JSON数组，如[{"path":"/v1/chat/completions","method":"POST"}]）',
    `max_context` INT NOT NULL DEFAULT 0 COMMENT '最大上下文长度',
    `max_output` INT NOT NULL DEFAULT 0 COMMENT '最大输出长度',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 2=禁用',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序权重',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_model_name` (`model_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='模型表';

-- ----------------------------
-- 5. model_pricing - 模型计费表
-- ----------------------------
DROP TABLE IF EXISTS `model_pricing`;
CREATE TABLE `model_pricing` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `model_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '模型ID',
    `billing_type` TINYINT NOT NULL DEFAULT 1 COMMENT '计费类型：1=按量（token）2=按次',
    `input_price` DECIMAL(12,6) NOT NULL DEFAULT 0.000000 COMMENT '输入价格（元/百万token）',
    `output_price` DECIMAL(12,6) NOT NULL DEFAULT 0.000000 COMMENT '输出价格（元/百万token）',
    `per_request_price` DECIMAL(12,6) NOT NULL DEFAULT 0.000000 COMMENT '每次请求价格（元/次）',
    `min_charge` DECIMAL(12,6) NOT NULL DEFAULT 0.000000 COMMENT '最小消费金额（元）',
    `cache_input_ratio` DECIMAL(5,4) NOT NULL DEFAULT 1.0000 COMMENT '缓存输入价格比例',
    `cache_enabled` TINYINT(1) DEFAULT 0 COMMENT '是否启用缓存独立计费',
    `cache_creation_price` DECIMAL(10,4) DEFAULT 0.0000 COMMENT '缓存创建价格（元/百万token）',
    `cache_read_price` DECIMAL(10,4) DEFAULT 0.0000 COMMENT '缓存读取价格（元/百万token）',
    `currency` VARCHAR(8) NOT NULL DEFAULT 'CNY' COMMENT '货币单位',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 2=禁用',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_model_id` (`model_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='模型计费表';

-- ----------------------------
-- 6. tokens - API令牌表
-- ----------------------------
DROP TABLE IF EXISTS `tokens`;
CREATE TABLE `tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属用户ID',
    `name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '令牌名称',
    `key` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '令牌密钥，格式 sk-xxxx',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 2=禁用 3=已过期',
    `max_budget` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT '最大消费额度（元），0表示无限制',
    `used_amount` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT '已消费金额（元）',
    `model_limits_enabled` TINYINT NOT NULL DEFAULT 0 COMMENT '是否启用模型限制',
    `model_limits` TEXT NULL COMMENT '允许使用的模型列表JSON',
    `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '绑定的模型分类ID，0=不限制（兼容旧逻辑）',
    `allow_ips` TEXT NULL COMMENT 'IP白名单，换行分隔',
    `expired_at` TIMESTAMP NULL DEFAULT NULL COMMENT '过期时间',
    `last_used_at` TIMESTAMP NULL DEFAULT NULL COMMENT '最后使用时间',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`key`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_name` (`name`),
    KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API令牌表';

-- ----------------------------
-- 7. logs - 使用日志表
-- ----------------------------
DROP TABLE IF EXISTS `logs`;
CREATE TABLE `logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
    `token_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '令牌ID',
    `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '渠道ID',
    `type` TINYINT NOT NULL DEFAULT 2 COMMENT '日志类型：1=充值 2=消费 3=管理 4=系统 5=错误 6=退款',
    `model_name` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '模型名称',
    `prompt_tokens` INT NOT NULL DEFAULT 0 COMMENT '输入token数',
    `completion_tokens` INT NOT NULL DEFAULT 0 COMMENT '输出token数',
    `cached_tokens` INT(11) DEFAULT 0 COMMENT '缓存命中token数',
    `cache_creation_tokens` INT(11) DEFAULT 0 COMMENT '缓存创建token数',
    `cache_read_tokens` INT(11) DEFAULT 0 COMMENT '缓存读取token数',
    `cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000 COMMENT '消费金额（元）',
    `content` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '日志内容描述',
    `token_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '令牌名称（冗余）',
    `username` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '用户名（冗余）',
    `channel_name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '渠道名称（冗余）',
    `use_time` INT NOT NULL DEFAULT 0 COMMENT '请求耗时（毫秒）',
    `duration` INT NOT NULL DEFAULT 0 COMMENT '总用时（毫秒）',
    `ttft` INT NOT NULL DEFAULT 0 COMMENT '首字时间（毫秒）',
    `is_stream` TINYINT NOT NULL DEFAULT 0 COMMENT '是否流式请求',
    `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '请求IP',
    `request_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '请求唯一ID',
    `other` TEXT NULL COMMENT '其他信息JSON',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`, `id`),
    KEY `idx_token_id` (`token_id`),
    KEY `idx_channel_id` (`channel_id`),
    KEY `idx_model_name` (`model_name`),
    KEY `idx_username_model` (`username`, `model_name`),
    KEY `idx_ip` (`ip`),
    KEY `idx_request_id` (`request_id`),
    KEY `idx_created_at` (`created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='使用日志表';

-- ----------------------------
-- 8. redemption_codes - 兑换码表
-- ----------------------------
DROP TABLE IF EXISTS `redemption_codes`;
CREATE TABLE `redemption_codes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '兑换码批次名称',
    `key` CHAR(32) NOT NULL DEFAULT '' COMMENT '兑换码密钥',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=未使用 2=已禁用 3=已使用',
    `quota` BIGINT NOT NULL DEFAULT 0 COMMENT '兑换额度',
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建者ID',
    `used_user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '使用者ID',
    `redeemed_at` TIMESTAMP NULL DEFAULT NULL COMMENT '兑换时间',
    `expired_at` TIMESTAMP NULL DEFAULT NULL COMMENT '过期时间',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`key`),
    KEY `idx_name` (`name`),
    KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='兑换码表';

-- ----------------------------
-- 9. wallets - 钱包表
-- ----------------------------
DROP TABLE IF EXISTS `wallets`;
CREATE TABLE `wallets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
    `balance` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT '可用余额（元）',
    `frozen_balance` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT '冻结余额（元）',
    `total_recharge` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT '累计充值（元）',
    `total_consumption` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT '累计消费（元）',
    `used_amount` DECIMAL(10,4) NOT NULL DEFAULT 0.0000 COMMENT '累计消费金额（元）',
    `currency` VARCHAR(8) NOT NULL DEFAULT 'CNY' COMMENT '货币单位',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='钱包表';

-- ----------------------------
-- 10. wallet_transactions - 钱包交易记录表
-- ----------------------------
DROP TABLE IF EXISTS `wallet_transactions`;
CREATE TABLE `wallet_transactions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
    `wallet_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '钱包ID',
    `type` TINYINT NOT NULL DEFAULT 1 COMMENT '交易类型：1=充值 2=消费 3=退款 4=兑换 5=系统调整',
    `amount` DECIMAL(12,4) NOT NULL DEFAULT 0.0000 COMMENT '交易金额',
    `balance_before` DECIMAL(12,4) NOT NULL DEFAULT 0.0000 COMMENT '交易前余额',
    `balance_after` DECIMAL(12,4) NOT NULL DEFAULT 0.0000 COMMENT '交易后余额',
    `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '交易描述',
    `related_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联ID',
    `related_type` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '关联类型',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_wallet_id` (`wallet_id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='钱包交易记录表';

-- ----------------------------
-- 11. options - 系统设置表
-- ----------------------------
DROP TABLE IF EXISTS `options`;
CREATE TABLE `options` (
    `key` VARCHAR(128) NOT NULL COMMENT '设置键名',
    `value` TEXT NULL COMMENT '设置值',
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统设置表';

-- ----------------------------
-- 12. blocked_words - 屏蔽词表
-- ----------------------------
DROP TABLE IF EXISTS `blocked_words`;
CREATE TABLE `blocked_words` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `word` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '屏蔽词内容',
    `type` TINYINT NOT NULL DEFAULT 3 COMMENT '类型：1=用户输入屏蔽 2=上游返回屏蔽 3=双向屏蔽',
    `action` TINYINT NOT NULL DEFAULT 1 COMMENT '触发动作：1=替换为*** 2=拒绝请求 3=记录日志',
    `replacement` VARCHAR(255) NOT NULL DEFAULT '***' COMMENT '替换内容',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 2=禁用',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_word` (`word`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='屏蔽词表';

-- ----------------------------
-- 13. groups - 用户分组表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `groups` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL DEFAULT '' COMMENT '分组标识（如 default, vip, svip）',
    `display_name` varchar(100) NOT NULL DEFAULT '' COMMENT '显示名称（如 默认分组, VIP, SVIP）',
    `ratio` decimal(10,4) NOT NULL DEFAULT 1.0000 COMMENT '价格倍率',
    `description` varchar(500) DEFAULT '' COMMENT '分组描述',
    `sort` int(11) DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：0禁用 1启用',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户分组表';


-- ----------------------------
-- 14. providers - 厂商/供应商表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `providers` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL DEFAULT '' COMMENT '厂商标识（如 openai, anthropic）',
    `display_name` varchar(100) NOT NULL DEFAULT '' COMMENT '显示名称（如 OpenAI, Anthropic）',
    `icon` varchar(500) DEFAULT '' COMMENT '图标URL或SVG',
    `color` varchar(20) DEFAULT '#0052d9' COMMENT '品牌色',
    `sort` int(11) DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：0禁用 1启用',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='厂商/供应商表';

-- ----------------------------
-- 15. checkins - 签到记录表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `checkins` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '用户ID',
    `amount` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT '签到获得金额（元）',
    `checkin_date` date DEFAULT NULL COMMENT '签到日期',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_date` (`user_id`, `checkin_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='签到记录表';

-- ----------------------------
-- 16. announcements - 系统公告表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `title` varchar(200) NOT NULL DEFAULT '' COMMENT '公告标题',
    `content` text DEFAULT NULL COMMENT '公告内容',
    `sort` int(11) DEFAULT 0 COMMENT '排序（越大越靠前）',
    `status` tinyint(1) DEFAULT 1 COMMENT '状态：0隐藏 1显示',
    `popup` tinyint(1) DEFAULT 0 COMMENT '全平台弹出：0关闭 1开启（每天弹出一次）',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统公告表';
-- ----------------------------
-- 17. model_categories - 模型分类表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `model_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '分类标识（如 gpt, claude, general-chat）',
    `icon` VARCHAR(500) DEFAULT '' COMMENT '图标URL或SVG',
    `description` VARCHAR(500) DEFAULT '' COMMENT '分类描述',
    `sort_order` INT NOT NULL DEFAULT 0 COMMENT '排序（越大越靠前）',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 0=禁用',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='模型分类表';

-- ----------------------------
-- 18. category_channels - 分类渠道关联表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `category_channels` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
    `channel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '渠道ID',
    `model_name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '模型名称',
    `model_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '模型ID（该渠道在此分类下使用的模型）',
    `custom_price_enabled` TINYINT NOT NULL DEFAULT 0 COMMENT '是否启用自定义价格：0=跟随模型定价 1=自定义',
    `custom_input_price` DECIMAL(12,6) NOT NULL DEFAULT 0.000000 COMMENT '自定义输入价格（元/百万token）',
    `custom_output_price` DECIMAL(12,6) NOT NULL DEFAULT 0.000000 COMMENT '自定义输出价格（元/百万token）',
    `priority` INT NOT NULL DEFAULT 0 COMMENT '在该分类内的优先级',
    `weight` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '在该分类内的权重',
    `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态：1=启用 0=禁用',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_category_channel_model` (`category_id`, `channel_id`, `model_id`),
    KEY `idx_category_id` (`category_id`),
    KEY `idx_channel_id` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分类渠道关联表';

-- ----------------------------
-- 19. tickets - 工单表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `tickets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '提交用户ID',
    `title` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '工单标题',
    `content` TEXT DEFAULT NULL COMMENT '工单内容',
    `category` VARCHAR(50) NOT NULL DEFAULT 'general' COMMENT '工单分类：general=一般问题 billing=计费问题 technical=技术问题 suggestion=建议反馈',
    `status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT '状态：pending=待处理 processing=处理中 resolved=已解决 closed=已关闭',
    `priority` VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT '优先级：low=低 normal=普通 high=紧急 urgent=非常紧急',
    `assigned_to` INT UNSIGNED DEFAULT NULL COMMENT '指派管理员ID',
    `closed_at` TIMESTAMP NULL DEFAULT NULL COMMENT '关闭时间',
    `last_reply_at` TIMESTAMP NULL DEFAULT NULL COMMENT '最后回复时间',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL COMMENT '软删除时间',
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_category` (`category`),
    KEY `idx_assigned_to` (`assigned_to`),
    KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单表';

-- ----------------------------
-- 20. ticket_replies - 工单回复表
-- ----------------------------
CREATE TABLE IF NOT EXISTS `ticket_replies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ticket_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '工单ID',
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '回复者ID',
    `content` TEXT DEFAULT NULL COMMENT '回复内容',
    `is_admin` TINYINT NOT NULL DEFAULT 0 COMMENT '是否管理员回复：0=用户 1=管理员',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='工单回复表';
-- ----------------------------
-- 插入默认厂商数据
-- ----------------------------
INSERT INTO `providers` (`name`, `display_name`, `color`, `sort`) VALUES
('openai', 'OpenAI', '#10a37f', 1),
('anthropic', 'Anthropic', '#d97706', 2),
('google', 'Google', '#4285f4', 3),
('meta', 'Meta', '#0668e1', 4),
('deepseek', 'DeepSeek', '#0066ff', 5),
('ollama', 'Ollama', '#333333', 6),
('openrouter', 'OpenRouter', '#6467f2', 7),
('moonshot', 'Moonshot', '#6366f1', 8),
('minimax', 'MiniMax', '#ff6b35', 9),
('zhipu', '智谱AI', '#3b82f6', 10),
('xai', 'xAI', '#000000', 11);
-- ----------------------------
-- 插入默认分组
-- ----------------------------
INSERT INTO `groups` (`name`, `display_name`, `ratio`, `description`, `sort`) VALUES
('default', '默认分组', 1.0000, '系统默认分组，标准价格', 0);

-- ----------------------------
-- 插入默认系统设置
-- ----------------------------
INSERT INTO `options` (`key`, `value`) VALUES
('site_name', 'AI中转站'),
('site_logo', ''),
('site_footer', ''),
('notice', '欢迎使用AI中转站'),
('smtp_server', ''),
('smtp_port', '465'),
('smtp_account', ''),
('smtp_token', ''),
('smtp_from', ''),
('smtp_ssl_enabled', '1'),
('pay_address', ''),
('pay_id', ''),
('pay_key', ''),
('register_enabled', '1'),
('email_verification_enabled', '0'),
('rate_limit_global', '600'),
('rate_limit_per_user', '120'),
('log_consume_enabled', '1'),
('auto_disable_channel_enabled', '1'),
('channel_disable_threshold', '5'),
('blocked_word_upstream_enabled', '0'),
('blocked_word_input_enabled', '0'),
('cache_ttl', '300'),
('worker_num', '4');

-- 额度设置
INSERT INTO `options` (`key`, `value`) VALUES ('new_user_initial_balance', '0') ON DUPLICATE KEY UPDATE `key`=`key`;
INSERT INTO `options` (`key`, `value`) VALUES ('pre_deduct_amount', '0') ON DUPLICATE KEY UPDATE `key`=`key`;
INSERT INTO `options` (`key`, `value`) VALUES ('invite_reward_amount', '0') ON DUPLICATE KEY UPDATE `key`=`key`;

-- 签到设置
INSERT INTO `options` (`key`, `value`) VALUES ('checkin_enabled', '0') ON DUPLICATE KEY UPDATE `key`=`key`;
INSERT INTO `options` (`key`, `value`) VALUES ('checkin_min_amount', '0.01') ON DUPLICATE KEY UPDATE `key`=`key`;
INSERT INTO `options` (`key`, `value`) VALUES ('checkin_max_amount', '0.1') ON DUPLICATE KEY UPDATE `key`=`key`;

-- 系统公告
INSERT INTO `options` (`key`, `value`) VALUES ('system_announcement', '') ON DUPLICATE KEY UPDATE `key`=`key`;

-- 充值链接
INSERT INTO `options` (`key`, `value`) VALUES ('recharge_url', '') ON DUPLICATE KEY UPDATE `key`=`key`;

-- 验证码设置
INSERT INTO `options` (`key`, `value`) VALUES ('captcha_length', '4') ON DUPLICATE KEY UPDATE `key`=`key`;
INSERT INTO `options` (`key`, `value`) VALUES ('captcha_chars', 'abcdefghjkmnpqrstuvwxyz23456789') ON DUPLICATE KEY UPDATE `key`=`key`;

-- 注册设置
INSERT INTO `options` (`key`, `value`) VALUES ('register_email_verify', '0') ON DUPLICATE KEY UPDATE `key`=`key`;

-- 协议设置
INSERT INTO `options` (`key`, `value`) VALUES ('privacy_policy', '') ON DUPLICATE KEY UPDATE `key`=`key`;
INSERT INTO `options` (`key`, `value`) VALUES ('terms_of_service', '') ON DUPLICATE KEY UPDATE `key`=`key`;

SET FOREIGN_KEY_CHECKS = 1;
