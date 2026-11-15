<?php

namespace App\Http\Controllers\Boq;

use App\Http\Controllers\Boq\Concerns\RequiresAdvancedBoqCalculations;
use App\Http\Controllers\Controller;
use App\Models\Boq\Boq;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Support\Pdf\AdvancedBoqCostingPdfDataBuilder;
use App\Support\Pdf\PdfResponder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AdvancedBoqCostingPdfController extends Controller
{
    use RequiresAdvancedBoqCalculations;

    public function __construct(private readonly AdvancedBoqCostingPdfDataBuilder $builder) {}

    public function pdf(Request $request, Project $project, Boq $boq): Response
    {
        $this->requireAdvancedBoqCalculations();
        abort_unless((int) $boq->project_id === (int) $project->id, 404);
        Gate::authorize('view', $boq);
        abort_unless($request->user()->can('boq.view_costs'), 403);

        $options = $this->optionsFromRequest($request);
        if ($request->filled('from_revision')) {
            $options['from_boq_id'] = (int) $request->input('from_revision');
        }
        if ($request->filled('to_revision')) {
            $options['to_boq_id'] = (int) $request->input('to_revision');
        }
        $company = Company::query()->findOrFail($boq->company_id);
        $data = $this->builder->build($boq, $project, $company, $options);

        $pdf = Pdf::loadView('pdf.advanced-boq-costing', $data)->setPaper('a4', 'landscape');
        $name = $this->filename($boq);

        return PdfResponder::send($pdf, $name, $request->boolean('inline') || $request->routeIs('projects.boqs.advanced-costing.pdf.view'));
    }

    /**
     * @return array{mode: string, sections: array<string, bool>}
     */
    private function optionsFromRequest(Request $request): array
    {
        $mode = $request->input('mode', 'detailed') === 'summary' ? 'summary' : 'detailed';
        $sections = [];
        foreach (AdvancedBoqCostingPdfDataBuilder::SECTION_KEYS as $key) {
            if ($request->has($key)) {
                $sections[$key] = $request->boolean($key);
            }
        }

        return ['mode' => $mode, 'sections' => $sections];
    }

    private function filename(Boq $boq): string
    {
        $safe = Str::slug($boq->boq_number) ?: 'boq';

        return "Advanced-BOQ-Costing-{$safe}-R{$boq->version}.pdf";
    }
}
