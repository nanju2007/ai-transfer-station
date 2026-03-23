<?php

namespace app\model;

use support\Model;

class ChannelModel extends Model
{
    protected $table = 'channel_models';

    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'channel_id', 'model_id', 'custom_model_name', 'status',
    ];

    protected $casts = [
        'channel_id' => 'integer',
        'model_id' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
    ];

    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function model()
    {
        return $this->belongsTo(Model_::class, 'model_id');
    }
}
