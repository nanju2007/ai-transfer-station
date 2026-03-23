<?php

namespace app\model;

use support\Model;

class CategoryChannel extends Model
{
    protected $table = 'category_channels';

    protected $fillable = [
        'category_id', 'channel_id', 'model_name', 'priority', 'weight',
        'custom_input_price', 'custom_output_price', 'status',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'channel_id' => 'integer',
        'priority' => 'integer',
        'weight' => 'integer',
        'custom_input_price' => 'decimal:6',
        'custom_output_price' => 'decimal:6',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(ModelCategory::class, 'category_id');
    }

    public function channel()
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }
}
