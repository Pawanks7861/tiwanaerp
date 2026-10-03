<?php

namespace App\Enums\Planning;

use App\Enums\Concerns\HasOptions;

enum TaskStatus: string
{
    use HasOptions;

    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Delayed = 'delayed';
    case Completed = 'completed';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Delayed => 'Delayed',
            self::Completed => 'Completed',
            self::OnHold => 'On hold',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::NotStarted => [self::InProgress, self::OnHold],
            self::InProgress => [self::Delayed, self::Completed, self::OnHold],
            self::Delayed => [self::InProgress, self::Completed, self::OnHold],
            self::OnHold => [self::NotStarted, self::InProgress],
            self::Completed => [self::InProgress],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
