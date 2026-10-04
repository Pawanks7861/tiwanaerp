<?php

namespace App\Services\Inventory;

use App\Models\Core\Company;
use App\Models\Inventory\LowStockAlert;
use App\Notifications\Inventory\InventoryNotification;
use App\Support\Math\Decimal;
use App\Support\Notifications\PermissionRecipients;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Low stock: balance quantity ≤ the item's reorder level (items with a reorder level of 0 are not
 * tracked). One alert per warehouse + material while it stays low; the marker is removed when
 * stock recovers so a later dip alerts again. Recipients: holders of inventory.issue (the store
 * team), limited to the project team for site stores.
 */
class LowStockScanner
{
    public const PERMISSIONS = ['inventory.issue'];

    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly PermissionRecipients $recipients,
    ) {}

    /**
     * @return array{low: int, alerted: int, cleared: int}
     */
    public function scan(Company $company): array
    {
        return $this->tenancy->runAs($company, function () use ($company) {
            $low = DB::table('stock_balances as b')
                ->join('materials as m', 'm.id', '=', 'b.material_id')
                ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
                ->where('b.company_id', $company->id)
                ->where('m.reorder_level', '>', 0)
                ->whereColumn('b.quantity', '<=', 'm.reorder_level')
                ->whereNull('m.deleted_at')->where('m.is_active', true)
                ->whereNull('w.deleted_at')->where('w.is_active', true)
                ->get(['b.warehouse_id', 'b.material_id', 'b.quantity', 'm.reorder_level', 'm.code', 'm.name as material', 'w.name as warehouse', 'w.project_id']);

            $open = LowStockAlert::query()->get()->keyBy(fn (LowStockAlert $a) => "{$a->warehouse_id}:{$a->material_id}");
            $lowKeys = [];
            $alerted = 0;

            foreach ($low as $row) {
                $key = "{$row->warehouse_id}:{$row->material_id}";
                $lowKeys[$key] = true;
                if ($open->has($key)) {
                    continue;
                }

                $alert = new LowStockAlert;
                $alert->forceFill([
                    'warehouse_id' => $row->warehouse_id,
                    'material_id' => $row->material_id,
                    'quantity' => Decimal::of((string) $row->quantity)->toQuantity(),
                    'reorder_level' => Decimal::of((string) $row->reorder_level)->toQuantity(),
                    'alerted_at' => now(),
                ])->save();
                $alerted++;

                Notification::send(
                    $this->recipients->in($company->id, self::PERMISSIONS, $row->project_id ? (int) $row->project_id : null),
                    new InventoryNotification(
                        $company->id,
                        'inventory.low_stock',
                        'Low stock',
                        sprintf('%s (%s) in %s is at %s, at or below its reorder level of %s.', $row->material, $row->code, $row->warehouse,
                            Decimal::of((string) $row->quantity)->toQuantity(), Decimal::of((string) $row->reorder_level)->toQuantity()),
                        $row->project_id ? route('projects.inventory.index', ['project' => $row->project_id, 'warehouse' => $row->warehouse_id, 'low' => 1]) : null,
                        $row->project_id ? ['project_id' => (int) $row->project_id] : [],
                    ),
                );
            }

            $recovered = $open->keys()->reject(fn (string $key) => isset($lowKeys[$key]));
            $cleared = $recovered->isEmpty() ? 0 : LowStockAlert::query()->whereIn('id', $recovered->map(fn ($k) => $open[$k]->id)->all())->delete();

            return ['low' => $low->count(), 'alerted' => $alerted, 'cleared' => $cleared];
        });
    }
}
