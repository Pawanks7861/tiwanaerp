<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Ai\AiConversation;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiConversationService;
use App\Services\Ai\AiSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistantController extends Controller
{
    public function __construct(
        private readonly AiAssistantService $assistant,
        private readonly AiConversationService $conversations,
        private readonly AiSettingsService $settings,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('ai.use'), 403);

        $conversations = AiConversation::query()
            ->where('user_id', $user->id)
            ->latest('last_message_at')
            ->limit(30)
            ->get(['id', 'title', 'project_id', 'last_message_at', 'created_at']);

        return response()->json([
            'config' => $this->settings->publicConfig(),
            'suggestions' => $this->assistant->suggestions($user, [
                'project_id' => $request->integer('project_id') ?: null,
                'page_context' => $request->query('page_context'),
            ]),
            'welcome' => "Hello! I'm your Tiwana Smart Assistant. I can help you understand ERP workflows, find records, and check business information. How can I help you?",
            'conversations' => $conversations,
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('ai.use'), 403);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_conversations,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'page_context' => ['nullable', 'string', 'max:120'],
        ]);

        $conversation = isset($data['conversation_id'])
            ? AiConversation::query()->findOrFail($data['conversation_id'])
            : null;

        $result = $this->assistant->ask($user, $data['message'], $conversation, [
            'project_id' => $data['project_id'] ?? null,
            'page_context' => $data['page_context'] ?? null,
        ]);

        return response()->json([
            'conversation' => [
                'id' => $result['conversation']->id,
                'title' => $result['conversation']->title,
            ],
            'reply' => $result['reply'],
            'suggestions' => $result['suggestions'],
        ]);
    }

    public function show(Request $request, AiConversation $conversation): JsonResponse
    {
        $this->conversations->assertOwner($request->user(), $conversation);
        abort_unless($request->user()->can('ai.use'), 403);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
            ],
            'messages' => $conversation->messages()->get(['id', 'role', 'content', 'created_at']),
        ]);
    }

    public function destroy(Request $request, AiConversation $conversation): JsonResponse
    {
        abort_unless($request->user()->can('ai.use'), 403);
        $this->conversations->delete($request->user(), $conversation);

        return response()->json(['ok' => true]);
    }
}
