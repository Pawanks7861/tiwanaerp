<?php

namespace App\Http\Controllers\Uploads;

use App\Http\Controllers\Controller;
use App\Models\Uploads\UploadSession;
use App\Services\Uploads\LargeFileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function __construct(private readonly LargeFileUploadService $uploads) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'original_name' => ['required', 'string', 'max:200'],
            'total_size' => ['required', 'integer', 'min:1'],
            'module' => ['required', 'string', 'max:40'],
            'source_type' => ['nullable', 'string', 'max:40'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'declared_mime' => ['nullable', 'string', 'max:127'],
        ]);

        $session = $this->uploads->initialize($request->user(), $data);

        return response()->json(['upload' => $this->uploads->describe($session)], 201);
    }

    public function show(Request $request, UploadSession $upload): JsonResponse
    {
        $session = $this->uploads->status($request->user(), $upload);

        return response()->json(['upload' => $this->uploads->describe($session)]);
    }

    public function chunk(Request $request, UploadSession $upload, int $number): JsonResponse
    {
        $data = $request->validate([
            'chunk' => ['required', 'file'],
            'checksum' => ['nullable', 'string', 'size:64'],
        ]);

        $session = $this->uploads->receive($request->user(), $upload, $number, $data['chunk'], $data['checksum'] ?? null);

        return response()->json(['upload' => $this->uploads->describe($session)]);
    }

    public function complete(Request $request, UploadSession $upload): JsonResponse
    {
        $data = $request->validate([
            'checksum' => ['nullable', 'string', 'size:64'],
            'category' => ['nullable', 'string', 'max:50'],
        ]);

        $session = $this->uploads->complete($request->user(), $upload, $data['checksum'] ?? null, $data['category'] ?? null);

        return response()->json(['upload' => $this->uploads->describe($session)]);
    }

    public function destroy(Request $request, UploadSession $upload): JsonResponse
    {
        $this->uploads->cancel($request->user(), $upload);

        return response()->json(['upload' => $this->uploads->describe($upload->fresh())]);
    }
}
