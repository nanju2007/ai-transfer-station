<?php

namespace app\model;

use support\Model;

class ModelCategory extends Model
{
    protected $table = 'model_categories';

    protected $fillable = ['name', 'description', 'icon', 'sort_order', 'status'];

    protected $casts = [
        'sort_order' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function categoryChannels()
    {
        return $this->hasMany(CategoryChannel::class, 'category_id');
    }

    public function channels()
    {
        return $this->belongsToMany(Channel::class, 'category_channels', 'category_id', 'channel_id');
    }
}
