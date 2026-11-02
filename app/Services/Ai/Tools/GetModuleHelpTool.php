<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\Contracts\AiTool;
use App\Services\Ai\ErpKnowledgeService;

class GetModuleHelpTool implements AiTool
{
    public function __construct(private readonly ErpKnowledgeService $knowledge) {}

    public function name(): string
    {
        return 'getModuleHelp';
    }

    public function description(): string
    {
        return 'Retrieve ERP workflow documentation for a topic such as BOQ, drawings, procurement, inventory, finance or imports.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'description' => 'Topic keywords, e.g. create BOQ, upload drawing, GRN, import materials',
                ],
            ],
            'required' => ['topic'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return true;
    }

    public function handle(User $user, array $arguments): array
    {
        $topic = (string) ($arguments['topic'] ?? '');
        $body = $this->knowledge->retrieve($topic, 3);

        return [
            'topic' => $topic,
            'documentation' => $body,
        ];
    }
}
