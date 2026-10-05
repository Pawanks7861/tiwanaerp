<?php

namespace App\Integrations\Tally;

use App\Models\Integrations\TallyConnection;
use App\Models\Integrations\TallyCostCentreMapping;
use App\Models\Integrations\TallyLedgerMapping;

class TallyLedgerMapper
{
    public function name(string $mapKey, string $label): string
    {
        $name = TallyLedgerMapping::query()
            ->where('map_key', $mapKey)
            ->where('active', true)
            ->value('tally_ledger_name');

        if (! is_string($name) || trim($name) === '') {
            throw new MissingTallyMappingException($label);
        }

        return $name;
    }

    public function system(string $key, string $label): string
    {
        return $this->name(TallyMappingCatalog::mapKey($key), $label);
    }

    public function party(string $sourceType, int $sourceId, string $label): string
    {
        return $this->name($sourceType.':'.$sourceId, $label);
    }

    public function costCentre(TallyConnection $connection, ?int $projectId): ?string
    {
        if (! $connection->cost_centres_enabled) {
            return null;
        }
        if (! $projectId) {
            throw new MissingTallyMappingException('Project cost centre');
        }

        $name = TallyCostCentreMapping::query()->where('project_id', $projectId)->value('tally_cost_centre_name');
        if (! is_string($name) || trim($name) === '') {
            throw new MissingTallyMappingException('Project cost centre');
        }

        return $name;
    }
}
