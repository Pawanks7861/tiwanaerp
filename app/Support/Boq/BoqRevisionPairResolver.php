<?php

namespace App\Support\Boq;

use App\Models\Boq\Boq;
use App\Models\Projects\Project;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Resolves from/to BOQ revisions for compare routes (web, API, PDF).
 */
final class BoqRevisionPairResolver
{
    /**
     * @return array{0: Boq, 1: Boq}
     */
    public function resolve(Project $project, Boq $boq, Request $request): array
    {
        $data = $request->validate([
            'previous_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'integer', 'min:1'],
            'from_revision' => ['nullable', 'integer', 'min:1'],
            'to' => ['nullable', 'integer', 'min:1'],
            'to_revision' => ['nullable', 'integer', 'min:1'],
        ]);

        $fromId = (int) ($data['from'] ?? $data['from_revision'] ?? $data['previous_id'] ?? 0);
        $toId = (int) ($data['to'] ?? $data['to_revision'] ?? $boq->id);

        if ($fromId === 0) {
            $fromId = (int) ($boq->parent_boq_id ?? 0);
            if ($fromId === 0) {
                $prior = Boq::query()
                    ->where('project_id', $project->id)
                    ->where('boq_number', $boq->boq_number)
                    ->where('version', '<', $boq->version)
                    ->orderByDesc('version')
                    ->first();
                $fromId = (int) ($prior?->id ?? 0);
            }
        }

        if ($fromId === 0) {
            throw ValidationException::withMessages(['from' => 'No previous revision found to compare against.']);
        }

        $from = $this->findRevision($project, $boq->boq_number, $fromId);
        $to = $this->findRevision($project, $boq->boq_number, $toId);

        return [$from, $to];
    }

    private function findRevision(Project $project, string $boqNumber, int $id): Boq
    {
        return Boq::query()
            ->where('project_id', $project->id)
            ->where('boq_number', $boqNumber)
            ->whereKey($id)
            ->firstOrFail();
    }
}
