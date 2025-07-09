<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;

    protected $primaryKey = 'message_id';
    public $timestamps = false;

    protected $fillable = [
        'chat_id',
        'sender_id',
        'message_type',
        'text',
        'replied_to_message_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Message belongs to a chat
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id', 'chat_id');
    }

    // Message belongs to a sender (user)
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id', 'user_id');
    }

    // Message can be a reply to another message
    public function repliedTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'replied_to_id', 'message_id');
    }

    // Message can have replies
    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'replied_to_id', 'message_id');
    }

    // Message has status for each recipient
    public function statuses(): HasMany
    {
        return $this->hasMany(MessageStatus::class, 'message_id', 'message_id');
    }

    // Scope for different message types
    public function scopeOfType($query, $type)
    {
        return $query->where('message_type', $type);
    }

    // Scope for text messages
    public function scopeText($query)
    {
        return $query->where('message_type', 'text');
    }

    // Scope for media messages
    public function scopeMedia($query)
    {
        return $query->whereIn('message_type', ['image', 'video', 'audio', 'document']);
    }
}
