<?php

namespace App\Support\Imports\Definitions;

use App\Services\Masters\MasterService;

/**
 * Labour register import (`labour` module key) via the master service layer.
 */
final class LabourRegisterImportDefinition extends MasterImportDefinition
{
    public function __construct(MasterService $masters)
    {
        parent::__construct('labour', $masters);
    }

    public function key(): string
    {
        return 'labour';
    }

    public function permission(): string
    {
        return 'labour.import';
    }
}
