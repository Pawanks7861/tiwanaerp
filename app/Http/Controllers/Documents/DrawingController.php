<?php

namespace App\Http\Controllers\Documents;

use App\Enums\Discipline;
use App\Enums\Documents\DrawingRevisionStatus;
use App\Enums\Documents\DrawingStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AuditPresenter;
use App\Http\Controllers\Projects\ProjectHeader;
use App\Models\Documents\Drawing;
use App\Models\Documents\DrawingRevision;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Attachments\FileTypeGuard;
use App\Services\Attachments\PrivateFileStore;
use App\Services\Documents\DrawingService;
use App\Services\Files\FilePreviewService;
use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DrawingController extends Controller
{
    public function __construct(
        private readonly DrawingService $drawings,
        private readonly PrivateFileStore $files,
        private readonly LargeFileUploadService $uploads,
    ) {}

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('viewAny', [Drawing::class, $project]);

        $filters = $request->validate([
            'discipline' => ['nullable', Rule::enum(Discipline::class)],
            'status' => ['nullable', Rule::enum(DrawingStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = $project->drawings()
            ->with(['currentRevision:id,revision_code,decided_at'])
            ->withCount('revisions')
            ->addSelect(['open_revision' => DrawingRevision::query()->select('status')
                ->whereColumn('drawing_id', 'drawings.id')
                ->whereIn('status', [DrawingRevisionStatus::Draft->value, DrawingRevisionStatus::Submitted->value, DrawingRevisionStatus::UnderReview->value])
                ->whereNull('deleted_at')
                ->limit(1)])
            ->when($filters['discipline'] ?? null, fn ($q, $d) => $q->where('discipline', $d))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('drawing_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('title', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->orderBy('drawing_number')->paginate(25)->withQueryString();

        return Inertia::render('Documents/Drawings/Index', [
            'project' => ProjectHeader::for($project),
            'drawings' => $page->through(fn (Drawing $d) => [
                ...$this->header($d),
                'revisions_count' => $d->revisions_count,
                'open_revision_status' => $d->open_revision,
                'open_revision_label' => $d->open_revision ? DrawingRevisionStatus::from($d->open_revision)->label() : null,
            ]),
            'filters' => $filters,
            'disciplines' => Discipline::options(),
            'statuses' => DrawingStatus::options(),
            'can' => ['create' => $request->user()->can('create', [Drawing::class, $project])],
        ]);
    }

    public function create(Project $project): Response
    {
        Gate::authorize('create', [Drawing::class, $project]);

        return Inertia::render('Documents/Drawings/Create', [
            'project' => ProjectHeader::for($project),
            'disciplines' => Discipline::options(),
            'extensions' => config('uploads.drawing_extensions'),
            'maxMb' => (int) floor(config('uploads.max_kb') / 1024),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('create', [Drawing::class, $project]);

        $data = $request->validate([
            'drawing_number' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:200'],
            'discipline' => ['required', Rule::enum(Discipline::class)],
            'revision_code' => ['required', 'string', 'max:10'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            ...$this->fileRules(),
        ]);
        $file = $this->uploads->fileFromRequest($request);
        [$drawing, $revision] = $this->drawings->create($project, $data, $file, $request->user());
        $this->uploads->release($request->user(), $request->input('upload_id'));

        return redirect()->route('projects.drawings.show', [$project, $drawing])
            ->with('success', "Drawing {$drawing->drawing_number} registered with revision {$revision->revision_code} (draft). Submit it for review when ready.");
    }

    public function show(Request $request, Project $project, Drawing $drawing): Response
    {
        Gate::authorize('view', $drawing);
        $user = $request->user();
        $drawing->load('currentRevision:id,revision_code');

        $revisions = DrawingRevision::query()->withTrashed()->where('drawing_id', $drawing->id)
            ->with(['uploader:id,name', 'submitter:id,name', 'reviewer:id,name', 'decider:id,name', 'supersedes:id,revision_code'])
            ->orderByDesc('id')->get();
        $open = $revisions->first(fn (DrawingRevision $r) => ! $r->trashed() && $r->status->isOpen());

        return Inertia::render('Documents/Drawings/Show', [
            'project' => ProjectHeader::for($project),
            'drawing' => $this->header($drawing),
            'revisions' => $revisions->map(fn (DrawingRevision $r) => $this->revision($project, $drawing, $r, $user))->all(),
            'disciplines' => Discipline::options(),
            'extensions' => config('uploads.drawing_extensions'),
            'maxMb' => (int) floor(config('uploads.max_kb') / 1024),
            'audit' => AuditPresenter::trail([$drawing, ...$revisions->all()], ['drawing_revision' => 'Revision']),
            'can' => [
                'update' => $user->can('upload', $drawing),
                'editNumber' => $drawing->status !== DrawingStatus::Approved && $user->can('upload', $drawing),
                'upload' => $open === null && $user->can('upload', $drawing),
            ],
        ]);
    }

    public function update(Request $request, Project $project, Drawing $drawing): RedirectResponse
    {
        Gate::authorize('upload', $drawing);

        $data = $request->validate([
            'drawing_number' => ['required', 'string', 'max:60'],
            'title' => ['required', 'string', 'max:200'],
            'discipline' => ['required', Rule::enum(Discipline::class)],
        ]);
        $this->drawings->update($drawing, $data);

        return back()->with('success', 'Drawing details saved.');
    }

    public function storeRevision(Request $request, Project $project, Drawing $drawing): RedirectResponse
    {
        Gate::authorize('upload', $drawing);

        $data = $request->validate([
            'revision_code' => ['required', 'string', 'max:10'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            ...$this->fileRules(),
        ]);
        $file = $this->uploads->fileFromRequest($request);
        $revision = $this->drawings->uploadRevision($drawing, $data, $file, $request->user());
        $this->uploads->release($request->user(), $request->input('upload_id'));
        $duplicate = $this->drawings->duplicateOf($revision);

        return back()->with('success', "Revision {$revision->revision_code} uploaded as draft."
            .($duplicate ? " Note: the file is identical to revision {$duplicate}." : ''));
    }

    public function submit(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): RedirectResponse
    {
        Gate::authorize('upload', $drawing);

        $this->drawings->submit($revision, $request->user());

        return back()->with('success', "Revision {$revision->revision_code} submitted for review.");
    }

    public function review(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): RedirectResponse
    {
        Gate::authorize('review', $drawing);

        $this->drawings->startReview($revision, $request->user());

        return back()->with('success', "Revision {$revision->revision_code} is under review.");
    }

    public function approve(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): RedirectResponse
    {
        Gate::authorize('approve', $drawing);

        $comments = $request->validate(['comments' => ['nullable', 'string', 'max:1000']])['comments'] ?? null;
        $this->drawings->approve($revision, $comments, $request->user());

        return back()->with('success', "Revision {$revision->revision_code} approved and now current.");
    }

    public function reject(Request $request, Project $project, Drawing $drawing, DrawingRevision $revision): RedirectResponse
    {
        Gate::authorize('reject', $drawing);

        $comments = $request->validate(['comments' => ['required', 'string', 'min:5', 'max:1000']])['comments'];
        $this->drawings->reject($revision, $comments, $request->user());

        return back()->with('success', "Revision {$revision->revision_code} rejected.");
    }

    public function withdraw(Project $project, Drawing $drawing, DrawingRevision $revision): RedirectResponse
    {
        Gate::authorize('upload', $drawing);

        $this->drawings->withdraw($revision);

        return back()->with('success', "Draft revision {$revision->revision_code} withdrawn. The code cannot be reused.");
    }

    public function download(Project $project, Drawing $drawing, DrawingRevision $revision): StreamedResponse
    {
        Gate::authorize('view', $drawing);

        return $this->files->respond($revision, inline: false);
    }

    public function preview(Project $project, Drawing $drawing, DrawingRevision $revision): StreamedResponse
    {
        Gate::authorize('view', $drawing);
        abort_unless(PrivateFileStore::isPreviewable($revision->extension), 404);

        return $this->files->respond($revision, inline: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function fileRules(): array
    {
        return FileTypeGuard::fileRules();
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Drawing $drawing): array
    {
        return [
            'id' => $drawing->id,
            'drawing_number' => $drawing->drawing_number,
            'title' => $drawing->title,
            'discipline' => $drawing->discipline->value,
            'discipline_label' => $drawing->discipline->label(),
            'status' => $drawing->status->value,
            'status_label' => $drawing->status->label(),
            'current_revision' => $drawing->currentRevision?->revision_code,
            'current_revision_id' => $drawing->current_revision_id,
        ];
    }

    /**
     * Permission AND revision state (platform super admins pass every policy check).
     *
     * @return array<string, mixed>
     */
    private function revision(Project $project, Drawing $drawing, DrawingRevision $r, User $user): array
    {
        $status = $r->status;
        $withdrawn = $r->trashed();
        $route = fn (string $name) => route("projects.drawings.revisions.{$name}", [$project, $drawing, $r]);

        return [
            'id' => $r->id,
            'revision_code' => $r->revision_code,
            'status' => $withdrawn ? 'withdrawn' : $status->value,
            'status_label' => $withdrawn ? 'Withdrawn' : $status->label(),
            'is_current' => $r->id === $drawing->current_revision_id,
            'file_name' => $r->file_name,
            'extension' => $r->extension,
            'size_bytes' => $r->size_bytes,
            'checksum' => $r->checksum,
            'remarks' => $r->remarks,
            'review_comments' => $r->review_comments,
            'uploaded_by' => $r->uploader?->name,
            'uploaded_at' => $r->created_at?->toIso8601String(),
            'submitted_by' => $r->submitter?->name,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'reviewed_by' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'decided_by' => $r->decider?->name,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'superseded_at' => $r->superseded_at?->toIso8601String(),
            'supersedes' => $r->supersedes?->revision_code,
            'previewable' => ! $withdrawn && PrivateFileStore::isPreviewable($r->extension),
            'download_url' => $withdrawn ? null : $route('download'),
            'preview' => $withdrawn ? null : app(FilePreviewService::class)->card('drawing_revision', (int) $r->id, (string) $r->extension),
            'preview_url' => ! $withdrawn && PrivateFileStore::isPreviewable($r->extension) ? $route('preview') : null,
            'can' => [
                'submit' => ! $withdrawn && $status === DrawingRevisionStatus::Draft && $user->can('upload', $drawing),
                'withdraw' => ! $withdrawn && $status === DrawingRevisionStatus::Draft && $user->can('upload', $drawing),
                'review' => ! $withdrawn && $status === DrawingRevisionStatus::Submitted && $user->can('review', $drawing),
                'approve' => ! $withdrawn && $status === DrawingRevisionStatus::UnderReview && $user->can('approve', $drawing),
                'reject' => ! $withdrawn && $status === DrawingRevisionStatus::UnderReview && $user->can('reject', $drawing),
            ],
        ];
    }
}
