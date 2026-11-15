<?php

namespace App\Http\Controllers\Boq;

use App\Http\Controllers\Controller;
use App\Models\Boq\Boq;
use App\Models\Boq\BoqRevisionChangeOrder;
use App\Models\Core\Company;
use App\Models\Projects\Project;
use App\Services\Boq\BoqRevisionChangeOrderService;
use App\Support\Pdf\DocumentPdfSettings;
use App\Support\Pdf\PdfResponder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class BoqRevisionChangeOrderPdfController extends Controller
{
    public function __construct(private readonly BoqRevisionChangeOrderService $orders) {}

    public function internal(Request $request, Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): Response
    {
        $this->assertOrder($project, $boq, $changeOrder);
        Gate::authorize('downloadInternalPdf', $changeOrder);

        return $this->render($request, $project, $changeOrder, 'pdf.boq-revision-change-order-internal', true, 'Internal');
    }

    public function client(Request $request, Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): Response
    {
        $this->assertOrder($project, $boq, $changeOrder);
        Gate::authorize('downloadClientPdf', $changeOrder);

        return $this->render($request, $project, $changeOrder, 'pdf.boq-revision-change-order-client', false, 'Client');
    }

    private function render(
        Request $request,
        Project $project,
        BoqRevisionChangeOrder $changeOrder,
        string $view,
        bool $withCosts,
        string $suffix,
    ): Response {
        $company = Company::query()->findOrFail($changeOrder->company_id);
        $changeOrder->load(['fromBoq', 'toBoq', 'project', 'approvedBy:id,name', 'submittedBy:id,name']);

        $data = [
            'project' => $project,
            'company' => $company,
            'settings' => DocumentPdfSettings::for($company),
            ...$this->orders->pdfPayload($changeOrder, $withCosts),
        ];

        $pdf = Pdf::loadView($view, $data)->setPaper('a4', 'landscape');
        $safe = Str::slug($changeOrder->change_order_number) ?: 'change-order';
        $name = "BOQ-Change-Order-{$safe}-{$suffix}.pdf";
        $inline = $request->boolean('inline') || str_ends_with((string) $request->route()->getName(), '.view');

        return PdfResponder::send($pdf, $name, $inline);
    }

    private function assertOrder(Project $project, Boq $boq, BoqRevisionChangeOrder $changeOrder): void
    {
        abort_unless((int) $changeOrder->project_id === (int) $project->id, 404);
        abort_unless((string) $changeOrder->boq_number === (string) $boq->boq_number, 404);
    }
}
