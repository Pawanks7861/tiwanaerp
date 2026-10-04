<?php

namespace App\Services\Quality;

use App\Models\Quality\QualityChecklist;
use App\Models\Quality\QualityChecklistItem;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checklist templates. Items are synced by id so unchanged checkpoints keep their identity;
 * inspections hold their own copy, so template edits never touch existing inspections.
 */
class QualityChecklistService
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array{name: string, discipline: string, activity?: ?string, is_active?: bool, items: list<array{id?: ?int, checkpoint: string, acceptance_criteria?: ?string}>}  $data
     */
    public function create(array $data): QualityChecklist
    {
        return DB::transaction(function () use ($data) {
            $this->assertUniqueName($data['name']);

            $checklist = new QualityChecklist;
            $checklist->forceFill($this->attributes($data))->save();
            $this->syncItems($checklist, $data['items']);

            return $checklist;
        });
    }

    /**
     * @param  array{name: string, discipline: string, activity?: ?string, is_active?: bool, items: list<array{id?: ?int, checkpoint: string, acceptance_criteria?: ?string}>}  $data
     */
    public function update(QualityChecklist $checklist, array $data): QualityChecklist
    {
        return DB::transaction(function () use ($checklist, $data) {
            $locked = QualityChecklist::query()->whereKey($checklist->id)->lockForUpdate()->firstOrFail();
            $this->assertUniqueName($data['name'], $locked->id);

            $locked->forceFill($this->attributes($data))->save();
            $this->syncItems($locked, $data['items']);

            return $locked;
        });
    }

    public function delete(QualityChecklist $checklist): void
    {
        DB::transaction(function () use ($checklist) {
            $locked = QualityChecklist::query()->whereKey($checklist->id)->lockForUpdate()->firstOrFail();
            if ($locked->isInUse()) {
                throw ValidationException::withMessages(['checklist' => 'This checklist is used by inspections. Deactivate it instead.']);
            }
            $locked->items()->delete();
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'discipline' => $data['discipline'],
            'activity' => filled($data['activity'] ?? null) ? trim($data['activity']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function assertUniqueName(string $name, ?int $ignoreId = null): void
    {
        $exists = QualityChecklist::query()
            ->where('company_id', $this->tenancy->require()->id)
            ->where('name', trim($name))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => 'A checklist with this name already exists.']);
        }
    }

    /**
     * @param  list<array{id?: ?int, checkpoint: string, acceptance_criteria?: ?string}>  $items
     */
    private function syncItems(QualityChecklist $checklist, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Add at least one checkpoint.']);
        }

        $existing = $checklist->items()->get()->keyBy('id');
        $kept = [];

        foreach (array_values($items) as $index => $row) {
            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["items.{$index}.id" => 'This checkpoint does not belong to the checklist.']);
            }

            $item = $id !== null ? $existing->get($id) : new QualityChecklistItem;
            $item->forceFill([
                'quality_checklist_id' => $checklist->id,
                'checkpoint' => trim($row['checkpoint']),
                'acceptance_criteria' => filled($row['acceptance_criteria'] ?? null) ? trim($row['acceptance_criteria']) : null,
                'sort_order' => $index + 1,
            ])->save();
            $kept[] = $item->id;
        }

        $checklist->items()->whereNotIn('id', $kept)->delete();
    }
}
