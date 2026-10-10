<?php

namespace App\Console\Commands;

use App\Models\Chat\MessageAttachment;
use App\Models\Core\Attachment;
use App\Models\Core\Company;
use App\Models\Documents\DocumentVersion;
use App\Models\Documents\DrawingRevision;
use App\Models\Files\FilePreview;
use App\Services\Files\FilePreviewService;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('files:generate-previews {--type=dwg} {--company=} {--dry-run} {--batch=50}')]
#[Description('Queue missing DWG previews. Conversion runs on the queue, not in this command')]
class GenerateFilePreviews extends Command
{
    public function handle(FilePreviewService $previews, CurrentCompany $current): int
    {
        $type = strtolower((string) $this->option('type'));
        if ($type !== 'dwg') {
            $this->error('Only --type=dwg is supported.');

            return self::FAILURE;
        }

        $batch = max(1, min(200, (int) $this->option('batch')));
        $companies = $this->option('company')
            ? Company::query()->whereKey((int) $this->option('company'))->get()
            : Company::query()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        $queued = 0;
        foreach ($companies as $company) {
            if ($queued >= $batch) {
                break;
            }

            $current->runAs($company, function () use ($company, $previews, $batch, &$queued) {
                foreach ($this->candidates() as $candidate) {
                    if ($queued >= $batch) {
                        return;
                    }

                    if ($this->option('dry-run')) {
                        $this->line("company {$company->id} {$candidate['source']} {$candidate['id']}");
                        $queued++;

                        continue;
                    }

                    $previews->enqueue($candidate['source'], $candidate['id']);
                    $queued++;
                }
            });
        }

        $this->line($this->option('dry-run')
            ? "Dry run: {$queued} DWG file(s) would be queued."
            : "Queued {$queued} DWG preview job(s). Run php artisan queue:work to generate them.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{source: string, id: int}>
     */
    private function candidates(): Collection
    {
        $rows = collect();

        Attachment::query()->where('extension', 'dwg')->orderBy('id')->get(['id', 'checksum'])->each(function (Attachment $file) use ($rows) {
            $rows->push(['source' => 'attachment', 'id' => (int) $file->id, 'checksum' => $file->checksum]);
        });

        DrawingRevision::query()->where('extension', 'dwg')->orderBy('id')->get(['id', 'checksum'])->each(function (DrawingRevision $file) use ($rows) {
            $rows->push(['source' => 'drawing_revision', 'id' => (int) $file->id, 'checksum' => $file->checksum]);
        });

        DocumentVersion::query()->where('extension', 'dwg')->orderBy('id')->get(['id', 'checksum'])->each(function (DocumentVersion $file) use ($rows) {
            $rows->push(['source' => 'document_version', 'id' => (int) $file->id, 'checksum' => $file->checksum]);
        });

        MessageAttachment::query()->where('stored_name', 'like', '%.dwg')->orderBy('id')->get(['id', 'stored_name', 'checksum'])->each(function (MessageAttachment $file) use ($rows) {
            if ($file->extension() !== 'dwg') {
                return;
            }
            $rows->push(['source' => 'chat', 'id' => (int) $file->id, 'checksum' => $file->checksum]);
        });

        $existing = FilePreview::query()->whereIn('source_type', ['attachment', 'drawing_revision', 'document_version', 'chat'])->get();

        return $rows->filter(function (array $candidate) use ($existing) {
            $preview = $existing->first(fn (FilePreview $row) => $row->source_type === $candidate['source'] && (int) $row->source_id === $candidate['id']);
            if ($preview === null || $preview->status === FilePreview::UNSUPPORTED) {
                return true;
            }

            if ($preview->status === FilePreview::READY && $candidate['checksum'] !== null && $preview->source_checksum !== $candidate['checksum']) {
                return true;
            }

            return false;
        })->map(fn (array $candidate) => ['source' => $candidate['source'], 'id' => $candidate['id']])->values();
    }
}
