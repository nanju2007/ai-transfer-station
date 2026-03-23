<?php

namespace app\model;

use support\Model;

class Model_ extends Model
{
    protected $table = 'models';

    protected $fillable = [
        'model_name', 'display_name', 'vendor', 'type', 'provider',
        'description', 'tags', 'endpoints',
        'max_context', 'max_output', 'status', 'sort_order',
    ];

    protected $casts = [
        'type' => 'integer',
        'tags' => 'array',
        'endpoints' => 'array',
        'max_context' => 'integer',
        'max_output' => 'integer',
        'status' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function pricing()
    {
        return $this->hasOne(ModelPricing::class, 'model_id');
    }

    public function channelModels()
    {
        return $this->hasMany(ChannelModel::class, 'model_id');
    }
}
