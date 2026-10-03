<?php

namespace App\Http\Controllers\Boq;

use App\Exports\BoqExport;
use App\Exports\BoqTemplateExport;
use App\Http\Controllers\Controller;
use App\Models\Boq\Boq;
use App\Models\Projects\Project;
use App\Services\Boq\BoqImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BoqExcelController extends Controller
{
    public function __construct(private readonly BoqImportService $imports) {}

    public function template(Request $request, Project $project): BinaryFileResponse
    {
        Gate::authorize('create', [Boq::class, $project]);
        abort_unless($request->user()->can('boq.import'), 403);

        return Excel::download(new BoqTemplateExport, 'boq-import-template.xlsx');
    }

    public function import(Request $request, Project $project, Boq $boq): RedirectResponse
    {
        Gate::authorize('import', $boq);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv'],
        ]);

        $count = $this->imports->import($boq, $request->file('file'), $request->user()->can('boq.view_costs'));

        return back()->with('success', "{$count} line(s) imported.");
    }

    public function export(Request $request, Project $project, Boq $boq): BinaryFileResponse
    {
        Gate::authorize('export', $boq);

        $name = Str::slug("{$boq->boq_number}-v{$boq->version}").'.xlsx';

        return Excel::download(new BoqExport($boq, $request->user()->can('boq.view_costs')), $name);
    }
}
