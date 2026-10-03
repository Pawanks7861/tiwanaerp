<?php

namespace App\Models\Finance;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Blameable;
use App\Models\Projects\Project;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cash float held by one person on a project. The balance is never stored: it is the signed sum
 * of the account's ledger rows.
 */
class PettyCashAccount extends Model
{
    use Auditable, BelongsToCompany, Blameable;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'limit_amount' => 'decimal:2',
            'is_active' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /**
     * @return HasMany<PettyCashTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PettyCashTransaction::class);
    }

    public function balance(): Decimal
    {
        $rows = PettyCashTransaction::query()->where('petty_cash_account_id', $this->id)->get(['type', 'amount']);

        return Decimal::sum($rows->map(fn (PettyCashTransaction $t) => $t->type->sign() > 0
            ? Decimal::of($t->amount) : Decimal::of($t->amount)->negate()));
    }
}
