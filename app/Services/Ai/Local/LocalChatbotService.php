<?php

namespace App\Services\Ai\Local;

use App\Models\Ai\AiConversation;
use App\Models\Ai\AiUsageLog;
use App\Models\User;
use App\Services\Ai\AiConversationService;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\ErpKnowledgeService;
use App\Services\Ai\ErpToolRegistry;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LocalChatbotService
{
    public function __construct(
        private readonly AiConversationService $conversations,
        private readonly AiSettingsService $settings,
        private readonly QuestionNormalizer $normalizer,
        private readonly DateRangeResolver $dates,
        private readonly EntityExtractor $entities,
        private readonly IntentResolver $intents,
        private readonly IntentRegistry $registry,
        private readonly ErpKnowledgeService $knowledge,
        private readonly ErpToolRegistry $tools,
        private readonly ChatResponseFormatter $formatter,
        private readonly CurrentCompany $company,
    ) {}

    /**
     * @param  array{project_id?: ?int, page_context?: ?string}  $context
     * @return array{conversation: AiConversation, reply: string, suggestions: list<string>, intent: ?string}
     */
    public function ask(User $user, string $message, ?AiConversation $conversation = null, array $context = []): array
    {
        $message = trim($message);
        if ($message === '') {
            throw ValidationException::withMessages(['message' => 'Enter a question.']);
        }

        $cfg = $this->settings->effective();
        if (! $cfg['enabled']) {
            throw ValidationException::withMessages(['ai' => 'Smart Assistant is currently unavailable.']);
        }

        $this->assertRateLimit($user, $cfg);

        if ($conversation === null) {
            $conversation = $this->conversations->start($user, $context);
        } else {
            $this->conversations->assertOwner($user, $conversation);
        }

        if (! $cfg['history_enabled'] && $conversation->messages()->exists()) {
            // Still allow the turn, but new chats are preferred; history flag mainly gates listing.
        }

        $this->conversations->addMessage($conversation, 'user', $message);

        $normalized = $this->normalizer->normalize($message);
        $tz = $this->company->get()?->timezone ?: config('app.timezone', 'Asia/Kolkata');
        $dateFilters = $this->dates->extract($normalized, $tz) ?? [];
        $entityFilters = $this->entities->extract($user, $message, $normalized, $context);

        $prevContext = is_array($conversation->context) ? $conversation->context : [];
        $resolveContext = array_merge($prevContext, [
            'follow_up_only' => (bool) ($entityFilters['follow_up_only'] ?? false),
        ]);

        $resolved = $this->intents->resolve($message, $normalized, $resolveContext);
        $intent = $resolved['intent'];

        if ($intent === null) {
            $reply = $this->formatter->formatFallback($resolved['candidates'], $this->suggestions($user, $context));
            $this->persistAssistant($conversation, $user, $reply, null, $prevContext, []);

            return [
                'conversation' => $conversation->fresh(),
                'reply' => $reply,
                'suggestions' => $this->suggestions($user, $context),
                'intent' => null,
            ];
        }

        if ($intent->permission && ! $user->can($intent->permission)) {
            $reply = 'This information is restricted for your role. Ask an administrator if you need access.';
            $this->persistAssistant($conversation, $user, $reply, $intent->id, $prevContext, []);

            return [
                'conversation' => $conversation->fresh(),
                'reply' => $reply,
                'suggestions' => $this->suggestions($user, $context),
                'intent' => $intent->id,
            ];
        }

        $filters = $this->mergeFilters($prevContext['filters'] ?? [], $intent, $dateFilters, $entityFilters, $context);

        if ($intent->type === 'help') {
            $doc = $this->knowledge->retrieve($intent->helpTopic ?? $message, 2);
            $reply = $this->formatter->formatHelp($doc, $this->routeHint($intent, $user));
            $newContext = [
                'intent' => $intent->id,
                'filters' => $filters,
                'module' => 'help',
            ];
            $this->persistAssistant($conversation, $user, $reply, $intent->id, $newContext, ['help' => true]);

            return [
                'conversation' => $conversation->fresh(),
                'reply' => $reply,
                'suggestions' => $intent->suggestions !== [] ? $intent->suggestions : $this->suggestions($user, $context),
                'intent' => $intent->id,
            ];
        }

        if (! $cfg['analytics_enabled']) {
            $reply = 'Live ERP analytics are currently unavailable.';
            $this->persistAssistant($conversation, $user, $reply, $intent->id, $prevContext, []);

            return [
                'conversation' => $conversation->fresh(),
                'reply' => $reply,
                'suggestions' => $this->suggestions($user, $context),
                'intent' => $intent->id,
            ];
        }

        $toolArgs = array_merge($intent->defaults, $filters);
        unset($toolArgs['follow_up_only']);

        $payload = $this->tools->execute($user, (string) $intent->tool, $toolArgs);
        $reply = $this->formatter->formatAnalytics($intent->id, $payload, $toolArgs, $user);

        $newContext = [
            'intent' => $intent->id,
            'filters' => [
                'period' => $toolArgs['period'] ?? null,
                'from' => $toolArgs['from'] ?? null,
                'to' => $toolArgs['to'] ?? null,
                'project_id' => $toolArgs['project_id'] ?? null,
                'vendor_id' => $toolArgs['vendor_id'] ?? null,
                'group_by' => $toolArgs['group_by'] ?? 'none',
                'pending_only' => $toolArgs['pending_only'] ?? false,
            ],
            'module' => explode('.', $intent->id)[0] ?? 'erp',
        ];

        $this->persistAssistant($conversation, $user, $reply, $intent->id, $newContext, [
            'tool' => $intent->tool,
            'ok' => ! isset($payload['error']),
        ]);

        return [
            'conversation' => $conversation->fresh(),
            'reply' => $reply,
            'suggestions' => $intent->suggestions !== [] ? $intent->suggestions : $this->suggestions($user, $context),
            'intent' => $intent->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    public function suggestions(User $user, array $context = []): array
    {
        $items = [];
        foreach ($this->registry->suggestionLabels() as $label) {
            $items[] = $label;
        }

        $filtered = [];
        foreach ($items as $item) {
            $n = $this->normalizer->normalize($item);
            if (str_contains($n, 'purchase_order') && ! $user->can('purchase.view')) {
                continue;
            }
            if (str_contains($n, 'drawing') && ! $user->can('drawings.view')) {
                continue;
            }
            if (str_contains($n, 'inventory') && ! $user->can('inventory.view')) {
                continue;
            }
            if (str_contains($n, 'vendor') && ! $user->can('vendor_bills.view') && ! $user->can('purchase.view')) {
                continue;
            }
            if (str_contains($n, 'grn') && ! $user->can('grn.view')) {
                continue;
            }
            $filtered[] = $item;
        }

        if ($filtered === []) {
            $filtered = ['How to create BOQ?', 'Help me with the ERP'];
        }

        return array_slice(array_values(array_unique($filtered)), 0, 6);
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $dateFilters
     * @param  array<string, mixed>  $entityFilters
     * @param  array<string, mixed>  $pageContext
     * @return array<string, mixed>
     */
    private function mergeFilters(
        array $previous,
        IntentDefinition $intent,
        array $dateFilters,
        array $entityFilters,
        array $pageContext,
    ): array {
        $filters = array_merge($intent->defaults, $previous);

        foreach (['period', 'from', 'to'] as $key) {
            if (array_key_exists($key, $dateFilters) && $dateFilters[$key] !== null) {
                $filters[$key] = $dateFilters[$key];
            }
        }

        foreach (['project_id', 'vendor_id', 'group_by'] as $key) {
            if (array_key_exists($key, $entityFilters) && $entityFilters[$key] !== null) {
                $filters[$key] = $entityFilters[$key];
            }
        }

        // Page project context for analytics when user did not name another project and this is a fresh intent.
        if (
            empty($filters['project_id'])
            && ! empty($pageContext['project_id'])
            && ($entityFilters['follow_up_only'] ?? false) === false
            && $intent->type === 'analytics'
            && ! isset($entityFilters['project_id'])
        ) {
            // Prefer page project only when question implies "this project" — already handled in EntityExtractor.
        }

        if (! empty($pageContext['project_id']) && empty($filters['project_id']) && $this->pageImpliesProject($pageContext)) {
            // no-op unless entity extractor set it
        }

        if (($entityFilters['follow_up_only'] ?? false) === true) {
            // Keep previous period unless dateFilters provided
            if ($dateFilters === [] && isset($previous['period'])) {
                $filters['period'] = $previous['period'];
                $filters['from'] = $previous['from'] ?? null;
                $filters['to'] = $previous['to'] ?? null;
            }
            if (! isset($entityFilters['project_id']) && isset($previous['project_id'])) {
                $filters['project_id'] = $previous['project_id'];
            }
            if (! isset($entityFilters['vendor_id']) && isset($previous['vendor_id'])) {
                $filters['vendor_id'] = $previous['vendor_id'];
            }
        }

        return array_filter(
            $filters,
            static fn ($v) => $v !== null && $v !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $pageContext
     */
    private function pageImpliesProject(array $pageContext): bool
    {
        return filled($pageContext['project_id'] ?? null);
    }

    private function routeHint(IntentDefinition $intent, User $user): ?string
    {
        return match (true) {
            str_contains($intent->id, 'boq') && $user->can('boq.view') => 'Project → BOQ Management',
            str_contains($intent->id, 'drawing') && $user->can('drawings.view') => 'Project → Drawing Management',
            str_contains($intent->id, 'grn') && $user->can('grn.view') => 'Project → Procurement → GRN',
            str_contains($intent->id, 'po') || str_contains($intent->id, 'pr') => 'Project → Procurement',
            str_contains($intent->id, 'inventory') => 'Inventory / Stores',
            str_contains($intent->id, 'finance') => 'Finance → Vendor Bills / Payments',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $toolMeta
     */
    private function persistAssistant(
        AiConversation $conversation,
        User $user,
        string $reply,
        ?string $intentId,
        array $context,
        array $toolMeta,
    ): void {
        $conversation->forceFill(['context' => $context])->save();

        $this->conversations->addMessage($conversation, 'assistant', $reply, [
            'provider' => 'local',
            'model' => 'smart-assistant',
            'tool_results' => $toolMeta !== [] ? array_merge($toolMeta, ['intent' => $intentId]) : ['intent' => $intentId],
        ]);

        AiUsageLog::query()->create([
            'user_id' => $user->id,
            'ai_conversation_id' => $conversation->id,
            'provider' => 'local',
            'model' => $intentId ?? 'fallback',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'status' => 'ok',
            'error_code' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function assertRateLimit(User $user, array $cfg): void
    {
        $companyId = $this->company->id();
        $dayKey = 'smart:'.$companyId.':'.$user->id.':'.now()->toDateString();
        $minKey = 'smart-min:'.$companyId.':'.$user->id.':'.now()->format('YmdHi');

        $daily = (int) ($cfg['daily_user_request_limit'] ?? config('ai.daily_user_request_limit'));
        $perMinute = (int) ($cfg['per_minute_limit'] ?? config('ai.per_minute_limit', 20));

        if (RateLimiter::tooManyAttempts($minKey, $perMinute)) {
            throw ValidationException::withMessages([
                'ai' => 'Too many assistant requests. Please wait a minute and try again.',
            ]);
        }
        if (RateLimiter::tooManyAttempts($dayKey, $daily)) {
            throw ValidationException::withMessages([
                'ai' => 'Daily Smart Assistant limit reached. Try again tomorrow or ask an administrator to raise the limit.',
            ]);
        }

        RateLimiter::hit($minKey, 60);
        RateLimiter::hit($dayKey, 86400);
    }
}
