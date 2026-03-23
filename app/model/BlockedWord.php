<?php

namespace app\model;

use support\Model;

class BlockedWord extends Model
{
    protected $table = 'blocked_words';

    protected $fillable = [
        'word', 'type', 'action', 'replacement', 'status',
    ];

    protected $casts = [
        'type' => 'integer',
        'action' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // 类型常量
    const TYPE_INPUT = 1;
    const TYPE_OUTPUT = 2;
    const TYPE_BOTH = 3;

    // 动作常量
    const ACTION_REPLACE = 1;
    const ACTION_REJECT = 2;
    const ACTION_LOG = 3;
}
