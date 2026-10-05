<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\Tally\MissingTallyMappingException;
use App\Integrations\Tally\TallyDocumentRegistry;
use App\Integrations\Tally\TallyException;
use App\Integrations\Tally\TallySyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TallyDocumentController extends Controller
{
    public function __construct(
        private readonly TallyDocumentRegistry $documents,
        private readonly TallySyncService $sync,
    ) {}

    public function preview(Request $request, string $type, int $id): Response
    {
        abort_unless($request->user()->can('tally.sync'), 403);
        $model = $this->documents->find($type, $id);
        abort_if($model === null, 404);
        try {
            $voucher = $this->sync->preview($model);
        } catch (MissingTallyMappingException $exception) {
            return Inertia::render('Integrations/Tally/Preview', [
                'reference' => $this->documents->meta($model)['reference'],
                'error' => $exception->getMessage(),
                'voucher' => null,
            ]);
        } catch (TallyException $exception) {
            return Inertia::render('Integrations/Tally/Preview', [
                'reference' => $this->documents->meta($model)['reference'],
                'error' => $exception->getMessage(),
                'voucher' => null,
            ]);
        }

        return Inertia::render('Integrations/Tally/Preview', [
            'reference' => $voucher->reference,
            'error' => null,
            'voucher' => $voucher->toArray(),
        ]);
    }

    public function sync(Request $request, string $type, int $id): RedirectResponse
    {
        abort_unless($request->user()->can('tally.sync'), 403);
        $model = $this->documents->find($type, $id);
        abort_if($model === null, 404);
        $record = $this->sync->enqueue($model, 'export', true);
        $message = $record->status->value === 'synced'
            ? 'Already synced. No second voucher was created.'
            : 'Tally sync queued.';

        return back()->with('success', $message);
    }
}
