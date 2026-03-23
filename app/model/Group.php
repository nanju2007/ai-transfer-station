<?php

namespace app\model;

use support\Model;

class Group extends Model
{
    protected $table = 'groups';
    protected $fillable = ['name', 'display_name', 'ratio', 'description', 'sort', 'status'];

    protected $casts = [
        'ratio' => 'float',
    ];
}
