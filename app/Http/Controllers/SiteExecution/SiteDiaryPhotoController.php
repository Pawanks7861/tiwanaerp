<?php

namespace App\Http\Controllers\SiteExecution;

use App\Http\Controllers\Controller;
use App\Models\Projects\Project;
use App\Models\SiteExecution\SiteDiary;
use App\Models\SiteExecution\SiteDiaryPhoto;
use App\Services\SiteExecution\SiteDiaryPhotoService;
use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        if ($request->filled('upload_id')) {
            $data = $request->validate([
                'upload_id' => ['required', 'uuid'],
                'caption' => ['nullable', 'string', 'max:255'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'taken_at' => ['nullable', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
            ]);
            $file = $this->uploads->claim($request->user(), $data['upload_id']);
            $this->photos->store($siteDiary, $file, [
                'caption' => $data['caption'] ?? null,
                'latitude' => isset($data['latitude']) ? round((float) $data['latitude'], 7) : null,
                'longitude' => isset($data['longitude']) ? round((float) $data['longitude'], 7) : null,
                'taken_at' => $data['taken_at'] ?? null,
            ]);
            $this->uploads->release($request->user(), $data['upload_id']);

            return back()->with('success', 'Photo added.');
        }

        $maxKb = (int) config('uploads.photo_max_kb');
        $data = $request->validate([
            'photo' => ['required', 'file', "max:{$maxKb}", 'extensions:'.implode(',', config('uploads.image_extensions'))],
            'uuid' => ['nullable', 'uuid'],
            'caption' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'taken_at' => ['nullable', 'date', 'before_or_equal:'.now()->addDay()->toDateString()],
        ]);

        $this->photos->store($siteDiary, $request->file('photo'), [
            'uuid' => $data['uuid'] ?? null,
            'caption' => $data['caption'] ?? null,
            'latitude' => isset($data['latitude']) ? round((float) $data['latitude'], 7) : null,
            'longitude' => isset($data['longitude']) ? round((float) $data['longitude'], 7) : null,
            'taken_at' => $data['taken_at'] ?? null,
        ]);

        return back()->with('success', 'Photo added.');
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

        $this->photos->delete($photo);

        return back()->with('success', 'Photo removed.');
    }
}
