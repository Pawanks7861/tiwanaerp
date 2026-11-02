<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\Contracts\AiTool;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class GetInventoryValuationTool implements AiTool
{
    public function name(): string
    {
        return 'getInventorySummary';
    }

    public function description(): string
    {
        return 'Return a high-level inventory valuation summary when stock balance tables exist and the user may view valuation.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('inventory.view') && (
            $user->can('inventory.view_valuation') || $user->can('dashboard.view_financials')
        );
    }

    public function handle(User $user, array $arguments): array
    {
        if (! $this->authorize($user, $arguments)) {
            return ['error' => 'restricted', 'message' => 'Inventory valuation is restricted for your role.'];
        }

        // Prefer stock_balances if present; otherwise report unsupported.
        if (! Schema::hasTable('stock_balances')) {
            return [
                'supported' => false,
                'message' => 'Inventory valuation summary is not available from a single balance table in this deployment. Open Inventory reports in the ERP.',
            ];
        }

        $qtyCol = Schema::hasColumn('stock_balances', 'quantity') ? 'quantity' : null;
        $valueCol = Schema::hasColumn('stock_balances', 'value')
            ? 'value'
            : (Schema::hasColumn('stock_balances', 'stock_value') ? 'stock_value' : null);

        if ($qtyCol === null) {
            return ['supported' => false, 'message' => 'Stock balance schema is not recognized.'];
        }

        $row = DB::table('stock_balances')->selectRaw(
            'COUNT(*) as lines, SUM('.$qtyCol.') as qty'.($valueCol ? ', SUM('.$valueCol.') as valuation' : '')
        )->first();

        return [
            'supported' => true,
            'balance_lines' => (int) ($row->lines ?? 0),
            'total_quantity' => (string) ($row->qty ?? '0'),
            'valuation' => isset($row->valuation) ? (string) $row->valuation : null,
        ];
    }
}
