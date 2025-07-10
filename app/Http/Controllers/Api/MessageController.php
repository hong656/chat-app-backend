<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Chat;
use App\Models\ChatMember;
use App\Models\MessageStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Events\MessageSent;

class MessageController extends Controller
{
    // Send a message
        public function store(Request $request): JsonResponse
    {
        $request->validate([
            'chat_id' => 'required|exists:chats,chat_id',
            'message_type' => 'required|string|in:text,image,video,audio,document,location',
            'text' => 'nullable|string',
            'replied_to_message_id' => 'nullable|exists:messages,message_id'
        ]);

        $senderId = Auth::user()->user_id; // Get the user_id from the authenticated user

        $isMember = ChatMember::where('chat_id', $request->chat_id)
            ->where('user_id', $senderId)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a member of this chat'
            ], 403);  // This 403 is different from the broadcasting 403!
        }

        DB::beginTransaction();
        try {
            $message = Message::create([
                'chat_id' => $request->chat_id,
                'sender_id' => $senderId,
                'message_type' => $request->message_type,
                'text' => $request->text,
                'replied_to_message_id' => $request->replied_to_message_id
            ]);

            $chatMembers = ChatMember::where('chat_id', $request->chat_id)
                ->pluck('user_id');

            foreach ($chatMembers as $memberId) {
                MessageStatus::create([
                    'message_id' => $message->message_id,
                    'user_id' => $memberId,
                    'status' => $memberId == $senderId ? 'read' : 'sent'
                ]);
            }

            DB::commit();

            $message->load(['sender:user_id,name', 'repliedTo:message_id,text,sender_id']);

            // Broadcast the event.  Don't use toOthers() here.
            broadcast(new MessageSent($message));

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => $message
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Message creation failed: ' . $e->getMessage(), [
                'exception' => $e,
                'request_data' => $request->all(),
                'sender_id' => $senderId
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }
    }

    // Get messages for a chat
        public function index(Request $request, $chatId): JsonResponse
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100'
        ]);

        $userId = Auth::user()->user_id;

        // Check if user is member of the chat
        $isMember = ChatMember::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a member of this chat'
            ], 403);
        }

        $page = $request->get('page', 1);
        $limit = $request->get('limit', 50);
        $offset = ($page - 1) * $limit;

        // Get all messages for the chat
        $messages = Message::where('chat_id', $chatId)
            ->with([
                'sender:user_id,name',
                'repliedTo:message_id,text,sender_id',
            ])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $filteredMessages = $messages->filter(function ($message) use ($userId) {
            $latestStatus = MessageStatus::where('message_id', $message->message_id)
                ->where('user_id', $userId)
                ->orderByDesc('updated_at')
                ->first();

            return !$latestStatus || (!in_array($latestStatus->status, ['deleted', 'delete_everyone']));
        });

        foreach ($filteredMessages as $message) {
            $latestStatus = MessageStatus::where('message_id', $message->message_id)
                ->where('user_id', $userId)
                ->orderByDesc('updated_at')
                ->first();

            if (
                !$latestStatus ||
                (
                    $latestStatus->status !== 'read' &&
                    !in_array($latestStatus->status, ['deleted', 'delete_everyone'])
                )
            ) {
                MessageStatus::create([
                    'message_id' => $message->message_id,
                    'user_id' => $userId,
                    'status' => 'read',
                ]);
            }
        }

        $filteredMessages->each(function ($message) use ($userId) {
            $message->is_you = $message->sender->user_id === $userId;

            $latestStatuses = MessageStatus::select('message_id', 'user_id', 'status', 'updated_at')
                ->where('message_id', $message->message_id)
                ->whereNotIn('status', ['delete_everyone'])
                ->orderBy('user_id')
                ->orderByDesc('updated_at')
                ->get()
                ->unique('user_id')
                ->values();
            $message->latest_statuses = $latestStatuses;
        });

        return response()->json([
            'success' => true,
            'data' => $filteredMessages->reverse()->values()
        ]);
    }

    // Delete message for user
    public function destroy(Request $request, $messageId): JsonResponse
    {
        $request->validate([
            'delete_for_everyone' => 'nullable|boolean'
        ]);

        $userId = Auth::user()->user_id;

        $message = Message::find($messageId);

        if (!$message) {
            return response()->json([
                'success' => false,
                'message' => 'Message not found'
            ], 404);
        }

        $isMember = ChatMember::where('chat_id', $message->chat_id)
            ->where('user_id', $userId)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a member of this chat'
            ], 403);
        }

        if ($request->delete_for_everyone && $message->sender_id == $userId) {
            // Soft delete for everyone - insert new status only if not already deleted
            $chatMembers = ChatMember::where('chat_id', $message->chat_id)
                ->pluck('user_id');

            foreach ($chatMembers as $memberId) {
                $latestStatus = MessageStatus::where('message_id', $messageId)
                    ->where('user_id', $memberId)
                    ->orderByDesc('updated_at')
                    ->first();

                // Skip if already deleted
                if ($latestStatus && in_array($latestStatus->status, ['deleted', 'delete_everyone'])) {
                    continue;
                }

                MessageStatus::create([
                    'message_id' => $messageId,
                    'user_id' => $memberId,
                    'status' => 'delete_everyone',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Message deleted for everyone'
            ]);
        } else {
            // Soft delete for current user only
            $latestStatus = MessageStatus::where('message_id', $messageId)
                ->where('user_id', $userId)
                ->orderByDesc('updated_at')
                ->first();

            // Skip if already deleted
            if (!$latestStatus || !in_array($latestStatus->status, ['deleted', 'delete_everyone'])) {
                MessageStatus::create([
                    'message_id' => $messageId,
                    'user_id' => $userId,
                    'status' => 'deleted',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Message deleted for you'
            ]);
        }
    }



    // Get unread message count for user
    // public function unreadCount(Request $request): JsonResponse
    // {
    //     $userId = Auth::user()->user_id;

    //     $unreadCount = MessageStatus::where('user_id', $userId)
    //         ->where('is_read', false)
    //         ->where('is_deleted', false)
    //         ->count();

    //     return response()->json([
    //         'success' => true,
    //         'data' => ['unread_count' => $unreadCount]
    //     ]);
    // }
}
