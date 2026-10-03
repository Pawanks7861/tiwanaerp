<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\Core\Attachment;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\FileTypeGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /** Morph aliases that accept attachments. */
    private const ATTACHABLE = [
        'vendor', 'subcontractor', 'client',
        'material_request', 'rfq', 'vendor_quotation', 'purchase_order', 'grn',
        'material_issue', 'stock_transfer', 'material_return', 'stock_adjustment',
        'dpr',
        'labour', 'equipment', 'work_order', 'subcontractor_bill', 'equipment_repair',
        'expense', 'client_invoice', 'vendor_bill', 'payment', 'retention_release', 'quotation',
    ];

    public function __construct(private readonly AttachmentService $attachments) {}

    public function store(Request $request): RedirectResponse
    {
        $maxKb = (int) config('uploads.max_kb');

        $validated = $request->validate([
            'attachable_type' => ['required', 'string', 'in:'.implode(',', self::ATTACHABLE)],
            'attachable_id' => ['required', 'integer'],
            'category' => ['nullable', 'string', 'max:50'],
            'file' => ['required', 'file', "max:{$maxKb}", 'extensions:'.implode(',', FileTypeGuard::extensions())],
        ]);

        /** @var class-string<Model> $class */
        $class = Relation::getMorphedModel($validated['attachable_type']);
        $attachable = $class::query()->findOrFail($validated['attachable_id']);
        Gate::authorize('update', $attachable);

        $this->attachments->store($attachable, $request->file('file'), $validated['category'] ?? null);

        return back()->with('success', 'File uploaded.');
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        return $this->attachments->download($attachment);
    }

    public function destroy(Attachment $attachment): RedirectResponse
    {
        Gate::authorize('delete', $attachment);

        $this->attachments->delete($attachment);

        return back()->with('success', 'File removed.');
    }
}
