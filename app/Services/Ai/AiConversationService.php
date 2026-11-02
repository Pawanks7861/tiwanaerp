<?php

namespace App\Services\Ai;

use App\Models\Ai\AiConversation;
use App\Models\Ai\AiMessage;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Str;

class AiConversationService
{
    public function __construct(private readonly CurrentCompany $company) {}

    /**
     * @param  array{project_id?: ?int, page_context?: ?string, context?: array<string, mixed>}  $meta
     */
    public function start(User $user, array $meta = []): AiConversation
    {
        $conversation = new AiConversation;
        $conversation->fill([
            'user_id' => $user->id,
            'project_id' => $meta['project_id'] ?? null,
            'title' => 'New chat',
            'page_context' => $meta['page_context'] ?? null,
            'context' => $meta['context'] ?? null,
            'last_message_at' => now(),
        ])->save();

        return $conversation;
    }

    public function assertOwner(User $user, AiConversation $conversation): void
    {
        abort_unless(
            (int) $conversation->company_id === (int) $this->company->id()
            && (int) $conversation->user_id === (int) $user->id,
            403
        );
    }

    public function addMessage(AiConversation $conversation, string $role, string $content, array $extra = []): AiMessage
    {
        $message = new AiMessage;
        $message->fill([
            'ai_conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
            'tool_calls' => $extra['tool_calls'] ?? null,
            'tool_results' => $extra['tool_results'] ?? null,
            'provider' => $extra['provider'] ?? null,
            'model' => $extra['model'] ?? null,
            'prompt_tokens' => $extra['prompt_tokens'] ?? 0,
            'completion_tokens' => $extra['completion_tokens'] ?? 0,
        ])->save();

        if ($role === 'user' && ($conversation->title === 'New chat' || blank($conversation->title))) {
            $conversation->title = Str::limit(trim($content), 80, '…');
        }
        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    public function delete(User $user, AiConversation $conversation): void
    {
        $this->assertOwner($user, $conversation);
        $conversation->messages()->delete();
        $conversation->delete();
    }
}
