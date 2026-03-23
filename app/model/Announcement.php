<?php
namespace app\model;
use support\Model;

class Announcement extends Model
{
    protected $table = 'announcements';
    protected $fillable = ['title', 'content', 'sort', 'status', 'popup'];
}
