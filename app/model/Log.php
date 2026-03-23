<?php

namespace app\model;

use support\Model;

class Log extends Model
{
    protected $table = 'logs';

    public $timestamps = false;

    const UPDATED_AT = null;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id', 'token_id', 'channel_id', 'type', 'model_name',
        'prompt_tokens', 'completion_tokens',
        'cached_tokens', 'cache_creation_tokens', 'cache_read_tokens',
        'cost', 'content',
        'token_name', 'username', 'channel_name', 'use_time',
        'duration', 'ttft',
        'is_stream', 'ip', 'request_id', 'other',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'token_id' => 'integer',
        'channel_id' => 'integer',
        'type' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'cached_tokens' => 'integer',
        'cache_creation_tokens' => 'integer',
        'cache_read_tokens' => 'integer',
        'cost' => 'float',
        'use_time' => 'integer',
        'duration' => 'integer',
        'ttft' => 'integer',
        'is_stream' => 'integer',
        'created_at' => 'datetime',
    ];

    // 日志类型常量
    const TYPE_RECHARGE = 1;
    const TYPE_CONSUME = 2;
    const TYPE_MANAGE = 3;
    const TYPE_SYSTEM = 4;
    const TYPE_ERROR = 5;
    const TYPE_REFUND = 6;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function token()
    {
        return $this->belongsTo(Token::class, 'token_id');
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }
}
