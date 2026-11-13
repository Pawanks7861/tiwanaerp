<?php

namespace App\Http\Controllers\SiteExecution;

use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Services\SiteExecution\SiteDiaryPhotoService;
use App\Services\SiteExecution\SiteDiaryService;
use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Site photos: upload / remove while the diary is editable, download for anyone who may view it.
 * The route is scoped project → diary → photo, so a photo id from another diary is a 404.
 */
class SiteDiaryPhotoController extends Controller
{
    public function __construct(
        private readonly SiteDiaryPhotoService $photos,
        private readonly LargeFileUploadService $uploads,
    ) {}

    public function store(Request $request, Project $project, SiteDiary $siteDiary): RedirectResponse
    {
        Gate::authorize('update', $siteDiary);
        throw ValidationException::withMessages(['diary' => SiteDiaryService::RETIRED_MESSAGE]);
    }

    public function show(Project $project, SiteDiary $siteDiary, SiteDiaryPhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $siteDiary);

        return $this->photos->stream($photo);
    }

    public function thumb(Project $project, SiteDiary $siteDiary, SiteDiaryPhoto $photo): StreamedResponse
    {
        Gate::authorize('view', $siteDiary);

        return $this->photos->stream($photo, thumbnail: true);
    }

    public function destroy(Project $project, SiteDiary $siteDiary, SiteDiaryPhoto $photo): RedirectResponse
    {
        Gate::authorize('update', $siteDiary);
        throw ValidationException::withMessages(['diary' => SiteDiaryService::RETIRED_MESSAGE]);
    }
}
