<?php

namespace App\Http\Controllers\Api\V1\Documents;

use App\Http\Controllers\Controller;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentFolder;
use App\Models\Projects\Project;
use App\Services\Documents\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentFolderApiController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Project $project): JsonResponse
    {
        Gate::authorize('viewAny', [Document::class, $project]);

        $counts = $project->documents()
            ->selectRaw('document_folder_id, count(*) as aggregate')
            ->groupBy('document_folder_id')
            ->pluck('aggregate', 'document_folder_id');

        $folders = $project->documentFolders()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'sort_order']);

        return response()->json([
            'data' => [
                'folders' => $folders->map(fn (DocumentFolder $f) => [
                    ...$f->only(['id', 'parent_id', 'name', 'sort_order']),
                    'documents_count' => (int) ($counts[$f->id] ?? 0),
                ])->values(),
                'unfiled_count' => (int) ($counts->get('', 0) + ($counts[null] ?? 0)),
                'can' => [
                    'manage_folders' => request()->user()->can('manageFolders', [Document::class, $project]),
                ],
            ],
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('manageFolders', [Document::class, $project]);

        $folder = $this->documents->createFolder($project, $this->validated($request));

        return response()->json([
            'data' => $folder->only(['id', 'parent_id', 'name', 'sort_order']),
        ], 201);
    }

    public function update(Request $request, Project $project, DocumentFolder $documentFolder): JsonResponse
    {
        $this->assertFolder($project, $documentFolder);
        Gate::authorize('manageFolders', [Document::class, $project]);

        $this->documents->updateFolder($documentFolder, $this->validated($request));

        return response()->json([
            'data' => $documentFolder->fresh()->only(['id', 'parent_id', 'name', 'sort_order']),
        ]);
    }

    public function destroy(Project $project, DocumentFolder $documentFolder): JsonResponse
    {
        $this->assertFolder($project, $documentFolder);
        Gate::authorize('manageFolders', [Document::class, $project]);

        $this->documents->deleteFolder($documentFolder);

        return response()->json(['message' => 'Folder deleted.']);
    }

    /**
     * @return array{name: string, parent_id?: ?int}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer'],
        ]);
    }

    private function assertFolder(Project $project, DocumentFolder $folder): void
    {
        abort_unless((int) $folder->project_id === (int) $project->id, 404);
    }
}
