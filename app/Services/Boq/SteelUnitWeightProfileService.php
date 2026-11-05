<?php

namespace App\Services\Boq;

use App\Enums\Boq\MaterialRecipeStatus;
use App\Enums\Boq\SteelUnitWeightMethod;
use App\Models\Boq\SteelUnitWeightProfile;
use App\Models\Boq\SteelUnitWeightProfileLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SteelUnitWeightProfileService
{
    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{diameter_mm: int, weight_kg_per_m: string}>  $lines
     */
    public function save(int $companyId, array $header, array $lines, User $user, ?SteelUnitWeightProfile $profile = null): SteelUnitWeightProfile
    {
        return DB::transaction(function () use ($companyId, $header, $lines, $profile, $user) {
            if ($profile !== null) {
                $profile = SteelUnitWeightProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
                $profile->assertDraft();
            } else {
                $profile = new SteelUnitWeightProfile;
            }

            $name = trim((string) ($header['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages(['name' => 'Profile name is required.']);
            }

            if (! $profile->exists) {
                $version = (int) SteelUnitWeightProfile::query()
                    ->where('company_id', $companyId)
                    ->where('name', $name)
                    ->max('version') + 1;

                $profile->forceFill([
                    'company_id' => $companyId,
                    'project_id' => $header['project_id'] ?? null,
                    'name' => $name,
                    'method' => SteelUnitWeightMethod::Profile,
                    'version' => max(1, $version),
                    'status' => MaterialRecipeStatus::Draft,
                    'created_by' => $user->id,
                ]);
            }

            $profile->forceFill([
                'notes' => $header['notes'] ?? $profile->notes,
                'updated_by' => $user->id,
            ])->save();

            $profile->lines()->delete();
            $seen = [];
            foreach (array_values($lines) as $i => $row) {
                $dia = (int) ($row['diameter_mm'] ?? 0);
                if ($dia < 1) {
                    throw ValidationException::withMessages(["lines.{$i}.diameter_mm" => 'Diameter is required.']);
                }
                if (isset($seen[$dia])) {
                    throw ValidationException::withMessages(["lines.{$i}.diameter_mm" => 'Duplicate diameter in profile.']);
                }
                $seen[$dia] = true;

                $line = new SteelUnitWeightProfileLine;
                $line->forceFill([
                    'profile_id' => $profile->id,
                    'diameter_mm' => $dia,
                    'weight_kg_per_m' => (string) ($row['weight_kg_per_m'] ?? '0'),
                ])->save();
            }

            return $profile->fresh('lines');
        });
    }

    public function activate(SteelUnitWeightProfile $profile, User $user): SteelUnitWeightProfile
    {
        return DB::transaction(function () use ($profile, $user) {
            $locked = SteelUnitWeightProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($locked->lines()->count() === 0) {
                throw ValidationException::withMessages(['steel_profile' => 'Add at least one diameter line before activating.']);
            }

            SteelUnitWeightProfile::query()
                ->where('company_id', $locked->company_id)
                ->where('name', $locked->name)
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

            return $locked->fresh('lines');
        });
    }

    /**
     * @return array<int, string>  diameter_mm => kg/m
     */
    public function diameterWeightMap(SteelUnitWeightProfile $profile): array
    {
        $profile->loadMissing('lines');
        $map = [];
        foreach ($profile->lines as $line) {
            $map[(int) $line->diameter_mm] = (string) $line->weight_kg_per_m;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    public function profileSnapshot(SteelUnitWeightProfile $profile): array
    {
        return [
            'profile_id' => $profile->id,
            'name' => $profile->name,
            'method' => $profile->method?->value ?? SteelUnitWeightMethod::Profile->value,
            'version' => $profile->version,
            'weights' => $this->diameterWeightMap($profile),
        ];
    }
}
