<?php

namespace App\Queries\Reports;

use App\Support\Reports\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Settlement sub-queries shared by receivables and payables: allocations of approved payments
 * and approved retention releases, each up to an as-of date. Cached received / paid amounts on
 * the documents are never used as the source.
 */
final class Settlements
{
    public static function allocated(int $companyId, string $payableType, string $asOf): Builder
    {
        return DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->where('p.company_id', $companyId)
            ->where('p.status', 'approved')
            ->whereNull('p.deleted_at')
            ->where('p.payment_date', '<=', Sql::eod($asOf))
            ->where('pa.payable_type', $payableType)
            ->groupBy('pa.payable_id')
            ->selectRaw('pa.payable_id, SUM(pa.amount) as settled');
    }

    public static function released(int $companyId, string $releasableType, string $asOf): Builder
    {
        return DB::table('retention_releases')
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->whereNull('deleted_at')
            ->where('release_date', '<=', Sql::eod($asOf))
            ->where('releasable_type', $releasableType)
            ->groupBy('releasable_id')
            ->selectRaw('releasable_id, SUM(amount) as released');
    }

    /** SQL for the four aging buckets over a derived table with age / outstanding columns. */
    public static function bucketSelect(): string
    {
        return 'SUM(CASE WHEN age <= 30 THEN outstanding ELSE 0 END) as b0_30,
            SUM(CASE WHEN age > 30 AND age <= 60 THEN outstanding ELSE 0 END) as b31_60,
            SUM(CASE WHEN age > 60 AND age <= 90 THEN outstanding ELSE 0 END) as b61_90,
            SUM(CASE WHEN age > 90 THEN outstanding ELSE 0 END) as b90_plus';
    }

    public static function bucket(int $age): string
    {
        return match (true) {
            $age <= 30 => '0–30 days',
            $age <= 60 => '31–60 days',
            $age <= 90 => '61–90 days',
            default => '90+ days',
        };
    }
}
