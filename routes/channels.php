<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use App\Models\ChatMember;

// Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
//     return (int) $user->id === (int) $id;
// });
Broadcast::routes();

Broadcast::channel('chat.{chat_id}', function ($user, $chat_id) {
    return ChatMember::where('chat_id', $chat_id)
        ->where('user_id', $user->id)
        ->exists();
});