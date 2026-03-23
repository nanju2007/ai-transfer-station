<?php

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RedemptionCode extends Model
{
    use SoftDeletes;

    protected $table = 'redemption_codes';

    public $timestamps = false;

    const UPDATED_AT = null;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'name', 'key', 'status', 'quota', 'user_id',
        'used_user_id', 'redeemed_at', 'expired_at',
    ];

    protected $casts = [
        'status' => 'integer',
        'quota' => 'integer',
        'user_id' => 'integer',
        'used_user_id' => 'integer',
        'redeemed_at' => 'datetime',
        'expired_at' => 'datetime',
        'created_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // 状态常量
    const STATUS_UNUSED = 1;
    const STATUS_DISABLED = 2;
    const STATUS_USED = 3;

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function usedBy()
    {
        return $this->belongsTo(User::class, 'used_user_id');
    }
}
