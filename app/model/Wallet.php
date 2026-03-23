<?php

namespace app\model;

use support\Model;

class Wallet extends Model
{
    protected $table = 'wallets';

    protected $fillable = [
        'user_id', 'balance', 'frozen_balance',
        'total_recharge', 'total_consumption', 'currency',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'balance' => 'decimal:4',
        'frozen_balance' => 'decimal:4',
        'total_recharge' => 'decimal:4',
        'total_consumption' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id');
    }
}
