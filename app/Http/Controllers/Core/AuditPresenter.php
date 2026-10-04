<?php

namespace App\Http\Controllers\Core;

use App\Models\Core\AuditLog;
use App\Services\Audit\AuditValueFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Audit history of one record (and optionally its child records) for detail pages, newest first.
 */
class AuditPresenter
{
    /**
     * @param  iterable<Model>  $subjects
     * @param  array<string, string>  $subjectLabels  morph alias => label shown next to child events
     * @return list<array<string, mixed>>
     */
    public static function trail(iterable $subjects, array $subjectLabels = [], int $limit = 100): array
    {
        $byType = [];
        $names = [];
        foreach ($subjects as $subject) {
            $byType[$subject->getMorphClass()][] = $subject->getKey();
            $names[$subject->getMorphClass().':'.$subject->getKey()] = self::subjectName($subject);
        }
        if ($byType === []) {
            return [];
        }

        return AuditLog::query()->forCurrentCompany()
            ->where(function (Builder $q) use ($byType) {
                foreach ($byType as $type => $ids) {
                    $q->orWhere(fn (Builder $w) => $w->where('auditable_type', $type)->whereIn('auditable_id', $ids));
                }
            })
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'event_label' => Str::of($log->event)->replace('_', ' ')->headline()->toString(),
                'subject' => isset($subjectLabels[$log->auditable_type])
                    ? trim($subjectLabels[$log->auditable_type].' '.($names[$log->auditable_type.':'.$log->auditable_id] ?? ''))
                    : null,
                'user' => $log->user?->name,
                'at' => $log->created_at?->toIso8601String(),
                'changes' => app(AuditValueFormatter::class)->changes($log->old_values ?? [], $log->new_values ?? []),
            ])
            ->values()
            ->all();
    }

    private static function subjectName(Model $subject): string
    {
        $attributes = $subject->getAttributes();

        return match (true) {
            isset($attributes['revision_code']) => (string) $attributes['revision_code'],
            isset($attributes['version_no']) => 'v'.$attributes['version_no'],
            default => '',
        };
    }
}
