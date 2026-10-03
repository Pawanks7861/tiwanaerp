<?php

namespace App\Services\Resources\Concerns;

use App\Models\Boq\BoqItem;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Services\Inventory\Concerns\ResolvesInventoryLines;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Tenant- and project-safe resolution of ids and amounts posted to labour, subcontract and
 * equipment documents. Never trusts ids from the client: every reference is re-read inside the
 * project (and so the company).
 */
trait ResolvesProjectRefs
{
    use ResolvesInventoryLines;

    protected function site(Project $project, mixed $id, string $key = 'site_id'): ?Site
    {
        if (blank($id)) {
            return null;
        }

        $site = is_numeric($id) ? Site::query()->where('project_id', $project->id)->whereKey((int) $id)->first() : null;

        return $site ?? throw ValidationException::withMessages([$key => 'Choose a site of this project.']);
    }

    /**
     * Non-negative decimal with at most $scale places; blank counts as zero unless $required.
     */
    protected function amount(mixed $value, string $key, int $scale = Decimal::MONEY_SCALE, ?string $max = null, bool $required = false): Decimal
    {
        if (blank($value)) {
            if (! $required) {
                return Decimal::zero();
            }
            throw ValidationException::withMessages([$key => 'This value is required.']);
        }

        try {
            $amount = Decimal::of(is_scalar($value) ? (string) $value : '');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$key => 'Enter a valid number.']);
        }

        if ($amount->isNegative()) {
            throw ValidationException::withMessages([$key => 'The value cannot be negative.']);
        }
        if (! $amount->equals($amount->round($scale))) {
            throw ValidationException::withMessages([$key => "Use at most {$scale} decimal places."]);
        }
        if ($max !== null && $amount->greaterThan($max)) {
            throw ValidationException::withMessages([$key => "The value cannot exceed {$max}."]);
        }

        return $amount;
    }

    protected function percent(mixed $value, string $key): Decimal
    {
        return $this->amount($value, $key, Decimal::PERCENT_SCALE, '100');
    }

    /**
     * BOQ line of a task, for the cost ledger link.
     *
     * @return array{0: ?int, 1: ?string}
     */
    protected function boqRefOfTask(?int $taskId): array
    {
        if ($taskId === null) {
            return [null, null];
        }

        $boqItemId = ProjectTask::query()->whereKey($taskId)->value('boq_item_id');

        return [$boqItemId, $boqItemId ? BoqItem::query()->whereKey($boqItemId)->value('line_uid') : null];
    }
}
