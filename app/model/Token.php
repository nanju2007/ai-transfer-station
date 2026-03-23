<?php

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Token extends Model
{
    use SoftDeletes;

    protected $table = 'tokens';

    protected $fillable = [
        'user_id', 'name', 'key', 'status', 'max_budget', 'used_amount',
        'model_limits_enabled', 'model_limits', 'category_id',
        'allow_ips', 'expired_at', 'last_used_at',
    ];

    protected $hidden = ['key'];

    protected $casts = [
        'user_id' => 'integer',
        'category_id' => 'integer',
        'status' => 'integer',
        'max_budget' => 'float',
        'used_amount' => 'float',
        'model_limits_enabled' => 'integer',
        'expired_at' => 'datetime',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'token_id');
    }

    public function category()
    {
        return $this->belongsTo(ModelCategory::class, 'category_id');
    }

    public function isExpired(): bool
    {
        return $this->expired_at && $this->expired_at->isPast();
    }

    /**
     * 检查令牌是否还有可用额度
     * max_budget=0 表示无限制
     */
    public function hasBudget(): bool
    {
        if ($this->max_budget <= 0) {
            return true; // 无限制
        }
        return $this->used_amount < $this->max_budget;
    }
}
