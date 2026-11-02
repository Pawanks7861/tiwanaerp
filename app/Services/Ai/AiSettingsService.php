<?php

namespace App\Services\Ai;

/**
 * Internal Smart Assistant configuration.
 * Limits and feature flags come from config/ai.php (and env) — there is no user-facing settings UI.
 */
class AiSettingsService
{
    /**
     * @return array{
     *   enabled: bool,
     *   analytics_enabled: bool,
     *   history_enabled: bool,
     *   daily_user_request_limit: int,
     *   per_minute_limit: int,
     *   retention_days: int,
     *   configured: bool,
     *   engine: string
     * }
     */
    public function effective(): array
    {
        $enabled = (bool) config('ai.enabled', true);

        return [
            'enabled' => $enabled,
            'analytics_enabled' => (bool) config('ai.analytics_enabled', true),
            'history_enabled' => (bool) config('ai.history_enabled', true),
            'daily_user_request_limit' => (int) config('ai.daily_user_request_limit', 200),
            'per_minute_limit' => (int) config('ai.per_minute_limit', 20),
            'retention_days' => (int) config('ai.retention_days', 90),
            'configured' => true,
            'engine' => 'local',
        ];
    }

    /**
     * Public bootstrap payload (no secrets).
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        $cfg = $this->effective();

        return [
            'enabled' => $cfg['enabled'],
            'configured' => true,
            'analytics_enabled' => $cfg['analytics_enabled'],
            'history_enabled' => $cfg['history_enabled'],
            'engine' => 'local',
        ];
    }
}
