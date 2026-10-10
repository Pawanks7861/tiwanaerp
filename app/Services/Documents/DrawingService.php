<?php

namespace App\Services\Documents;

use App\Enums\Discipline;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Enums\Documents\DrawingStatus;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Attachments\PrivateFileStore;
use App\Services\Files\FilePreviewService;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Drawing register and revisions: draft → submitted → under_review → approved / rejected.
 *
 * Every revision is a new row with a new code (never reused, even after rejection or withdrawal)
 * and an immutable private file. One revision at a time may be in the workflow. Approving locks
 * the drawing, marks the previously approved revision superseded and moves current_revision_id,
 * all in one transaction. Superseded revisions stay visible and downloadable.
 */
class DrawingService
{
    public const REVISION_CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,9}$/';

    public function __construct(private readonly PrivateFileStore $files) {}

    /**
     * @param  array{drawing_number: string, title: string, discipline: string, revision_code: string, remarks?: ?string}  $data
     * @return array{0: Drawing, 1: DrawingRevision}
     */
    public function create(Project $project, array $data, UploadedFile $file, User $user): array
    {
        $number = $this->drawingNumber($data['drawing_number'] ?? '');
        $code = $this->revisionCode($data['revision_code'] ?? '');

        return $this->withStoredFile($file, $project, function (array $stored) use ($project, $data, $number, $code, $user) {
            if (Drawing::query()->withTrashed()->where('project_id', $project->id)->where('drawing_number', $number)->exists()) {
                throw ValidationException::withMessages(['drawing_number' => 'This drawing number is already registered in the project.']);
            }

            $drawing = new Drawing;
            $drawing->forceFill([
                'project_id' => $project->id,
                'drawing_number' => $number,
                'title' => trim($data['title']),
                'discipline' => Discipline::from($data['discipline']),
                'status' => DrawingStatus::Draft,
            ])->save();

            $revision = $this->newRevision($drawing, $code, $stored, $data['remarks'] ?? null, $user);

            return [$drawing, $revision];
        });
    }

    /**
     * @param  array{drawing_number: string, title: string, discipline: string}  $data
     */
    public function update(Drawing $drawing, array $data): Drawing
    {
        return DB::transaction(function () use ($drawing, $data) {
            $locked = Drawing::query()->whereKey($drawing->id)->lockForUpdate()->firstOrFail();
            $number = $this->drawingNumber($data['drawing_number'] ?? '');

            if ($number !== $locked->drawing_number) {
                if ($locked->status === DrawingStatus::Approved) {
                    throw ValidationException::withMessages(['drawing_number' => 'The drawing number is fixed once a revision has been approved.']);
                }
                if (Drawing::query()->withTrashed()->where('project_id', $locked->project_id)->where('drawing_number', $number)->whereKeyNot($locked->id)->exists()) {
                    throw ValidationException::withMessages(['drawing_number' => 'This drawing number is already registered in the project.']);
                }
            }

            $locked->forceFill([
                'drawing_number' => $number,
                'title' => trim($data['title']),
                'discipline' => Discipline::from($data['discipline']),
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  array{revision_code: string, remarks?: ?string}  $data
     */
    public function uploadRevision(Drawing $drawing, array $data, UploadedFile $file, User $user): DrawingRevision
    {
        $code = $this->revisionCode($data['revision_code'] ?? '');

        return $this->withStoredFile($file, $drawing->project, function (array $stored) use ($drawing, $code, $data, $user) {
            $locked = Drawing::query()->whereKey($drawing->id)->lockForUpdate()->firstOrFail();

            return $this->newRevision($locked, $code, $stored, $data['remarks'] ?? null, $user);
        });
    }

    public function submit(DrawingRevision $revision, User $user): void
    {
        $this->transition($revision, DrawingRevisionStatus::Draft, 'Only a draft revision can be submitted.', function (DrawingRevision $r, Drawing $d) use ($user) {
            $r->forceFill(['status' => DrawingRevisionStatus::Submitted, 'submitted_by' => $user->id, 'submitted_at' => now()])->save();
            $d->writeAudit('revision_submitted', null, ['revision' => $r->revision_code]);
        });
    }

    public function startReview(DrawingRevision $revision, User $user): void
    {
        $this->transition($revision, DrawingRevisionStatus::Submitted, 'Only a submitted revision can be taken into review.', function (DrawingRevision $r, Drawing $d) use ($user) {
            $r->forceFill(['status' => DrawingRevisionStatus::UnderReview, 'reviewed_by' => $user->id, 'reviewed_at' => now()])->save();
            $d->writeAudit('revision_review_started', null, ['revision' => $r->revision_code]);
        });
    }

    public function approve(DrawingRevision $revision, ?string $comments, User $user): void
    {
        $this->transition($revision, DrawingRevisionStatus::UnderReview, 'Only a revision under review can be approved.', function (DrawingRevision $r, Drawing $d) use ($comments, $user) {
            $previous = $d->current_revision_id
                ? DrawingRevision::query()->whereKey($d->current_revision_id)->lockForUpdate()->first()
                : null;

            if ($previous !== null && $previous->status === DrawingRevisionStatus::Approved) {
                $previous->forceFill(['status' => DrawingRevisionStatus::Superseded, 'superseded_at' => now()])->save();
            }

            $r->forceFill([
                'status' => DrawingRevisionStatus::Approved,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'review_comments' => filled($comments) ? trim($comments) : null,
                'supersedes_revision_id' => $previous?->id,
            ])->save();

            $d->forceFill(['current_revision_id' => $r->id, 'status' => DrawingStatus::Approved])->save();
            $d->writeAudit('revision_approved', $previous ? ['current_revision' => $previous->revision_code] : null, [
                'current_revision' => $r->revision_code,
                'superseded' => $previous?->revision_code,
            ]);
        });
    }

    public function reject(DrawingRevision $revision, string $comments, User $user): void
    {
        if (trim($comments) === '') {
            throw ValidationException::withMessages(['comments' => 'Give the reason for rejecting the revision.']);
        }

        $this->transition($revision, DrawingRevisionStatus::UnderReview, 'Only a revision under review can be rejected.', function (DrawingRevision $r, Drawing $d) use ($comments, $user) {
            $r->forceFill([
                'status' => DrawingRevisionStatus::Rejected,
                'decided_by' => $user->id,
                'decided_at' => now(),
                'review_comments' => trim($comments),
            ])->save();
            $d->writeAudit('revision_rejected', null, ['revision' => $r->revision_code, 'comments' => trim($comments)]);
        });
    }

    /**
     * Withdraw a draft that was never submitted. The row is soft deleted, the file kept, and the
     * revision code stays taken.
     */
    public function withdraw(DrawingRevision $revision): void
    {
        $this->transition($revision, DrawingRevisionStatus::Draft, 'Only a draft revision can be withdrawn.', function (DrawingRevision $r, Drawing $d) {
            $r->delete();
            $d->writeAudit('revision_withdrawn', null, ['revision' => $r->revision_code]);
        });
    }

    /**
     * Same file content already uploaded as another revision of this drawing (flag only).
     */
    public function duplicateOf(DrawingRevision $revision): ?string
    {
        return DrawingRevision::query()->withTrashed()
            ->where('drawing_id', $revision->drawing_id)
            ->where('checksum', $revision->checksum)
            ->whereKeyNot($revision->id)
            ->orderBy('id')
            ->value('revision_code');
    }

    public function revisionCode(string $code): string
    {
        $code = strtoupper(trim($code));
        if (preg_match(self::REVISION_CODE_PATTERN, $code) !== 1) {
            throw ValidationException::withMessages(['revision_code' => 'Use a revision code such as R0, R1, A or B (letters, digits, . _ -, up to 10 characters).']);
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function newRevision(Drawing $drawing, string $code, array $stored, ?string $remarks, User $user): DrawingRevision
    {
        $open = DrawingRevision::query()->where('drawing_id', $drawing->id)
            ->whereIn('status', [DrawingRevisionStatus::Draft, DrawingRevisionStatus::Submitted, DrawingRevisionStatus::UnderReview])
            ->value('revision_code');
        if ($open !== null) {
            throw ValidationException::withMessages(['revision_code' => "Revision {$open} is still in the workflow. Finish or withdraw it first."]);
        }
        if (DrawingRevision::query()->withTrashed()->where('drawing_id', $drawing->id)->where('revision_code', $code)->exists()) {
            throw ValidationException::withMessages(['revision_code' => "Revision code {$code} has already been used for this drawing. Codes are never reused."]);
        }

        $revision = new DrawingRevision;
        $revision->forceFill([
            'drawing_id' => $drawing->id,
            'revision_code' => $code,
            ...$stored,
            'status' => DrawingRevisionStatus::Draft,
            'remarks' => filled($remarks) ? trim($remarks) : null,
            'uploaded_by' => $user->id,
        ])->save();
        $drawing->writeAudit('revision_uploaded', null, ['revision' => $code, 'file' => $stored['file_name'], 'checksum' => $stored['checksum']]);
        app(FilePreviewService::class)->enqueue('drawing_revision', (int) $revision->id);

        return $revision;
    }

    /**
     * @param  Closure(DrawingRevision, Drawing): void  $apply
     */
    private function transition(DrawingRevision $revision, DrawingRevisionStatus $from, string $message, Closure $apply): void
    {
        DB::transaction(function () use ($revision, $from, $message, $apply) {
            $drawing = Drawing::query()->whereKey($revision->drawing_id)->lockForUpdate()->firstOrFail();
            $locked = DrawingRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                throw ValidationException::withMessages(['revision' => $message]);
            }

            $apply($locked, $drawing);
            $revision->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(array<string, mixed>): T  $write
     * @return T
     */
    private function withStoredFile(UploadedFile $file, Project $project, Closure $write): mixed
    {
        $stored = $this->files->put($file, (int) $project->company_id, (int) $project->id, 'drawings', config('uploads.drawing_extensions'));

        try {
            return DB::transaction(fn () => $write($stored));
        } catch (Throwable $e) {
            $this->files->discard($stored);
            throw $e;
        }
    }

    private function drawingNumber(string $number): string
    {
        $number = trim($number);
        if ($number === '' || mb_strlen($number) > 60) {
            throw ValidationException::withMessages(['drawing_number' => 'Enter the drawing number (up to 60 characters).']);
        }

        return $number;
    }
}
