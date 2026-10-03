<?php

namespace App\Models\Inventory;

use App\Enums\Inventory\StockTxnType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Masters\Material;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One stock movement. Append-only: rows are written only by StockLedgerService and can never be
 * updated or deleted; mistakes are corrected with a 'reversal' row (reverses_id).
 */
class StockTransaction extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = function () {
            throw new LogicException('Stock ledger rows are append-only; post a reversal instead.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    protected function casts(): array
    {
        return [
            'txn_type' => StockTxnType::class,
            'txn_date' => 'date',
            'qty_in' => 'decimal:4',
            'qty_out' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'value' => 'decimal:2',
        ];
    }

    public function isIncoming(): bool
    {
        return Decimal::of($this->qty_in)->isPositive();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /**
     * @return HasOne<self, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
