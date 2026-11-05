<?php

namespace App\Services\Boq;

use App\Models\Boq\CostingRateLibraryEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CostingRateLibraryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(int $companyId, array $data, ?CostingRateLibraryEntry $entry = null, ?User $user = null): CostingRateLibraryEntry
    {
        return DB::transaction(function () use ($companyId, $data, $entry, $user) {
            $description = trim((string) ($data['description'] ?? ''));
            if ($description === '') {
                throw ValidationException::withMessages(['description' => 'Description is required.']);
            }

            $entry ??= new CostingRateLibraryEntry;
            $entry->forceFill([
                'company_id' => $companyId,
                'project_id' => $data['project_id'] ?? null,
                'entry_type' => (string) ($data['entry_type'] ?? 'other'),
                'description' => $description,
                'unit' => $data['unit'] ?? null,
                'rate' => (string) ($data['rate'] ?? '0'),
                'effective_from' => $data['effective_from'] ?? null,
                'source' => $data['source'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'updated_by' => $user?->id,
                'created_by' => $entry->created_by ?? $user?->id,
            ])->save();

            return $entry;
        });
    }

    /**
     * @return Collection<int, CostingRateLibraryEntry>
     */
    public function listActive(int $companyId, ?int $projectId = null, ?string $entryType = null): Collection
    {
        return CostingRateLibraryEntry::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->when($projectId !== null, fn ($q) => $q->where(function ($q) use ($projectId) {
                $q->whereNull('project_id')->orWhere('project_id', $projectId);
            }))
            ->when($entryType !== null, fn ($q) => $q->where('entry_type', $entryType))
            ->orderBy('entry_type')
            ->orderBy('description')
            ->get();
    }
}
