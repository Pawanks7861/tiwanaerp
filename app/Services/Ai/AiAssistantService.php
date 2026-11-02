<?php

namespace App\Services\Ai;

use App\Models\Ai\AiConversation;
use App\Models\User;
use App\Services\Ai\Local\LocalChatbotService;

/**
 * Facade kept for existing controllers — delegates to the local Smart Assistant engine.
 * No external AI provider is used.
 */
class AiAssistantService
{
    public function __construct(private readonly LocalChatbotService $chatbot) {}

    /**
     * @param  array{project_id?: ?int, page_context?: ?string}  $context
     * @return array{conversation: AiConversation, reply: string, suggestions: list<string>, intent?: ?string}
     */
    public function ask(User $user, string $message, ?AiConversation $conversation = null, array $context = []): array
    {
        return $this->chatbot->ask($user, $message, $conversation, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    public function suggestions(User $user, array $context = []): array
    {
        return $this->chatbot->suggestions($user, $context);
    }
}
