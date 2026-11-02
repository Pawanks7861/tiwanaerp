<?php

namespace App\Services\Ai\Contracts;

use App\Models\User;

interface AiTool
{
    public function name(): string;

    public function description(): string;

    /**
     * OpenAI-style JSON schema parameters object.
     *
     * @return array<string, mixed>
     */
    public function parametersSchema(): array;

    public function authorize(User $user, array $arguments): bool;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(User $user, array $arguments): array;
}
