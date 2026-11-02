<?php

namespace App\Services\Ai\Local;

final class IntentDefinition
{
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $phrases
     * @param  list<string>  $patterns
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $suggestions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $label,
        public readonly array $keywords = [],
        public readonly array $phrases = [],
        public readonly array $patterns = [],
        public readonly ?string $tool = null,
        public readonly ?string $helpTopic = null,
        public readonly array $defaults = [],
        public readonly array $suggestions = [],
        public readonly ?string $routeName = null,
        public readonly ?string $permission = null,
    ) {}
}
