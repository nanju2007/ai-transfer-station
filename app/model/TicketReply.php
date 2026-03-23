<?php

namespace app\model;

use support\Model;

class TicketReply extends Model
{
    const UPDATED_AT = null;

    protected $table = 'ticket_replies';

    protected $fillable = [
        'ticket_id', 'user_id', 'is_admin', 'content',
    ];

    protected $casts = [
        'ticket_id' => 'integer',
        'user_id' => 'integer',
        'is_admin' => 'integer',
        'created_at' => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
