<?php

namespace App\Services\Chat;

use App\Events\Chat\MessageSent;
use App\Models\Chat\Conversation;
use App\Models\Chat\ConversationParticipant;
use App\Models\Chat\Message;
use App\Models\Chat\MessageAttachment;
use App\Models\Core\CompanyUser;
use App\Models\User;
use App\Services\Files\FilePreviewService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Direct chat inside the current company. The sender is always the authenticated user.
 * A platform super admin is not added to existing conversations; they only see threads
 * they have joined, including one they start after entering that company.
 */
class ChatService
{
    public const BODY_MAX = 8000;

    public const ONLINE_SECONDS = 60;

    public const VIEWING_SECONDS = 20;

    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly ChatFileStore $files,
    ) {}

    public function direct(User $actor, int $otherId): Conversation
    {
        $this->assertCanSend($actor);
        if ($actor->id === $otherId) {
            throw ValidationException::withMessages(['user_id' => 'Choose a colleague.']);
        }
        $other = $this->activeColleague($otherId);
        $key = min($actor->id, $other->id).'-'.max($actor->id, $other->id);

        return DB::transaction(function () use ($actor, $other, $key) {
            $existing = Conversation::query()->where('type', 'direct')->where('pair_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $conversation = new Conversation;
            $conversation->forceFill([
                'type' => 'direct',
                'pair_key' => $key,
                'created_by' => $actor->id,
            ])->save();

            foreach ([$actor->id, $other->id] as $userId) {
                $conversation->participants()->create([
                    'user_id' => $userId,
                    'joined_at' => now(),
                ]);
            }

            return $conversation;
        });
    }

    /**
     * @param  list<UploadedFile>|UploadedFile|null  $files
     */
    public function send(Conversation $conversation, User $sender, mixed $body, mixed $files, ?int $replyToId): Message
    {
        $this->participant($conversation, $sender);
        $this->assertCanSend($sender);

        $text = $this->text($body);
        $uploads = $this->uploads($files);
        if ($text === null && $uploads === []) {
            throw ValidationException::withMessages(['body' => 'Write a message or attach a file.']);
        }
        if (count($uploads) > ChatFileStore::MAX_FILES) {
            throw ValidationException::withMessages(['files' => 'Attach at most '.ChatFileStore::MAX_FILES.' files.']);
        }

        $replyId = $this->replyId($conversation, $replyToId);
        $stored = [];
        $committed = false;

        try {
            foreach ($uploads as $upload) {
                $stored[] = $this->files->put($upload, (int) $conversation->company_id);
            }
            $message = DB::transaction(function () use ($conversation, $sender, $text, $replyId, $stored) {
                $message = new Message;
                $message->forceFill([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'body' => $text,
                    'reply_to_message_id' => $replyId,
                ])->save();

                foreach ($stored as $file) {
                    $attachment = new MessageAttachment;
                    $attachment->forceFill($file + [
                        'message_id' => $message->id,
                        'uploaded_by' => $sender->id,
                    ])->save();
                    app(FilePreviewService::class)->enqueue('chat', (int) $attachment->id);
                }

                $conversation->touch();

                return $message;
            });
            $committed = true;
            MessageSent::dispatch($message);

            return $message->load(['attachments', 'replyTo', 'sender:id,name']);
        } catch (\Throwable $e) {
            if (! $committed) {
                foreach ($stored as $file) {
                    $this->files->discard($file);
                }
            }
            throw $e;
        }
    }

    public function edit(Message $message, User $sender, mixed $body): Message
    {
        $this->owned($message, $sender);
        $text = $this->text($body);
        if ($text === null && $message->attachments()->count() === 0) {
            throw ValidationException::withMessages(['body' => 'Write a message or keep an attachment.']);
        }

        $message->forceFill(['body' => $text, 'edited_at' => now()])->save();

        return $message;
    }

    public function delete(Message $message, User $sender): void
    {
        $this->owned($message, $sender);
        $message->forceFill(['deleted_at' => now()])->save();
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        $participant = $this->participant($conversation, $user);
        $latest = $conversation->messages()->max('id');
        $participant->forceFill([
            'last_read_message_id' => $latest,
            'last_read_at' => now(),
            'viewing_at' => now(),
        ])->save();
    }

    public function touchPresence(User $user): void
    {
        $user->forceFill(['last_seen_at' => now()])->save();
    }

    public function participant(Conversation $conversation, User $user): ConversationParticipant
    {
        abort_unless((int) $conversation->company_id === $this->tenancy->id(), 404);

        $row = $conversation->participants()->where('user_id', $user->id)->first();
        abort_unless($row !== null, 404);

        return $row;
    }

    public function isViewing(ConversationParticipant $participant): bool
    {
        return $participant->viewing_at !== null && $participant->viewing_at->gt(now()->subSeconds(self::VIEWING_SECONDS));
    }

    private function owned(Message $message, User $sender): void
    {
        $this->participant($message->conversation, $sender);
        abort_unless((int) $message->sender_id === $sender->id && ! $message->isDeleted(), 404);
    }

    private function assertCanSend(User $user): void
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages(['body' => 'Your account is inactive.']);
        }

        $company = $this->tenancy->require();
        if ($user->isSuperAdmin() && $user->canAccessCompany($company)) {
            return;
        }

        $active = CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (! $active) {
            throw ValidationException::withMessages(['body' => 'Your account is inactive.']);
        }
    }

    private function activeColleague(int $userId): User
    {
        $companyId = $this->tenancy->require()->id;
        $user = User::query()->whereKey($userId)->where('is_active', true)->first();
        $member = $user && CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();

        if (! $member) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active colleague in this company.']);
        }

        return $user;
    }

    private function text(mixed $body): ?string
    {
        $text = trim(strip_tags((string) $body));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::BODY_MAX) {
            throw ValidationException::withMessages(['body' => 'A message can be at most '.self::BODY_MAX.' characters.']);
        }

        return $text;
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploads(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }
        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));
    }

    private function replyId(Conversation $conversation, ?int $replyToId): ?int
    {
        if ($replyToId === null) {
            return null;
        }

        $exists = $conversation->messages()->whereKey($replyToId)->exists();
        if (! $exists) {
            throw ValidationException::withMessages(['reply_to_message_id' => 'Reply to a message in this conversation.']);
        }

        return $replyToId;
    }
}
