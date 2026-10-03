<?php

namespace App\Services\Masters;

use App\Models\Masters\MasterModel;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Masters\MasterDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MasterService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(MasterDefinition $definition, array $data): MasterModel
    {
        return DB::transaction(function () use ($definition, $data) {
            $data = $definition->prepare($data, null);

            if ($definition->numberType() !== null && blank($data['code'] ?? null)) {
                $data['code'] = $this->numbers->next($definition->numberType());
            }

            $modelClass = $definition->model();
            /** @var MasterModel $record */
            $record = new $modelClass;
            $record->fill($data)->save();

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MasterDefinition $definition, MasterModel $record, array $data): MasterModel
    {
        return DB::transaction(function () use ($definition, $record, $data) {
            $record->fill($definition->prepare($data, $record))->save();

            return $record;
        });
    }

    /**
     * Masters referenced by other records cannot be deleted; they are deactivated instead.
     */
    public function delete(MasterDefinition $definition, MasterModel $record): void
    {
        if ($record->isInUse()) {
            throw ValidationException::withMessages([
                'record' => "This {$definition->singular()} is in use and cannot be deleted. Mark it inactive instead.",
            ]);
        }

        $record->delete();
    }
}
