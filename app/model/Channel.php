<?php

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Channel extends Model
{
    use SoftDeletes;

    protected $table = 'channels';

    protected $fillable = [
        'name', 'type', 'key', 'base_url', 'status', 'weight', 'priority',
        'pass_through', 'models', 'model_mapping', 'test_model', 'test_time',
        'response_time', 'balance', 'balance_updated_at', 'used_quota',
        'max_input_tokens', 'auto_ban', 'remark', 'rate_limit', 'rate_limit_window',
    ];

    protected $casts = [
        'type' => 'integer',
        'status' => 'integer',
        'weight' => 'integer',
        'priority' => 'integer',
        'pass_through' => 'integer',
        'response_time' => 'integer',
        'balance' => 'decimal:4',
        'used_quota' => 'integer',
        'max_input_tokens' => 'integer',
        'auto_ban' => 'integer',
        'rate_limit' => 'integer',
        'rate_limit_window' => 'integer',
        'test_time' => 'datetime',
        'balance_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function channelModels()
    {
        return $this->hasMany(ChannelModel::class, 'channel_id');
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'channel_id');
    }

    public function categoryChannels()
    {
        return $this->hasMany(CategoryChannel::class, 'channel_id');
    }
}
