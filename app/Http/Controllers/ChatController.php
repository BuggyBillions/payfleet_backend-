<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    private const DEFAULT_WELCOME_MESSAGE = 'Hi 👋 Welcome to Payfleet Support. How can we help you today?';

    public function getUserMessages(): JsonResponse
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $this->ensureDefaultMessageExists($user);

        $messages = Message::query()
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->with([
                'sender:id,name,email,role',
                'receiver:id,name,email,role',
            ])
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Support messages fetched successfully.',
            'data' => $this->groupMessages($messages),
        ]);
    }

    public function sendMessageToAdmin(Request $request): JsonResponse
    {
        $request->validate([
            'message' => ['required','string','max:5000',],
        ]);

        /** @var User|null $user */
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $receiverId = Message::query()
            ->where('receiver_id', $user->id)
            ->whereHas('sender', function ($query) {
                $query->whereIn('role', ['admin','support',])
                ->where('is_active', 1)
                ->where('is_verified', 1);
            })
            ->latest()
            ->value('sender_id');

        if (!$receiverId) {
            $receiverId = User::query()
                ->whereIn('role', ['support'])
                ->where('is_active', 1)
                ->where('is_verified', 1)
                ->value('id');
        }

        if (!$receiverId) {
            return response()->json([
                'status' => false,
                'message' => 'No support agent is available at the moment.',
            ], 503);
        }

        $message = Message::query()->create([
            'sender_id' => $user->id,
            'receiver_id' => $receiverId,
            'message' => $request->input('message'),
            'is_read' => false,
        ]);


        return response()->json([
            'status' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'receiver_id' => $message->receiver_id,
                'message' => $message->message,
                'is_read' => (bool) $message->is_read,
                'created_at' => $message->created_at,
            ],
        ], 201);
    }

    public function getConversations(): JsonResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $userIds = Message::query()
            ->select('sender_id', 'receiver_id')
            ->get()
            ->flatMap(function ($message) {
                return [
                    $message->sender_id,
                    $message->receiver_id,
                ];
            })
            ->unique()
            ->values();

        $users = User::query()
            ->whereIn('id', $userIds)
            ->where('role', 'company')
            ->select(['id','name','email','phone','role',])
            ->get()
            ->map(function ($user) use ($admin) {

                $lastMessage = Message::query()
                    ->where(function ($query) use ($user) {
                        $query->where('sender_id', $user->id)
                            ->orWhere('receiver_id', $user->id);
                    })
                    ->latest()
                    ->first();

                $unreadCount = Message::query()
                    ->where('sender_id', $user->id)
                    ->where('receiver_id', $admin->id)
                    ->where('is_read', false)
                    ->count();

                return [
                    'user' => $user,
                    'last_message' => $lastMessage,
                    'unread_count' => $unreadCount,
                    'last_activity' => $lastMessage?->created_at,
                ];
            })
            ->sortByDesc('last_activity')
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Conversations fetched successfully.',
            'data' => $users,
        ]);
    }

    public function getAdminMessages($userId): JsonResponse
    {
        /** @var User|null $admin */
        $admin = Auth::user();

        if (!$admin) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $user = User::query()
            ->where('role', 'company')
            ->find($userId);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ], 404);
        }

        $messages = Message::query()
            ->where(function ($query) use ($userId) {
                $query->where('sender_id', $userId)
                    ->orWhere('receiver_id', $userId);
            })
            ->with([
                'sender:id,name,email,role',
                'receiver:id,name,email,role',
            ])
            ->orderBy('created_at', 'asc')
            ->get();

        Message::query()
            ->where('sender_id', $userId)
            ->where('receiver_id', $admin->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
            ]);

        return response()->json([
            'status' => true,
            'message' => 'User chat history fetched successfully.',
            'data' => $this->groupMessages($messages),
        ]);
    }

    public function sendMessageToUser( Request $request, $userId): JsonResponse 
    {
        $request->validate([
            'message' => ['required','string','max:5000',],
        ]);

        /** @var User|null $sender */
        $sender = Auth::user();

        if (!$sender) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($sender->is_active != 1 || $sender->is_verified != 1 || !in_array($sender->role, ['admin', 'support'])) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to send support messages.',
            ], 403);
        }

        $receiver = User::query()
            ->where('role', 'company')
            ->find($userId);

        if (!$receiver) {
            return response()->json([
                'status' => false,
                'message' => 'Company not found.',
            ], 404);
        }

        $message = Message::query()->create([
            'sender_id' => $sender->id,
            'receiver_id' => $receiver->id,
            'message' => $request->input('message'),
            'is_read' => false,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Message sent successfully.',
            'data' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'receiver_id' => $message->receiver_id,
                'message' => $message->message,
                'is_read' => (bool) $message->is_read,
                'created_at' => $message->created_at,
            ],
        ], 201);
    }

    public function markAsRead(): JsonResponse
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        Message::query()
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->update(['is_read' => true,]);

        return response()->json([
            'status' => true,
            'message' => 'Messages marked as read.',
        ]);
    }

    public function unreadCount(): JsonResponse
    {
        $userId = Auth::id();

        if (!$userId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $count = Message::query()
            ->where('receiver_id', $userId)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'status' => true,
            'message' => 'Unread message count fetched successfully.',
            'data' => ['unread_count' => $count,],
        ]);
    }

    private function groupMessages($messages)
    {
        return $messages
            ->groupBy(function ($message) {

                $date = Carbon::parse($message->created_at);
                if ($date->isToday()) {
                    return 'Today';
                }

                if ($date->isYesterday()) {
                    return 'Yesterday';
                }

                return $date->format('F j, Y');
            })
            ->map(function ($dayMessages, $day) {
                return [
                    'day' => $day,
                    'messages' => $dayMessages
                        ->map(function ($msg) {
                            $createdAt = Carbon::parse($msg->created_at);

                            return [
                                'id' => $msg->id,
                                'sender_id' => $msg->sender_id,
                                'receiver_id' => $msg->receiver_id,
                                'message' => $msg->message,
                                'is_read' => (bool) $msg->is_read,
                                'time' => $createdAt->format('g:i A'),
                                'created_at' => $msg->created_at,
                                'is_sender' =>$msg->sender_id === Auth::id(),
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();
    }

    private function ensureDefaultMessageExists(User $user): void
    {
        $exists = Message::query()
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $user->id)
                    ->orWhere('receiver_id', $user->id);
            })
            ->exists();

        if ($exists) {
            return;
        }

        $admin = User::query()
            ->whereIn('role', ['support'])
            ->where('is_active', 1)
            ->where('is_verified', 1)
            ->first();

        if ($admin) {
            Message::query()->create([
                'sender_id' => $admin->id,
                'receiver_id' => $user->id,
                'message' => self::DEFAULT_WELCOME_MESSAGE,
                'is_read' => true,
            ]);
        }
    }
}