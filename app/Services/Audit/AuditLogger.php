<?php

namespace App\Services\Audit;

use App\Models\Core\AuditLog;
use App\Models\Core\Company;
use App\Models\Core\Role;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(Model $model, string $event, ?array $old = null, ?array $new = null): AuditLog
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::create([
            'company_id' => $this->companyIdFor($model),
            'user_id' => Auth::id(),
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'url' => $request?->fullUrl(),
        ]);
    }

    /**
     * Tenant models carry company_id; companies and roles map to themselves / their team;
     * global models (users) fall back to the company the change was made in.
     */
    private function companyIdFor(Model $model): ?int
    {
        $attributes = $model->getAttributes();

        $id = match (true) {
            $model instanceof Company => $model->getKey(),
            $model instanceof Role => $attributes['team_id'] ?? null,
            array_key_exists('company_id', $attributes) => $attributes['company_id'],
            default => null,
        };

        return $id !== null ? (int) $id : $this->tenancy->id();
    }
}
