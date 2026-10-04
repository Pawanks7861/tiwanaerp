<?php

namespace App\Services\Chat;

use App\Models\Chat\Conversation;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shapes chat rows for the screen. Message text is plain; the client must not render it as HTML.
 */
class ChatPresenter
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly ChatService $chat,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function inbox(User $user): array
    {
        $conversations = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->with([
                'participants.user:id,name,email,last_seen_at,is_active',
                'latestMessage.sender:id,name',
                'latestMessage.attachments',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $unread = $this->unreadByConversation($user);
        $roles = $this->roles($conversations->flatMap(fn (Conversation $c) => $c->participants->pluck('user_id'))->all());

        return $conversations->map(function (Conversation $conversation) use ($user, $unread, $roles) {
            $peer = $conversation->participants->first(fn ($p) => (int) $p->user_id !== $user->id)?->user;
            $latest = $conversation->latestMessage;

            return [
                'id' => $conversation->id,
                'peer' => $peer ? $this->person($peer, $roles[$peer->id] ?? null) : null,
                'unread' => (int) ($unread[$conversation->id] ?? 0),
                'preview' => $this->preview($latest),
                'at' => $latest?->created_at?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return array{messages: list<array<string, mixed>>, has_more: bool}
     */
    public function page(Conversation $conversation, ?int $before, ?int $after): array
    {
        $with = ['attachments', 'sender:id,name', 'replyTo.sender:id,name'];

        if ($after !== null) {
            $messages = $conversation->messages()->with($with)->where('id', '>', $after)->orderBy('id')->limit(50)->get();

            return ['messages' => $this->messages($messages), 'has_more' => false];
        }

        $limit = 40;
        $older = $conversation->messages()->with($with)
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $older->count() > $limit;

        return [
            'messages' => $this->messages($older->take($limit)->sortBy('id')->values()),
            'has_more' => $hasMore,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function directory(User $actor, string $search): array
    {
        $companyId = $this->tenancy->require()->id;
        $term = trim($search);

        $users = User::query()
            ->select('users.id', 'users.name', 'users.email', 'users.last_seen_at')
            ->where('users.is_active', true)
            ->where('users.id', '!=', $actor->id)
            ->whereHas('memberships', fn ($q) => $q->where('company_id', $companyId)->where('is_active', true))
            ->when($term !== '', function ($q) use ($term, $companyId) {
                $q->where(function ($inner) use ($term, $companyId) {
                    $inner->where('users.name', 'like', '%'.$term.'%')
                        ->orWhere('users.email', 'like', '%'.$term.'%')
                        ->orWhereExists(function ($roles) use ($term, $companyId) {
                            $roles->selectRaw('1')
                                ->from('model_has_roles')
                                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                                ->whereColumn('model_has_roles.model_id', 'users.id')
                                ->where('model_has_roles.model_type', (new User)->getMorphClass())
                                ->where('model_has_roles.team_id', $companyId)
                                ->where('roles.name', 'like', '%'.$term.'%');
                        });
                });
            })
            ->orderBy('users.name')
            ->limit(30)
            ->get();

        $roles = $this->roles($users->modelKeys());

        return $users->map(fn (User $user) => $this->person($user, $roles[$user->id] ?? null))->all();
    }

    public function unreadTotal(User $user): int
    {
        return (int) array_sum($this->unreadByConversation($user));
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public function messages(Collection $messages): array
    {
        return $messages->map(fn (Message $message) => $this->message($message))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function message(Message $message): array
    {
        $deleted = $message->isDeleted();

        return [
            'id' => $message->id,
            'sender_id' => $message->sender_id,
            'sender' => $message->sender?->name,
            'body' => $deleted ? null : $message->body,
            'deleted' => $deleted,
            'edited_at' => $message->edited_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'reply' => $this->reply($message),
            'attachments' => $deleted ? [] : $message->attachments->map(fn (MessageAttachment $file) => [
                'id' => $file->id,
                'name' => $file->original_name,
                'size' => $file->size_bytes,
                'mime' => $file->mime,
                'image' => in_array($file->extension(), ['jpg', 'jpeg', 'png', 'webp'], true),
                'url' => route('chat.attachments.show', $file),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function person(User $user, ?string $role): array
    {
        $online = $user->last_seen_at !== null && $user->last_seen_at->gt(now()->subSeconds(ChatService::ONLINE_SECONDS));

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $role,
            'online' => $online,
            'last_seen_at' => $user->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function roles(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->where('model_has_roles.team_id', $this->tenancy->id())
            ->whereIn('model_has_roles.model_id', $userIds)
            ->orderBy('roles.name')
            ->get(['model_has_roles.model_id as user_id', 'roles.name'])
            ->groupBy('user_id')
            ->mapWithKeys(fn ($rows, $id) => [(int) $id => $rows->first()->name])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function unreadByConversation(User $user): array
    {
        return Message::query()
            ->join('conversation_participants as participants', function ($join) use ($user) {
                $join->on('participants.conversation_id', '=', 'messages.conversation_id')
                    ->where('participants.user_id', $user->id);
            })
            ->where('messages.sender_id', '!=', $user->id)
            ->whereNull('messages.deleted_at')
            ->where(function ($query) {
                $query->whereNull('participants.last_read_message_id')
                    ->orWhereColumn('messages.id', '>', 'participants.last_read_message_id');
            })
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id as conversation_id, count(*) as unread')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function preview(?Message $message): ?string
    {
        if ($message === null) {
            return null;
        }
        if ($message->isDeleted()) {
            return 'Message deleted';
        }
        if (filled($message->body)) {
            return mb_strimwidth($message->body, 0, 80, '…');
        }

        $name = $message->attachments->first()?->original_name;

        return $name ? 'Attachment: '.$name : 'Attachment';
    }

    /**
     * @return array{id: int, sender: ?string, excerpt: string}|null
     */
    private function reply(Message $message): ?array
    {
        $source = $message->replyTo;
        if ($source === null) {
            return null;
        }

        return [
            'id' => $source->id,
            'sender' => $source->sender?->name,
            'excerpt' => $source->isDeleted() ? 'Message deleted' : mb_strimwidth((string) ($source->body ?: 'Attachment'), 0, 80, '…'),
        ];
    }
}
