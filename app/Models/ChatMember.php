<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMember extends Model
{
    protected $table = 'chat_members';
    public $timestamps = false;

    protected $fillable = [
        'chat_id',
        'user_id',
        'is_admin',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'is_admin' => 'boolean',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id', 'chat_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
