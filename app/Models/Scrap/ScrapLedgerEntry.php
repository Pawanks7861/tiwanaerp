<?php

namespace App\Models\Scrap;

use App\Enums\Scrap\ScrapLedgerTxnType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Masters\ScrapType;
use App\Models\Masters\Unit;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only scrap ledger. Corrections use compensating reversal rows, not updates.
 */
class ScrapLedgerEntry extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'transaction_type' => ScrapLedgerTxnType::class,
            'transaction_date' => 'date',
            'quantity_in' => 'decimal:4',
            'quantity_out' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ScrapType, $this>
     */
    public function scrapType(): BelongsTo
    {
        return $this->belongsTo(ScrapType::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isIncoming(): bool
    {
        return $this->transaction_type->isIncoming();
    }
}
