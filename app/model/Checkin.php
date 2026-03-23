<?php

namespace app\model;

use support\Model;

class Checkin extends Model
{
    protected $table = 'checkins';

    protected $fillable = ['user_id', 'amount', 'checkin_date'];

    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $casts = [
        'user_id' => 'integer',
        'amount' => 'decimal:4',
    ];
}
