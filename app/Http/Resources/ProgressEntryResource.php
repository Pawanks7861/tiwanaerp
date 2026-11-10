<?php

namespace App\Http\Resources;

use App\Models\Planning\ProgressEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProgressEntry
 */
class ProgressEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $dprMeta = $this->dprReference();

        return [
            'id' => $this->id,
            'entry_date' => $this->entry_date?->toDateString(),
            'quantity' => $this->quantity,
            'task_id' => $this->task_id,
            'task' => $this->whenLoaded('task', fn () => $this->task?->only(['id', 'wbs_code', 'name'])),
            'boq_item_id' => $this->boq_item_id,
            'boq_line_uid' => $this->whenLoaded('boqItem', fn () => $this->boqItem?->line_uid),
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'dpr_reference' => $dprMeta,
            'posting_ref' => $this->posting_ref,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{dpr_id: int, item_id: int, revision: int}|null
     */
    private function dprReference(): ?array
    {
        $ref = (string) ($this->posting_ref ?? '');
        if (preg_match('/^dpr:(\d+):r(\d+):item:(\d+)$/', $ref, $m) === 1) {
            return [
                'dpr_id' => (int) $m[1],
                'revision' => (int) $m[2],
                'item_id' => (int) $m[3],
            ];
        }

        if ($this->relationLoaded('source') && $this->source_type === 'dpr_item' && $this->source) {
            $item = $this->source;

            return [
                'dpr_id' => (int) ($item->dpr_id ?? 0),
                'revision' => 0,
                'item_id' => (int) $item->id,
            ];
        }

        return null;
    }
}
