<?php

namespace App\Console\Commands;

use App\Enums\Gate\GatePassStatus;
use App\Models\Gate\GatePass;
use App\Models\Gate\GatePassItem;
use App\Models\Gate\GatePassReturnItem;
use App\Enums\Gate\GatePassReturnStatus;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Services\Gate\GatePassReturnService;
use App\Support\Math\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class NotifyGatePassOverdueCommand extends Command
{
    protected $signature = 'gate-passes:notify-overdue';

    protected $description = 'Notify for returnable gate passes past expected return with pending quantity';

    public function handle(GatePassReturnService $returns): int
    {
        $today = now()->toDateString();
        $count = 0;

        GatePass::query()
            ->where('returnable', true)
            ->whereNotNull('expected_return_date')
            ->whereDate('expected_return_date', '<', $today)
            ->whereIn('status', [GatePassStatus::Verified->value, GatePassStatus::Approved->value])
            ->with(['items', 'project:id,company_id,project_manager_id'])
            ->orderBy('id')
            ->chunkById(100, function ($passes) use ($returns, $today, &$count) {
                foreach ($passes as $pass) {
                    $pending = false;
                    foreach ($pass->items as $item) {
                        if ($returns->pendingQuantity($item)->isPositive()) {
                            $pending = true;
                            break;
                        }
                    }
                    if (! $pending) {
                        continue;
                    }

                    $inserted = DB::table('gate_pass_overdue_alerts')->insertOrIgnore([
                        'gate_pass_id' => $pass->id,
                        'alert_date' => $today,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if (! $inserted) {
                        continue;
                    }

                    $recipients = User::query()
                        ->where('is_active', true)
                        ->whereHas('memberships', fn ($m) => $m->where('company_id', $pass->company_id)->where('is_active', true))
                        ->where(function ($q) use ($pass) {
                            $q->whereKey($pass->created_by)
                                ->orWhereKey($pass->project?->project_manager_id)
                                ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['Store Incharge', 'Store Manager', 'Project Manager']));
                        })
                        ->get()
                        ->unique('id');

                    if ($recipients->isEmpty()) {
                        continue;
                    }

                    Notification::send($recipients, new GeneralNotification(
                        (int) $pass->company_id,
                        'gate_pass.overdue_return',
                        'Gate pass return overdue',
                        "{$pass->pass_number} expected return {$pass->expected_return_date?->toDateString()} is overdue.",
                        route('projects.gate-passes.show', [$pass->project_id, $pass->id], absolute: false),
                        [
                            'project_id' => (int) $pass->project_id,
                            'target_type' => 'gate_pass',
                            'target_id' => (int) $pass->id,
                        ],
                    ));
                    $count++;
                }
            });

        $this->info("Overdue gate pass alerts sent: {$count}");

        return self::SUCCESS;
    }
}
