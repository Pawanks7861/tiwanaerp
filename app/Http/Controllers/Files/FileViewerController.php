<?php

namespace App\Http\Controllers\Files;

use App\Http\Controllers\Controller;
use App\Services\Files\FilePreviewService;
use App\Services\Files\FileSourceResolver;
use Illuminate\Http\JsonResponse;
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
}
