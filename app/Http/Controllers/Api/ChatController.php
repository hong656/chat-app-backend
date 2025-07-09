<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\User;
use App\Models\ChatMember;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    // Get all chats for a user
    public function index(Request $request): JsonResponse
    {
        $userId = Auth::user()->user_id;

        $chats = Chat::whereHas('members', function ($query) use ($userId) {
            $query->where('chat_members.user_id', $userId);
        })->with([
            'members:user_id,name',
            'latestMessage' => function ($query) {
                $query->with('sender:user_id,name');
            }
        ])->get();

        $chats->each(function ($chat) use ($userId) {
            $chat->members->each(function ($member) use ($userId) {
                $member->is_you = $member->user_id === $userId;
            });
            
            if ($chat->latestMessage && $chat->latestMessage->sender) {
            $chat->latestMessage->sender->is_you = $chat->latestMessage->sender->user_id === $userId;
        }
        });

        return response()->json([
            'success' => true,
            'data' => $chats
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'is_group' => 'required|boolean',
            'title' => 'nullable|string|max:200',
            'members' => 'required|array|min:1',
            'members.*' => 'exists:users,user_id'
        ]);

        $creatorId = Auth::user()->user_id;
        $members = $request->members;
        // Always include the creator in the members array
        if (!in_array($creatorId, $members)) {
            $members[] = $creatorId;
        }

        // For private chats, ensure only 2 members
        if (!$request->is_group) {
            if (count($members) !== 2) {
                return response()->json([
                    'success' => false,
                    'message' => 'Private chat must have exactly 2 members'
                ], 400);
            }
        }

        // Check if private chat already exists between these users
        if (!$request->is_group) {
            $existingChat = Chat::where('is_group', false)
                ->whereHas('members', function ($query) use ($members) {
                    $query->where('chat_members.user_id', $members[0]);
                })
                ->whereHas('members', function ($query) use ($members) {
                    $query->where('chat_members.user_id', $members[1]);
                })
                ->first();

            if ($existingChat) {
                return response()->json([
                    'success' => false,
                    'message' => 'Private chat already exists between these users',
                    'data' => $existingChat
                ], 409);
            }
        }

        if (!$request->is_group) {
            $otherMemberId = collect($members)->first(function ($id) use ($creatorId) {
                return $id != $creatorId;
            });
            $otherUser = User::where('user_id', $otherMemberId)->first();
            $title = $otherUser ? $otherUser->name : null;
        }

        DB::beginTransaction();
        try {
            $chat = Chat::create([
                'is_group' => $request->is_group,
                'title' => $title,
                'creator_id' => $creatorId
            ]);

            // Add members to chat
            foreach ($members as $memberId) {
                ChatMember::create([
                    'chat_id' => $chat->chat_id,
                    'user_id' => $memberId,
                    'is_admin' => $memberId == $creatorId && $request->is_group
                ]);
            }

            DB::commit();

            $chat->load('members:user_id,name,display_name');

            return response()->json([
                'success' => true,
                'message' => 'Chat created successfully',
                'data' => $chat
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Chat creation failed: ' . $e->getMessage(), [
                'exception' => $e,
                'request_data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create chat'
            ], 500);
        }
    }

    // Get specific chat details
    public function show($chatId, Request $request): JsonResponse
    {
        $userId = Auth::user()->user_id;

        $chat = Chat::whereHas('members', function ($query) use ($userId) {
            $query->where('chat_members.user_id', $userId);
        })->with([
            'members:user_id,name,display_name',
            // 'messages' => function ($query) {
            //     $query->with(['sender:user_id,name,display_name', 'repliedTo:message_id,text,sender_id'])
            //         ->orderBy('created_at', 'desc')
            //         ->limit(50);
            // }
        ])->find($chatId);

        if (!$chat) {
            return response()->json([
                'success' => false,
                'message' => 'Chat not found or access denied'
            ], 404);
        }

        // Mark is_you for each member
        $chat->members->each(function ($member) use ($userId) {
            $member->is_you = $member->user_id === $userId;
        });

        // Build chat_name from other members
        $chat_name = $chat->members->filter(function ($member) use ($userId) {
            return $member->user_id !== $userId;
        })->map(function ($member) {
            return $member->display_name ?: $member->name;
        })->implode(', ');

        return response()->json([
            'success' => true,
            'data' => [
                'chat_id' => $chat->chat_id,
                'is_group' => $chat->is_group,
                'title' => $chat->title,
                'chat_name' => $chat_name,
                'creator_id' => $chat->creator_id,
                'created_at' => $chat->created_at,
                'members' => $chat->members,
            ]
        ]);
    }

    // Add member to chat (automatically converts private chat to group chat)
    public function addMember(Request $request, $chatId): JsonResponse
    {
        $request->validate([
            'new_member_id' => 'required|exists:users,user_id',
        ]);

        $userId = Auth::user()->user_id;

        // Find the chat (can be either private or group)
        $chat = Chat::where('chat_id', $chatId)->first();

        if (!$chat) {
            return response()->json([
                'success' => false,
                'message' => 'Chat not found'
            ], 404);
        }

        // Check if user is a member of the chat
        $isMember = ChatMember::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->exists();

        if (!$isMember) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a member of this chat'
            ], 403);
        }

        // Check if user is already a member
        $isAlreadyMember = ChatMember::where('chat_id', $chatId)
            ->where('user_id', $request->new_member_id)
            ->exists();

        if ($isAlreadyMember) {
            return response()->json([
                'success' => false,
                'message' => 'User is already a member'
            ], 409);
        }

        DB::beginTransaction();
        try {
            if (!$chat->is_group) {
                // Private chat: only 2 members
                $originalMembers = ChatMember::where('chat_id', $chatId)->pluck('user_id')->toArray();
                $newMembers = array_unique(array_merge($originalMembers, [$request->new_member_id]));

                if (count($newMembers) == 3) {
                    // Create a new group chat with these 3 members
                    $newChat = Chat::create([
                        'is_group' => true,
                        'title' => $chat->title ?: 'Group Chat',
                        'creator_id' => $userId
                    ]);

                    // Add members to new chat, set adder as admin
                    foreach ($newMembers as $memberId) {
                        ChatMember::create([
                            'chat_id' => $newChat->chat_id,
                            'user_id' => $memberId,
                            'is_admin' => $memberId == $userId
                        ]);
                    }

                    DB::commit();

                    $newChat->load('members:user_id,name,display_name');

                    return response()->json([
                        'success' => true,
                        'message' => 'New group chat created with added member',
                        'data' => $newChat
                    ], 201);
                } else {
                    // Should not happen for private chat, but just in case
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot add more than one member to a private chat at once.'
                    ], 400);
                }
            } else {
                // Group chat: just add the new member
                $isAdmin = ChatMember::where('chat_id', $chatId)
                    ->where('user_id', $userId)
                    ->where('is_admin', true)
                    ->exists();

                if ($isAdmin) {
                    ChatMember::create([
                        'chat_id' => $chatId,
                        'user_id' => $request->new_member_id,
                        'is_admin' => false
                    ]);

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Member added successfully',
                        'data' => [
                            'new_chat_type' => 'group'
                        ]
                    ]);
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Only admins can add members to group chats'
                    ], 403);
                }
            }
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Add member failed: ' . $e->getMessage(), [
                'exception' => $e,
                'chat_id' => $chatId,
                'new_member_id' => $request->new_member_id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to add member'
            ], 500);
        }
    }

    // Remove member from group chat
    public function removeMember(Request $request, $chatId, $memberId): JsonResponse
    {
        $userId = Auth::user()->user_id;

        $chat = Chat::where('chat_id', $chatId)->where('is_group', true)->first();

        if (!$chat) {
            return response()->json([
                'success' => false,
                'message' => 'Group chat not found'
            ], 404);
        }

        // Check if user is admin
        $isAdmin = ChatMember::where('chat_id', $chatId)
            ->where('user_id', $userId)
            ->where('is_admin', true)
            ->exists();

        if (!$isAdmin && $userId != $memberId) {
            return response()->json([
                'success' => false,
                'message' => 'Only admins can remove members or users can leave themselves'
            ], 403);
        }

        $deleted = ChatMember::where('chat_id', $chatId)
            ->where('user_id', $memberId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Member not found in chat'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Member removed successfully'
        ]);
    }

    // Update chat (title, etc.)
    public function update(Request $request, $chatId): JsonResponse
    {
        Log::info('Update request data:', $request->all());

        $request->validate([
            'title' => 'nullable|string|max:200'
        ]);

        $userId = Auth::user()->user_id;

        $chat = Chat::where('chat_id', $chatId)->first();

        if (!$chat) {
            return response()->json([
                'success' => false,
                'message' => 'Chat not found'
            ], 404);
        }

        // For group chats, check if user is admin
        if ($chat->is_group) {
            $isAdmin = ChatMember::where('chat_id', $chatId)
                ->where('user_id', $userId)
                ->where('is_admin', true)
                ->exists();

            if (!$isAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only admins can update group chat'
                ], 403);
            }
        }

        $chat->update($request->only(['title']));
        $chat->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Chat updated successfully',
            'data' => $chat
        ]);
    }
}
