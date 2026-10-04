<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Documents\Document;
use App\Models\Documents\DocumentFolder;
use App\Models\Projects\Project;
use App\Services\Documents\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DocumentFolderController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('manageFolders', [Document::class, $project]);

        $folder = $this->documents->createFolder($project, $this->validated($request));

        return back()->with('success', "Folder \"{$folder->name}\" created.");
    }

    public function update(Request $request, Project $project, DocumentFolder $documentFolder): RedirectResponse
    {
        Gate::authorize('manageFolders', [Document::class, $project]);

        $this->documents->updateFolder($documentFolder, $this->validated($request));

        return back()->with('success', 'Folder saved.');
    }

    public function destroy(Project $project, DocumentFolder $documentFolder): RedirectResponse
    {
        Gate::authorize('manageFolders', [Document::class, $project]);

        $this->documents->deleteFolder($documentFolder);

        return redirect()->route('projects.documents.index', $project)->with('success', "Folder \"{$documentFolder->name}\" deleted.");
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
}
