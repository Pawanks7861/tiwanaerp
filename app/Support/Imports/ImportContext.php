<?php

namespace App\Support\Imports;

use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Per-import lookup cache so each file does not re-query masters for every row.
 */
final class ImportContext
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * @param  array<string, mixed>  $meta  Module-specific context (e.g. boq_id).
     */
    public function __construct(
        public readonly Company $company,
        public readonly User $actor,
        public readonly ?Project $project = null,
        public readonly array $meta = [],
    ) {}

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function remember(string $key, callable $resolver): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $resolver();
        }

        return $this->cache[$key];
    }

    public function lockKey(string $module): string
    {
        return "import:{$this->company->id}:{$module}";
    }

    public function withCompanyLock(string $module, callable $callback): mixed
    {
        $lock = Cache::lock($this->lockKey($module), 30);

        return $lock->block(10, $callback);
    }
}
