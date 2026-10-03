<?php

namespace App\Services\Crm;

use App\Enums\Crm\LeadActivityType;
use App\Enums\Crm\LeadStatus;
use App\Models\Crm\Client;
use App\Models\Crm\Lead;
use App\Models\Crm\LeadActivity;
use App\Models\User;
use App\Services\Numbering\DocumentNumberService;
use App\Services\Resources\Concerns\ResolvesProjectRefs;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leads (LEAD-YYYY-0001) and their activity log. Status moves freely between the open stages;
 * lost needs a reason; quoted and won are also set by the quotation flow (sent / accepted).
 * Assignees must be active members of the same company.
 */
class LeadService
{
    use ResolvesProjectRefs;

    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Lead
    {
        return DB::transaction(function () use ($data) {
            $lead = new Lead;
            $lead->forceFill([
                'lead_number' => $this->numbers->next('lead'),
                'status' => LeadStatus::New,
                ...$this->attributes($data),
            ])->save();

            return $lead;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Lead $lead, array $data): Lead
    {
        return DB::transaction(function () use ($lead, $data) {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill($this->attributes($data));
            if (array_key_exists('status', $data) && filled($data['status'])) {
                $this->applyStatus($locked, LeadStatus::from((string) $data['status']), $data['lost_reason'] ?? null);
            }
            $locked->save();

            return $locked;
        });
    }

    public function changeStatus(Lead $lead, LeadStatus $status, ?string $reason): Lead
    {
        return DB::transaction(function () use ($lead, $status, $reason) {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $this->applyStatus($locked, $status, $reason);
            $locked->save();

            return $locked;
        });
    }

    public function delete(Lead $lead): void
    {
        DB::transaction(function () use ($lead) {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($locked->quotations()->withTrashed()->exists()) {
                throw ValidationException::withMessages(['lead' => 'This lead has quotations and cannot be deleted. Mark it lost instead.']);
            }
            $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data  type, activity_at, summary, next_follow_up
     */
    public function addActivity(Lead $lead, array $data, User $user): LeadActivity
    {
        return DB::transaction(function () use ($lead, $data, $user) {
            $locked = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            $type = LeadActivityType::tryFrom((string) ($data['type'] ?? ''))
                ?? throw ValidationException::withMessages(['type' => 'Choose call, visit, email or note.']);

            $activity = new LeadActivity;
            $activity->forceFill([
                'company_id' => $locked->company_id,
                'lead_id' => $locked->id,
                'type' => $type,
                'activity_at' => $data['activity_at'],
                'summary' => trim((string) $data['summary']),
                'next_follow_up' => $data['next_follow_up'] ?? null,
                'created_by' => $user->id,
            ])->save();

            if ($locked->status === LeadStatus::New && $type !== LeadActivityType::Note) {
                $locked->forceFill(['status' => LeadStatus::Contacted])->save();
            }

            return $activity;
        });
    }

    /** Called when a quotation for the lead is sent. */
    public function markQuoted(?int $leadId): void
    {
        if ($leadId === null) {
            return;
        }
        $lead = Lead::query()->whereKey($leadId)->lockForUpdate()->first();
        if ($lead !== null && in_array($lead->status, [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified], true)) {
            $lead->forceFill(['status' => LeadStatus::Quoted])->save();
        }
    }

    /** Called when a quotation for the lead is accepted. */
    public function markWon(?int $leadId, ?int $clientId): void
    {
        if ($leadId === null) {
            return;
        }
        $lead = Lead::query()->whereKey($leadId)->lockForUpdate()->first();
        if ($lead === null) {
            return;
        }
        $lead->forceFill(['status' => LeadStatus::Won, 'lost_reason' => null, 'client_id' => $lead->client_id ?? $clientId])->save();
    }

    private function applyStatus(Lead $lead, LeadStatus $status, ?string $reason): void
    {
        $reason = trim((string) $reason);
        if ($status === LeadStatus::Lost && mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['lost_reason' => 'Give the reason the lead was lost.']);
        }
        if ($status === LeadStatus::Won && $lead->status !== LeadStatus::Won) {
            throw ValidationException::withMessages(['status' => 'A lead is won when its quotation is accepted.']);
        }

        $lead->forceFill([
            'status' => $status,
            'lost_reason' => $status === LeadStatus::Lost ? mb_substr($reason, 0, 500) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $assignee = null;
        if (filled($data['assigned_to'] ?? null)) {
            $companyId = app(CurrentCompany::class)->id();
            $assignee = User::query()->whereKey((int) $data['assigned_to'])->where('is_active', true)
                ->whereHas('memberships', fn ($m) => $m->where('company_id', $companyId)->where('is_active', true))
                ->value('id') ?? throw ValidationException::withMessages(['assigned_to' => 'Assign the lead to an active member of this company.']);
        }
        $clientId = null;
        if (filled($data['client_id'] ?? null)) {
            $clientId = Client::query()->whereKey((int) $data['client_id'])->value('id')
                ?? throw ValidationException::withMessages(['client_id' => 'Choose a client of this company.']);
        }

        return [
            'name' => trim((string) $data['name']),
            'company_name' => $data['company_name'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'source' => $data['source'] ?? null,
            'project_type' => $data['project_type'] ?? null,
            'location' => $data['location'] ?? null,
            'state_code' => $data['state_code'] ?? null,
            'estimated_value' => $this->amount($data['estimated_value'] ?? null, 'estimated_value')->toMoney(),
            'expected_close_date' => $data['expected_close_date'] ?? null,
            'assigned_to' => $assignee,
            'client_id' => $clientId,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
