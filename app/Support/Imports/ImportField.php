<?php

namespace App\Support\Imports;

final class ImportField
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>|null  $allowed  human labels for template instructions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly array $aliases = [],
        public readonly ?string $type = 'string',
        public readonly ?array $allowed = null,
        public readonly ?string $help = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'required' => $this->required,
            'aliases' => $this->aliases,
            'type' => $this->type,
            'allowed' => $this->allowed,
            'help' => $this->help,
        ];
    }
}
