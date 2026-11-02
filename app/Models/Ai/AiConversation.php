<?php

namespace App\Models\Ai;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiConversation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'user_id',
        'project_id',
        'title',
        'page_context',
        'context',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }
}
