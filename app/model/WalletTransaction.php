<?php

namespace app\model;

use support\Model;

class WalletTransaction extends Model
{
    protected $table = 'wallet_transactions';

    public $timestamps = false;

    const UPDATED_AT = null;
    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id', 'wallet_id', 'type', 'amount',
        'balance_before', 'balance_after', 'description',
        'related_id', 'related_type',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'wallet_id' => 'integer',
        'type' => 'integer',
        'amount' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'related_id' => 'integer',
        'created_at' => 'datetime',
    ];

    // 交易类型常量
    const TYPE_RECHARGE = 1;
    const TYPE_CONSUME = 2;
    const TYPE_REFUND = 3;
    const TYPE_REDEEM = 4;
    const TYPE_ADJUST = 5;

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class, 'wallet_id');
    }
}
