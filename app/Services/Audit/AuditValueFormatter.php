<?php

namespace App\Services\Audit;

use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Turns stored audit values into labels for the screen. Rows in audit_logs are never read back
 * for writing: masking and name lookup happen only on the way out.
 *
 * Mask list: secrets and identity / bank numbers. Everything else is allowed through. Foreign
 * keys in the lookup list are shown as the related record's name inside the current company.
 */
class AuditValueFormatter
{
    /** @var list<string> */
    private const MASKED = [
        'password', 'remember_token', 'bank_account_no', 'bank_ifsc', 'pan', 'id_proof_number', 'aadhaar', 'api_token',
    ];

    /** @var array<string, array{0: string, 1: string}> column => [table, label column] */
    private const LOOKUPS = [
        'project_id' => ['projects', 'code'],
        'client_id' => ['clients', 'company_name'],
        'vendor_id' => ['vendors', 'name'],
        'subcontractor_id' => ['subcontractors', 'name'],
        'warehouse_id' => ['warehouses', 'name'],
        'material_id' => ['materials', 'name'],
        'unit_id' => ['units', 'symbol'],
        'tax_rate_id' => ['tax_rates', 'name'],
        'expense_category_id' => ['expense_categories', 'name'],
        'labour_trade_id' => ['labour_trades', 'name'],
        'equipment_type_id' => ['equipment_types', 'name'],
        'material_category_id' => ['material_categories', 'name'],
        'purchase_order_id' => ['purchase_orders', 'po_number'],
        'grn_id' => ['grns', 'grn_number'],
        'work_order_id' => ['work_orders', 'wo_number'],
    ];

    /** @var list<string> */
    private const USER_COLUMNS = [
        'user_id', 'assigned_to', 'approved_by', 'certified_by', 'cancelled_by', 'paid_by', 'reversed_by',
        'resolved_by', 'verified_by', 'closed_by', 'requested_by', 'project_manager_id', 'responsible_user_id',
        'created_by', 'updated_by', 'deleted_by',
    ];

    /** @var array<string, string|null> */
    private array $cache = [];

    /** @var array<string, bool> */
    private array $companyScoped = [];

    public function __construct(private readonly CurrentCompany $current) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<array{field: string, old: ?string, new: ?string}>
     */
    public function changes(array $old, array $new): array
    {
        $fields = array_values(array_unique([...array_keys($new), ...array_keys($old)]));

        return array_map(fn (string $field) => [
            'field' => Str::of($field)->replace('_', ' ')->headline()->toString(),
            'old' => $this->display($field, $old[$field] ?? null),
            'new' => $this->display($field, $new[$field] ?? null),
        ], array_slice($fields, 0, 40));
    }

    public function display(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return Str::limit(json_encode($value), 200);
        }
        $text = Str::limit(trim((string) $value), 200);
        if (in_array($field, self::MASKED, true)) {
            return $this->mask($text);
        }
        if (in_array($field, self::USER_COLUMNS, true) && ctype_digit($text)) {
            return $this->userName((int) $text) ?? "User #{$text}";
        }
        if (isset(self::LOOKUPS[$field]) && ctype_digit($text)) {
            [$table, $column] = self::LOOKUPS[$field];

            return $this->label($table, $column, (int) $text) ?? $text;
        }

        return $text;
    }

    public function entityLabel(string $type): string
    {
        return Str::of($type)->replace('_', ' ')->headline()->toString();
    }

    private function mask(string $value): string
    {
        $tail = mb_strlen($value) > 4 ? mb_substr($value, -4) : '';

        return '••••'.$tail;
    }

    private function userName(int $id): ?string
    {
        $key = "user:{$id}";
        if (! array_key_exists($key, $this->cache)) {
            $companyId = $this->current->id();
            $this->cache[$key] = DB::table('users')
                ->where('users.id', $id)
                ->where(function ($q) use ($companyId) {
                    $q->whereExists(fn ($m) => $m->select(DB::raw(1))->from('company_user')
                        ->whereColumn('company_user.user_id', 'users.id')
                        ->where('company_user.company_id', $companyId))
                        ->orWhere('users.is_super_admin', true);
                })
                ->value('name');
        }

        return $this->cache[$key];
    }

    private function label(string $table, string $column, int $id): ?string
    {
        $key = "{$table}:{$column}:{$id}";
        if (! array_key_exists($key, $this->cache)) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $this->cache[$key] = null;

                return null;
            }
            $query = DB::table($table)->where('id', $id);
            if ($this->hasCompany($table)) {
                $query->where('company_id', $this->current->id());
            }
            $this->cache[$key] = $query->value($column);
        }

        return $this->cache[$key] !== null ? (string) $this->cache[$key] : null;
    }

    private function hasCompany(string $table): bool
    {
        return $this->companyScoped[$table] ??= Schema::hasColumn($table, 'company_id');
    }
}
