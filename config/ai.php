<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tiwana Smart Assistant (local, no external AI API)
    |--------------------------------------------------------------------------
    |
    | Fully hosted inside the ERP. Questions are resolved with a local intent
    | registry, ERP knowledge articles, and allowlisted read-only analytics.
    |
    */

    'enabled' => (bool) env('AI_ASSISTANT_ENABLED', true),

    'analytics_enabled' => (bool) env('AI_ANALYTICS_ENABLED', true),

    'history_enabled' => (bool) env('AI_HISTORY_ENABLED', true),

    'max_messages_context' => 20,

    'daily_user_request_limit' => (int) env('AI_DAILY_USER_LIMIT', 200),

    'per_minute_limit' => (int) env('AI_PER_MINUTE_LIMIT', 20),

    'retention_days' => (int) env('AI_RETENTION_DAYS', 90),

    'knowledge_path' => resource_path('ai-knowledge'),

    'intent_min_score' => 8,

];
