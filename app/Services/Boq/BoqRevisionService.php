<?php

namespace App\Services\Boq;

use App\Enums\Boq\BoqStatus;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the next revision of an approved BOQ (architecture: approved BOQs are immutable).
 * The clone is a new draft with version + 1; every line keeps its line_uid so later modules
 * (planning, progress, billing) can follow a line across revisions.
 */
class BoqRevisionService
{
    public function revise(Boq $boq): Boq
    {
        return DB::transaction(function () use ($boq) {
            $source = Boq::query()->whereKey($boq->id)->lockForUpdate()->firstOrFail();

            if ($source->status !== BoqStatus::Approved || ! $source->is_current) {
                throw ValidationException::withMessages(['boq' => 'Only the current approved BOQ can be revised.']);
            }

            $chain = Boq::query()->withTrashed()
                ->where('project_id', $source->project_id)
                ->where('boq_number', $source->boq_number)
                ->lockForUpdate()
                ->get(['id', 'version', 'status', 'deleted_at']);

            $open = $chain->first(fn (Boq $b) => $b->deleted_at === null
                && in_array($b->status, [BoqStatus::Draft, BoqStatus::Submitted, BoqStatus::Rejected], true));
            if ($open !== null) {
                throw ValidationException::withMessages(['boq' => "Version {$open->version} of this BOQ is still open. Finish or delete it before starting another revision."]);
            }

            $revision = new Boq(['title' => $source->title]);
            $revision->forceFill([
                'project_id' => $source->project_id,
                'boq_number' => $source->boq_number,
                'version' => (int) $chain->max('version') + 1,
                'parent_boq_id' => $source->id,
                'status' => BoqStatus::Draft,
                'is_current' => false,
                'total_cost_amount' => $source->total_cost_amount,
                'total_client_amount' => $source->total_client_amount,
            ])->save();

            $sectionMap = $this->cloneSections($source, $revision);
            $this->cloneItems($source, $revision, $sectionMap);

            return $revision;
        });
    }

    /**
     * @return array<int, int> old section id => new section id
     */
    private function cloneSections(Boq $source, Boq $revision): array
    {
        $map = [];
        $sections = $source->sections()->orderByRaw('parent_id is not null')->orderBy('id')->get();

        foreach ($sections as $section) {
            $copy = $section->replicate(['boq_id', 'parent_id']);
            $copy->boq()->associate($revision);
            $copy->parent_id = $section->parent_id !== null ? $map[$section->parent_id] : null;
            $copy->save();
            $map[$section->id] = $copy->id;
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $sectionMap
     */
    private function cloneItems(Boq $source, Boq $revision, array $sectionMap): void
    {
        $source->items()->orderBy('id')->get()->each(function (BoqItem $item) use ($revision, $sectionMap) {
            $copy = $item->replicate(['boq_id', 'boq_section_id']);
            $copy->boq()->associate($revision);
            $copy->boq_section_id = $sectionMap[$item->boq_section_id];
            $copy->save();
        });
    }
}
