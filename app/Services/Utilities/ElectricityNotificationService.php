<?php

namespace App\Services\Utilities;

use App\Models\User;
use App\Models\Utilities\ElectricityBill;
use App\Models\Utilities\ElectricityMeterReading;
use App\Notifications\Utilities\ElectricityAlertNotification;
use Illuminate\Support\Collection;

/**
 * Dispatches electricity events to users with matching permissions (no role-name hard-coding).
 */
final class ElectricityNotificationService
{
    public function readingPending(ElectricityMeterReading $reading): void
    {
        $this->notifyPermission('utilities.electricity.enter_readings', 'reading_pending', 'electricity_meter_reading', $reading->id, [
            'billing_month' => $reading->billing_month?->toDateString(),
            'site_id' => $reading->utility_site_id,
        ]);
    }

    public function billReadyForReview(ElectricityBill $bill): void
    {
        $this->notifyPermission('utilities.electricity.review', 'bill_ready_for_review', 'electricity_bill', $bill->id, [
            'bill_number' => $bill->bill_number,
            'billing_month' => $bill->billing_month?->toDateString(),
        ]);
    }

    public function billApproved(ElectricityBill $bill): void
    {
        $this->notifyPermission('utilities.electricity.view', 'bill_approved', 'electricity_bill', $bill->id, [
            'bill_number' => $bill->bill_number,
        ]);
    }

    public function demandExceeded(ElectricityBill $bill, array $extra = []): void
    {
        $this->notifyPermission('utilities.electricity.view', 'demand_exceeded', 'electricity_bill', $bill->id, $extra);
    }

    public function lowPf(ElectricityBill $bill, array $extra = []): void
    {
        $this->notifyPermission('utilities.electricity.view', 'low_pf', 'electricity_bill', $bill->id, $extra);
    }

    public function billDue(ElectricityBill $bill): void
    {
        $this->notifyPermission('utilities.electricity.view', 'bill_due', 'electricity_bill', $bill->id, [
            'due_date' => $bill->due_date?->toDateString(),
            'bill_number' => $bill->bill_number,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notifyPermission(string $permission, string $event, string $targetType, ?int $targetId, array $payload): void
    {
        $this->recipients($permission)->each(
            fn (User $user) => $user->notify(new ElectricityAlertNotification($event, $targetType, $targetId, $payload))
        );
    }

    /** @return Collection<int, User> */
    private function recipients(string $permission): Collection
    {
        $companyId = app(\App\Support\Tenancy\CurrentCompany::class)->id();

        return User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId))
            ->get()
            ->filter(fn (User $u) => $u->can($permission))
            ->values();
    }
}
