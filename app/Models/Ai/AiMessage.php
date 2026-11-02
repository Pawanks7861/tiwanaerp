<?php

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiMessage extends Model
{
    protected $fillable = [
        'ai_conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_results',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
