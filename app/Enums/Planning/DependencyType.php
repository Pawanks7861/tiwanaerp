<?php

namespace App\Enums\Planning;

use App\Enums\Concerns\HasOptions;

enum DependencyType: string
{
    use HasOptions;

    case FinishToStart = 'FS';
    case StartToStart = 'SS';
    case FinishToFinish = 'FF';
    case StartToFinish = 'SF';

    public function label(): string
    {
        return match ($this) {
            self::FinishToStart => 'Finish to start (FS)',
            self::StartToStart => 'Start to start (SS)',
            self::FinishToFinish => 'Finish to finish (FF)',
            self::StartToFinish => 'Start to finish (SF)',
        };
    }
}
