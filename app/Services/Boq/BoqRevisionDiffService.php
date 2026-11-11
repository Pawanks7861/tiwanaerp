<?php

namespace App\Services\Boq;

use App\Models\Boq\Boq;
use App\Models\Boq\BoqItem;
use App\Support\Math\Decimal;

/**
 * Compares two BOQ revisions by stable line_uid (never by description alone).
 */
class BoqRevisionDiffService
{
    /**
     * @return array{
     *   previous: array{id:int,version:int,total_client_amount:string,total_cost_amount:?string},
     *   current: array{id:int,version:int,total_client_amount:string,total_cost_amount:?string},
     *   summary: array<string,int>,
     *   changes: list<array<string,mixed>>
     * }
     */
    public function compare(Boq $previous, Boq $current, bool $withCosts = false): array
    {
        $prevItems = BoqItem::query()->where('boq_id', $previous->id)->orderBy('sort_order')->orderBy('id')->get()->keyBy('line_uid');
        $currItems = BoqItem::query()->where('boq_id', $current->id)->orderBy('sort_order')->orderBy('id')->get()->keyBy('line_uid');

        $uids = $prevItems->keys()->merge($currItems->keys())->unique()->values();
        $changes = [];
        $summary = [
            'added' => 0,
            'removed' => 0,
            'quantity_changed' => 0,
            'rate_changed' => 0,
            'description_changed' => 0,
            'labour_rate_changed' => 0,
            'material_rate_changed' => 0,
            'unchanged' => 0,
        ];

        foreach ($uids as $uid) {
            $before = $prevItems->get($uid);
            $after = $currItems->get($uid);

            if ($before === null && $after !== null) {
                $summary['added']++;
                $changes[] = $this->row('added', $uid, null, $after, $withCosts);
                continue;
            }
            if ($before !== null && $after === null) {
                $summary['removed']++;
                $changes[] = $this->row('removed', $uid, $before, null, $withCosts);
                continue;
            }

            $fields = [];
            if (! Decimal::of((string) $before->quantity)->equals((string) $after->quantity)) {
                $fields['quantity'] = ['from' => (string) $before->quantity, 'to' => (string) $after->quantity];
                $summary['quantity_changed']++;
            }
            if (! Decimal::of((string) $before->client_rate)->equals((string) $after->client_rate)) {
                $fields['client_rate'] = ['from' => (string) $before->client_rate, 'to' => (string) $after->client_rate];
                $summary['rate_changed']++;
            }
            if (trim((string) $before->name) !== trim((string) $after->name)
                || trim((string) ($before->description ?? '')) !== trim((string) ($after->description ?? ''))) {
                $fields['description'] = [
                    'from' => trim(($before->item_code ? $before->item_code.' ' : '').$before->name),
                    'to' => trim(($after->item_code ? $after->item_code.' ' : '').$after->name),
                ];
                $summary['description_changed']++;
            }
            if ($withCosts && ! Decimal::of((string) ($before->labour_rate ?? '0'))->equals((string) ($after->labour_rate ?? '0'))) {
                $fields['labour_rate'] = ['from' => (string) ($before->labour_rate ?? '0'), 'to' => (string) ($after->labour_rate ?? '0')];
                $summary['labour_rate_changed']++;
            }
            if ($withCosts && ! Decimal::of((string) ($before->material_rate ?? '0'))->equals((string) ($after->material_rate ?? '0'))) {
                $fields['material_rate'] = ['from' => (string) ($before->material_rate ?? '0'), 'to' => (string) ($after->material_rate ?? '0')];
                $summary['material_rate_changed']++;
            }

            if ($fields === []) {
                $summary['unchanged']++;
                continue;
            }

            $changes[] = $this->row('changed', $uid, $before, $after, $withCosts, $fields);
        }

        $amountChanged = ! Decimal::of((string) $previous->total_client_amount)->equals((string) $current->total_client_amount);

        return [
            'previous' => [
                'id' => $previous->id,
                'version' => (int) $previous->version,
                'total_client_amount' => (string) $previous->total_client_amount,
                'total_cost_amount' => $withCosts ? (string) $previous->total_cost_amount : null,
            ],
            'current' => [
                'id' => $current->id,
                'version' => (int) $current->version,
                'total_client_amount' => (string) $current->total_client_amount,
                'total_cost_amount' => $withCosts ? (string) $current->total_cost_amount : null,
            ],
            'total_amount_changed' => $amountChanged,
            'summary' => $summary,
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, array{from:string,to:string}>  $fields
     * @return array<string, mixed>
     */
    private function row(string $kind, string $uid, ?BoqItem $before, ?BoqItem $after, bool $withCosts, array $fields = []): array
    {
        $item = $after ?? $before;

        return [
            'kind' => $kind,
            'line_uid' => $uid,
            'item_code' => $item?->item_code,
            'name' => $item?->name,
            'fields' => $fields,
            'before' => $before ? $this->snapshot($before, $withCosts) : null,
            'after' => $after ? $this->snapshot($after, $withCosts) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(BoqItem $item, bool $withCosts): array
    {
        $data = [
            'id' => $item->id,
            'item_code' => $item->item_code,
            'name' => $item->name,
            'description' => $item->description,
            'quantity' => (string) $item->quantity,
            'client_rate' => (string) $item->client_rate,
            'client_amount' => (string) $item->client_amount,
        ];
        if ($withCosts) {
            $data['labour_rate'] = (string) ($item->labour_rate ?? '0');
            $data['material_rate'] = (string) ($item->material_rate ?? '0');
        }

        return $data;
    }
}
