<?php

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Model
{
    use SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'username', 'password', 'display_name', 'email', 'role', 'group_name', 'status',
        'quota', 'used_quota', 'request_count', 'access_token', 'aff_code',
        'aff_count', 'inviter_id', 'invite_code', 'invited_by', 'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'role' => 'integer',
        'status' => 'integer',
        'quota' => 'integer',
        'used_quota' => 'integer',
        'request_count' => 'integer',
        'aff_count' => 'integer',
        'inviter_id' => 'integer',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function tokens()
    {
        return $this->hasMany(Token::class, 'user_id');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class, 'user_id');
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'user_id');
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function isAdmin(): bool
    {
        return $this->role >= 10;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role >= 100;
    }
}
