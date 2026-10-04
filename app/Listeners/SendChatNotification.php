<?php

namespace App\Listeners;

use App\Events\Chat\MessageSent;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Services\Chat\ChatService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * One in-app notice per recipient per message. Skipped when that person is looking at the
 * conversation, or when they have turned the database channel off. The message row is unchanged.
 */
class SendChatNotification
{
    public function __construct(private readonly ChatService $chat) {}

    public function handle(MessageSent $event): void
    {
        $message = $event->message->load('conversation.participants');
        $conversation = $message->conversation;
        $sender = User::query()->find($message->sender_id);
        if ($conversation === null || $sender === null) {
            return;
        }

        $excerpt = $message->isDeleted()
            ? 'Sent a message'
            : (filled($message->body) ? Str::limit($message->body, 140) : 'Sent an attachment');

        foreach ($conversation->participants as $participant) {
            if ((int) $participant->user_id === (int) $message->sender_id || $this->chat->isViewing($participant)) {
                continue;
            }

            $user = User::query()->whereKey($participant->user_id)->where('is_active', true)->first();
            if ($user === null || $this->alreadySent($user, $message->id)) {
                continue;
            }

            Notification::send($user, new GeneralNotification(
                (int) $message->company_id,
                'chat.message_received',
                'New message',
                "{$sender->name}: {$excerpt}",
                route('chat.show', $conversation, absolute: false),
                ['message_id' => $message->id, 'conversation_id' => $conversation->id],
            ));
        }
    }

    private function alreadySent(User $user, int $messageId): bool
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->where('data->message_id', $messageId)
            ->exists();
    }
}
