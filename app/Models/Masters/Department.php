<?php

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Attributes\Fillable;

#[Fillable(['code', 'name', 'is_active'])]
class Department extends MasterModel
{
    protected $table = 'departments';

    protected array $searchable = ['code', 'name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
