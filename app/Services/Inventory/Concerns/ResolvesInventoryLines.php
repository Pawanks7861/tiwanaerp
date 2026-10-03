<?php

namespace App\Services\Inventory\Concerns;

use App\Enums\Boq\BoqStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Masters\Material;
use App\Models\Planning\ProjectTask;
use App\Models\Projects\Project;
use App\Support\Math\Decimal;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Tenant- and project-safe resolution of the ids posted on inventory document lines.
 */
trait ResolvesInventoryLines
{
    protected function material(mixed $id, string $key): Material
    {
        $material = is_numeric($id) ? Material::query()->active()->whereKey((int) $id)->first() : null;

        return $material ?? throw ValidationException::withMessages([$key => 'Choose an active item.']);
    }

    protected function quantity(mixed $value, string $key, bool $allowZero = false): Decimal
    {
        try {
            $quantity = Decimal::of(is_scalar($value) ? (string) $value : '');
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$key => 'Enter a valid quantity.']);
        }

        if ($quantity->isNegative() || (! $allowZero && $quantity->isZero())) {
            throw ValidationException::withMessages([$key => $allowZero ? 'The quantity cannot be negative.' : 'The quantity must be greater than zero.']);
        }

        if (! $quantity->equals($quantity->round(Decimal::QTY_SCALE))) {
            throw ValidationException::withMessages([$key => 'Use at most 4 decimal places.']);
        }

        return $quantity;
    }

    /**
     * BOQ line of the project's current approved BOQ.
     */
    protected function boqItem(Project $project, mixed $id, string $key): ?BoqItem
    {
        if (blank($id)) {
            return null;
        }

        $boqId = Boq::query()->where('project_id', $project->id)->where('is_current', true)->where('status', BoqStatus::Approved)->value('id');
        $item = $boqId !== null && is_numeric($id) ? BoqItem::query()->where('boq_id', $boqId)->whereKey((int) $id)->first() : null;

        return $item ?? throw ValidationException::withMessages([$key => 'Choose a line of the current approved BOQ of this project.']);
    }

    protected function task(Project $project, mixed $id, string $key): ?ProjectTask
    {
        if (blank($id)) {
            return null;
        }

        $task = is_numeric($id) ? ProjectTask::query()->where('project_id', $project->id)->whereKey((int) $id)->first() : null;

        return $task ?? throw ValidationException::withMessages([$key => 'Choose a task of this project.']);
    }
}
