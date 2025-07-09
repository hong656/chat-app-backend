<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    use HasFactory;

    protected $primaryKey = 'chat_id';
    public $timestamps = false;

    protected $fillable = [
        'is_group',
        'creator_id',
        'title',
    ];

    protected $casts = [
        'is_group' => 'boolean',
        'created_at' => 'datetime',
    ];

    // Chat has many members through chat_members pivot
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_members', 'chat_id', 'user_id')
            ->withPivot(['joined_at', 'is_admin']);
    }

    // Chat has many messages
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'chat_id', 'chat_id');
    }

    // Get chat admins
    public function admins(): BelongsToMany
    {
        return $this->members()->wherePivot('is_admin', true);
    }

    // Get latest message
    public function latestMessage()
    {
        return $this->hasOne(Message::class, 'chat_id', 'chat_id')->latest('created_at');
    }

    // Scope for group chats
    public function scopeGroups($query)
    {
        return $query->where('is_group', true);
    }

    // Scope for private chats
    public function scopePrivate($query)
    {
        return $query->where('is_group', false);
    }
}
