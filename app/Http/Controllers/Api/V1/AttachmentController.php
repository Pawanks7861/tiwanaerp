<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Core\AttachmentController as WebAttachmentController;
use App\Http\Resources\AttachmentResource;
use App\Models\Core\Attachment;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\FileTypeGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * Sanctum attachment upload/delete using the same morph types and parent policies as web.
 */
class AttachmentController extends Controller
{
    public function __construct(private readonly AttachmentService $attachments) {}

    public function store(Request $request): JsonResponse
    {
        $maxKb = (int) config('uploads.max_kb');

        $validated = $request->validate([
            'attachable_type' => ['required', 'string', 'in:'.implode(',', WebAttachmentController::ATTACHABLE)],
            'attachable_id' => ['required', 'integer'],
            'category' => ['nullable', 'string', 'max:50'],
            'file' => ['required', 'file', "max:{$maxKb}", function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value instanceof UploadedFile && ! app(FileTypeGuard::class)->isAllowed($value)) {
                    $fail('File type not permitted for security reasons');
                }
            }],
        ]);

        /** @var class-string<Model> $class */
        $class = Relation::getMorphedModel($validated['attachable_type']);
        abort_unless(is_string($class), 404);
        $attachable = $class::query()->findOrFail($validated['attachable_id']);
        Gate::authorize('update', $attachable);

        $attachment = $this->attachments->store($attachable, $request->file('file'), $validated['category'] ?? null);

        return (new AttachmentResource($attachment->load('uploader:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        Gate::authorize('delete', $attachment);
        $this->attachments->delete($attachment);

        return response()->json(['message' => 'File removed.']);
    }
}
