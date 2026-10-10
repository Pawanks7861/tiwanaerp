<?php

namespace App\Models\Concerns;

use App\Models\Core\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Writes old/new values to audit_logs on create, update, delete and restore.
 * Models may define `protected array $auditExclude = [...]` for extra excluded attributes.
 */
trait Auditable
{
    /** @var list<string> */
    protected static array $auditAlwaysExcluded = [
        'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by',
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAudit('created', null, $model->auditableAttributes($model->getAttributes()));
        });

        static::updated(function ($model) {
            // In the "updated" event getOriginal() still holds the pre-save values.
            $new = $model->auditableAttributes($model->getChanges());
            if ($new === []) {
                return;
            }
            $old = array_intersect_key($model->auditableAttributes($model->getOriginal()), $new);
            $model->writeAudit('updated', $old, $new);
        });

        static::deleted(function ($model) {
            $model->writeAudit('deleted', $model->auditableAttributes($model->getAttributes()), null);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function ($model) {
                $model->writeAudit('restored', null, null);
            });
        }
    }

    /**
     * Record a business event such as a status change or approval.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function writeAudit(string $event, ?array $old = null, ?array $new = null): void
    {
        app(AuditLogger::class)->record($this, $event, $old, $new);
    }

    /**
     * @return MorphMany<AuditLog, $this>
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        $excluded = array_merge(static::$auditAlwaysExcluded, property_exists($this, 'auditExclude') ? $this->auditExclude : []);

        return array_diff_key($attributes, array_flip($excluded));
    }
}
