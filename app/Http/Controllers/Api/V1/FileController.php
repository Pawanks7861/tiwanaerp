<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Files\FilePreviewService;
use App\Services\Files\FileSourceResolver;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum-facing wrapper around the same private file authorization as the web viewer.
 */
class FileController extends Controller
{
    public function __construct(
        private readonly FileSourceResolver $files,
        private readonly FilePreviewService $previews,
    ) {}

    public function show(string $source, int $id): JsonResponse
    {
        $file = $this->previews->describe($this->files->authorize($source, $id));

        return response()->json([
            'data' => $this->withApiUrls($file),
        ]);
    }

    public function preview(string $source, int $id): JsonResponse
    {
        $payload = $this->previews->structured($this->files->authorize($source, $id));
        if (isset($payload['file']) && is_array($payload['file'])) {
            $payload['file'] = $this->withApiUrls($payload['file']);
        }

        return response()->json($payload);
    }

    public function stream(string $source, int $id): Response
    {
        return $this->previews->stream($this->files->authorize($source, $id));
    }

    public function download(string $source, int $id): Response
    {
        return $this->previews->download($this->files->authorize($source, $id));
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function withApiUrls(array $file): array
    {
        $source = (string) ($file['source'] ?? '');
        $id = (int) ($file['id'] ?? 0);
        if ($source === '' || $id < 1) {
            return $file;
        }

        $file['download_url'] = route('api.v1.files.download', ['source' => $source, 'id' => $id]);
        $file['stream_url'] = route('api.v1.files.stream', ['source' => $source, 'id' => $id]);
        $file['preview_url'] = route('api.v1.files.preview', ['source' => $source, 'id' => $id]);

        return $file;
    }
}
