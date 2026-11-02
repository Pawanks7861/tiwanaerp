<?php

namespace App\Models\Imports;

use App\Enums\Imports\ImportMode;
use App\Enums\Imports\ImportStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImport extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'uuid',
        'project_id',
        'context',
        'module',
        'mode',
        'status',
        'original_filename',
        'mime',
        'size_bytes',
        'disk',
        'path',
        'mapping',
        'summary',
        'validation_path',
        'error_report_path',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'create_count',
        'update_count',
        'skip_count',
        'fail_count',
        'failure_message',
        'uploaded_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => ImportMode::class,
            'status' => ImportStatus::class,
            'mapping' => 'array',
            'summary' => 'array',
            'context' => 'array',
            'size_bytes' => 'integer',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'create_count' => 'integer',
            'update_count' => 'integer',
            'skip_count' => 'integer',
            'fail_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
