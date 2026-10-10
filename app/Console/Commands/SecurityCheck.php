<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('security:check')]
#[Description('Report production readiness without printing secrets')]
class SecurityCheck extends Command
{
    public function handle(): int
    {
        $production = app()->isProduction();
        $checks = [
            'APP_ENV is production' => $production,
            'APP_DEBUG is off' => config('app.debug') === false,
            'APP_KEY is set' => filled(config('app.key')),
            'Session cookie is secure' => config('session.secure') === true,
            'Session cookie is HTTP only' => config('session.http_only') === true,
        ];

        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '[ok] ' : '[!!] ').$label);
        }

        if (! $production) {
            $this->warn('This environment is not production. Failing checks above are expected locally.');

            return self::SUCCESS;
        }

        return collect($checks)->contains(false) ? self::FAILURE : self::SUCCESS;
    }
}
