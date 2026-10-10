<?php

namespace App\Console\Commands;

use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('uploads:cleanup')]
#[Description('Delete abandoned upload chunks and expire unfinished upload sessions')]
class CleanupUploads extends Command
{
    public function handle(LargeFileUploadService $uploads): int
    {
        $count = $uploads->purgeExpired();
        $this->line("Cleaned {$count} unfinished upload session(s).");

        return self::SUCCESS;
    }
}
