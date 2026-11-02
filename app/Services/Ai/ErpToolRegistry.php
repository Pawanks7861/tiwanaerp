<?php

namespace App\Services\Ai;

use App\Models\User;
use App\Services\Ai\Contracts\AiTool;
use App\Services\Ai\Tools\GetBoqSummaryTool;
use App\Services\Ai\Tools\GetDrawingApprovalSummaryTool;
use App\Services\Ai\Tools\GetExpenseSummaryTool;
use App\Services\Ai\Tools\GetInventoryValuationTool;
use App\Services\Ai\Tools\GetModuleHelpTool;
use App\Services\Ai\Tools\GetProjectSummaryTool;
use App\Services\Ai\Tools\GetPurchaseOrderSummaryTool;
use App\Services\Ai\Tools\GetVendorOutstandingTool;
use Illuminate\Support\Facades\Log;

class ErpToolRegistry
{
    /**
     * Allowlisted read-only analytics / help tools.
     *
     * @return list<AiTool>
     */
    public function tools(): array
    {
        return [
            app(GetModuleHelpTool::class),
            app(GetPurchaseOrderSummaryTool::class),
            app(GetDrawingApprovalSummaryTool::class),
            app(GetExpenseSummaryTool::class),
            app(GetInventoryValuationTool::class),
            app(GetBoqSummaryTool::class),
            app(GetVendorOutstandingTool::class),
            app(GetProjectSummaryTool::class),
        ];
    }

    public function find(string $name): ?AiTool
    {
        foreach ($this->tools() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(User $user, string $name, array $arguments): array
    {
        $tool = $this->find($name);
        if ($tool === null) {
            return ['error' => 'unknown_tool', 'message' => 'That data query is not available.'];
        }

        if (! $tool->authorize($user, $arguments)) {
            Log::info('smart_assistant.tool.denied', ['tool' => $name, 'user_id' => $user->id]);

            return ['error' => 'restricted', 'message' => 'You are not allowed to access this information.'];
        }

        $allowed = array_keys($tool->parametersSchema()['properties'] ?? []);
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $arguments)) {
                $clean[$key] = $arguments[$key];
            }
        }

        return $tool->handle($user, $clean);
    }
}
