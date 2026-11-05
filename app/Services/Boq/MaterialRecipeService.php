<?php

namespace App\Services\Boq;

use App\Enums\Boq\MaterialRecipeStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Models\Boq\BoqMaterialNorm;
use App\Models\Boq\MaterialRecipeTemplate;
use App\Models\Boq\MaterialRecipeTemplateLine;
use App\Models\Masters\Material;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialRecipeService
{
    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(int $companyId, array $header, array $lines, User $user): MaterialRecipeTemplate
    {
        return DB::transaction(function () use ($companyId, $header, $lines, $user) {
            $code = trim((string) ($header['code'] ?? ''));
            if ($code === '') {
                throw ValidationException::withMessages(['code' => 'Recipe code is required.']);
            }

            if (MaterialRecipeTemplate::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->where('version', 1)
                ->exists()) {
                throw ValidationException::withMessages(['code' => 'Recipe code already exists.']);
            }

            $projectId = array_key_exists('project_id', $header)
                ? ($header['project_id'] !== null && $header['project_id'] !== '' ? (int) $header['project_id'] : null)
                : null;

            $recipe = new MaterialRecipeTemplate;
            $recipe->forceFill([
                'company_id' => $companyId,
                'project_id' => $projectId,
                'code' => $code,
                'name' => trim((string) ($header['name'] ?? $code)),
                'description' => $header['description'] ?? null,
                'version' => 1,
                'status' => MaterialRecipeStatus::Draft,
                'notes' => $header['notes'] ?? null,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();

            $this->replaceLines($recipe, $lines);

            return $recipe->fresh(['lines.material']);
        });
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    public function updateDraft(MaterialRecipeTemplate $recipe, array $header, array $lines, User $user): MaterialRecipeTemplate
    {
        return DB::transaction(function () use ($recipe, $header, $lines, $user) {
            $locked = MaterialRecipeTemplate::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            $locked->assertDraft();

            $locked->forceFill([
                'name' => trim((string) ($header['name'] ?? $locked->name)),
                'description' => $header['description'] ?? $locked->description,
                'notes' => $header['notes'] ?? $locked->notes,
                'effective_from' => $header['effective_from'] ?? $locked->effective_from,
                'effective_to' => $header['effective_to'] ?? $locked->effective_to,
                'updated_by' => $user->id,
            ])->save();

            $this->replaceLines($locked, $lines);

            return $locked->fresh(['lines.material']);
        });
    }

    public function activate(MaterialRecipeTemplate $recipe, User $user): MaterialRecipeTemplate
    {
        return DB::transaction(function () use ($recipe, $user) {
            $locked = MaterialRecipeTemplate::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            if ($locked->lines()->count() === 0) {
                throw ValidationException::withMessages(['recipe' => 'Add at least one recipe line before activating.']);
            }

            MaterialRecipeTemplate::query()
                ->where('company_id', $locked->company_id)
                ->where('code', $locked->code)
                ->where('status', MaterialRecipeStatus::Active)
                ->whereKeyNot($locked->id)
                ->update([
                    'status' => MaterialRecipeStatus::Inactive->value,
                    'updated_by' => $user->id,
                ]);

            $locked->forceFill([
                'status' => MaterialRecipeStatus::Active,
                'updated_by' => $user->id,
            ])->save();

            return $locked->fresh(['lines.material']);
        });
    }

    public function createNewVersion(MaterialRecipeTemplate $recipe, User $user): MaterialRecipeTemplate
    {
        return DB::transaction(function () use ($recipe, $user) {
            $source = MaterialRecipeTemplate::query()->whereKey($recipe->id)->lockForUpdate()->firstOrFail();
            $nextVersion = (int) MaterialRecipeTemplate::query()
                ->where('company_id', $source->company_id)
                ->where('code', $source->code)
                ->max('version') + 1;

            $draft = new MaterialRecipeTemplate;
            $draft->forceFill([
                'company_id' => $source->company_id,
                'project_id' => $source->project_id,
                'code' => $source->code,
                'name' => $source->name,
                'description' => $source->description,
                'version' => $nextVersion,
                'status' => MaterialRecipeStatus::Draft,
                'notes' => $source->notes,
                'supersedes_id' => $source->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ])->save();

            $source->load('lines');
            foreach ($source->lines as $line) {
                $copy = new MaterialRecipeTemplateLine;
                $copy->forceFill([
                    'recipe_id' => $draft->id,
                    'material_id' => $line->material_id,
                    'consumption_qty' => $line->consumption_qty,
                    'consumption_unit' => $line->consumption_unit,
                    'wastage_percent' => $line->wastage_percent,
                    'sequence' => $line->sequence,
                    'notes' => $line->notes,
                ])->save();
            }

            return $draft->fresh(['lines.material']);
        });
    }

    public function applyToBoqItem(BoqItem $item, MaterialRecipeTemplate $recipe, User $user): BoqItem
    {
        $item->loadMissing('boq');
        $item->boq->assertEditable();

        return DB::transaction(function () use ($item, $recipe, $user) {
            $lockedItem = BoqItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $lockedBoq = Boq::query()->whereKey($lockedItem->boq_id)->lockForUpdate()->firstOrFail();
            $lockedBoq->assertEditable();

            $template = MaterialRecipeTemplate::query()
                ->whereKey($recipe->id)
                ->where('status', MaterialRecipeStatus::Active)
                ->with('lines')
                ->firstOrFail();

            if ((int) $template->company_id !== (int) $lockedBoq->company_id) {
                throw ValidationException::withMessages(['recipe' => 'Recipe must belong to this company.']);
            }

            BoqMaterialNorm::query()->where('boq_item_id', $lockedItem->id)->delete();

            $sequence = 0;
            foreach ($template->lines as $line) {
                Material::query()
                    ->whereKey($line->material_id)
                    ->where('company_id', $lockedBoq->company_id)
                    ->firstOrFail();

                $norm = new BoqMaterialNorm;
                $norm->forceFill([
                    'company_id' => $lockedBoq->company_id,
                    'project_id' => $lockedBoq->project_id,
                    'boq_id' => $lockedBoq->id,
                    'boq_item_id' => $lockedItem->id,
                    'boq_line_uid' => $lockedItem->line_uid,
                    'material_id' => $line->material_id,
                    'consumption_qty' => $line->consumption_qty,
                    'consumption_unit' => $line->consumption_unit,
                    'wastage_percent' => $line->wastage_percent ?? '0.0000',
                    'recipe_template_id' => $template->id,
                    'recipe_version' => $template->version,
                    'recipe_code' => $template->code,
                    'notes' => $line->notes,
                    'sequence' => $sequence++,
                    'is_active' => true,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ])->save();
            }

            return $lockedItem->fresh(['boq']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(MaterialRecipeTemplate $recipe, array $lines): void
    {
        $recipe->lines()->delete();
        $sequence = 0;
        foreach (array_values($lines) as $i => $row) {
            $materialId = (int) ($row['material_id'] ?? 0);
            Material::query()
                ->whereKey($materialId)
                ->where('company_id', $recipe->company_id)
                ->firstOr(fn () => throw ValidationException::withMessages(["lines.{$i}.material_id" => 'Material not found in this company.']));

            $unit = trim((string) ($row['consumption_unit'] ?? ''));
            if ($unit === '') {
                throw ValidationException::withMessages(["lines.{$i}.consumption_unit" => 'Consumption unit is required.']);
            }

            $calc = MaterialNormCalculator::line([
                'boq_qty' => '1',
                'consumption_qty' => $row['consumption_qty'] ?? '0',
                'wastage_percent' => $row['wastage_percent'] ?? '0',
            ]);

            $line = new MaterialRecipeTemplateLine;
            $line->forceFill([
                'recipe_id' => $recipe->id,
                'material_id' => $materialId,
                'consumption_qty' => $calc['consumption_qty'],
                'consumption_unit' => $unit,
                'wastage_percent' => $calc['wastage_percent'],
                'sequence' => $sequence++,
                'notes' => isset($row['notes']) ? (trim((string) $row['notes']) ?: null) : null,
            ])->save();
        }
    }
}
