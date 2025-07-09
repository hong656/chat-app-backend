<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageStatus extends Model
{
    protected $table = 'message_status';
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'user_id',
        'status'
    ];

    protected $casts = [
        'updated_at' => 'datetime'
    ];

    // Constants for status values
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_READ = 'read';
    const STATUS_DELETED = 'deleted';

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id', 'message_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    // Scope for read messages
    public function scopeRead($query)
    {
        return $query->where('status', self::STATUS_READ);
    }

    // Scope for unread messages
    public function scopeUnread($query)
    {
        return $query->where('status', '!=', self::STATUS_READ);
    }

    // Scope for delivered messages
    public function scopeDelivered($query)
    {
        return $query->where('status', self::STATUS_DELIVERED);
    }
}
