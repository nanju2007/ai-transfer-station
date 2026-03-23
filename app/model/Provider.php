<?php
namespace app\model;

use support\Model;

class Provider extends Model
{
    protected $table = 'providers';
    protected $fillable = ['name', 'display_name', 'icon', 'color', 'sort', 'status'];
}
