<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use App\Models\ChatMember;
use App\Models\Chat;

Broadcast::routes([
    'middleware' => ['auth:sanctum'],
]);

// Broadcast::channel('chat.{chat_id}', function ($user, $chat_id) {
//     Log::info('Broadcasting message', [
//         'message_id' => $user->id,
//         'chat_id' => $chat_id,
//         'sender_id' => $user->id,
//         'text' => 'hey',
//         'channel' => "chat.{$chat_id}",
//     ]);
//     return ChatMember::where('chat_id', $chat_id)
//         ->where('user_id', $user->id)
//         ->exists();
// });

Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    $chat = Chat::find($chatId);

    if ($chat && $chat->members->contains($user)) {
        return ['id' => $user->id, 'name' => $user->name];
    }

    return false;
});