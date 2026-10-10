<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Models\Files\FileExternalAccess;
use App\Services\Files\FilePreviewService;
use App\Services\Files\FileSourceResolver;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * One authorized preview/download surface for every private file the ERP stores.
 */
class FileViewerController extends Controller
{
    public function __construct(
        private readonly FileSourceResolver $files,
        private readonly FilePreviewService $previews,
    ) {}

    public function show(string $source, int $id): JsonResponse
    {
        return response()->json([
            'file' => $this->previews->describe($this->files->authorize($source, $id)),
        ]);
    }

    public function preview(string $source, int $id): JsonResponse
    {
        return response()->json(
            $this->previews->structured($this->files->authorize($source, $id)),
        );
    }

    public function stream(string $source, int $id): Response
    {
        return $this->previews->stream($this->files->authorize($source, $id));
    }

    public function download(string $source, int $id): Response
    {
        return $this->previews->download($this->files->authorize($source, $id));
    }

    public function retry(string $source, int $id): JsonResponse
    {
        $file = $this->files->authorize($source, $id);
        $this->previews->retry($file);

        return response()->json([
            'file' => $this->previews->describe($file),
        ]);
    }

    public function externalAccess(Request $request, string $source, int $id): JsonResponse
    {
        $file = $this->files->authorize($source, $id);
        Gate::authorize('manageSettings', app(CurrentCompany::class)->require());
        abort_unless(in_array($file->extension, ['dwg', 'dxf'], true), 404);

        $request->validate(['allow' => ['required', 'boolean']]);

        $row = FileExternalAccess::query()->firstOrNew([
            'source_type' => $file->source,
            'source_id' => $file->id,
        ]);
        $row->allow_external = $request->boolean('allow');
        $row->save();

        return response()->json([
            'file' => $this->previews->describe($file),
        ]);
    }
}
