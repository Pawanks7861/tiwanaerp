<?php

namespace App\Models\Integrations;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TallyConnection extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'timeout_seconds' => 'integer',
            'auto_sync' => 'boolean',
            'sync_approved_transactions' => 'boolean',
            'dry_run' => 'boolean',
            'cost_centres_enabled' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_connected_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function endpoint(): string
    {
        $protocol = $this->protocol === 'https' ? 'https' : 'http';

        return $protocol.'://'.$this->host.':'.$this->port;
    }
}
