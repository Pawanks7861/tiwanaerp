<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Subcontract\WorkOrderTemplateProfile;
use App\Http\Controllers\Controller;
use App\Models\Core\DocumentTermsTemplate;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company library of document terms templates (PO / Work Order). Copied into documents — not live-linked.
 */
class DocumentTermsTemplateController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        $company = $this->tenancy->require();
        Gate::authorize('viewSettings', $company);

        $rows = DocumentTermsTemplate::query()
            ->orderBy('document_type')
            ->orderBy('name')
            ->get()
            ->map(fn (DocumentTermsTemplate $t) => $t->only([
                'id', 'name', 'document_type', 'template_profile', 'contractor_scope', 'company_scope',
                'payment_terms', 'safety_terms', 'general_terms', 'penalty_terms', 'is_active',
            ]));

        return Inertia::render('Admin/DocumentTerms/Index', [
            'templates' => $rows,
            'profiles' => WorkOrderTemplateProfile::options(),
            'can' => ['manage' => $request->user()->can('manageSettings', $company)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);

        $data = $this->validated($request);
        $template = new DocumentTermsTemplate;
        $template->fill($data);
        $template->save();

        return back()->with('success', "Terms template {$template->name} created.");
    }

    public function update(Request $request, DocumentTermsTemplate $documentTermsTemplate): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);
        abort_unless((int) $documentTermsTemplate->company_id === (int) $company->id, 404);

        $documentTermsTemplate->fill($this->validated($request))->save();

        return back()->with('success', 'Terms template updated.');
    }

    public function destroy(DocumentTermsTemplate $documentTermsTemplate): RedirectResponse
    {
        $company = $this->tenancy->require();
        Gate::authorize('manageSettings', $company);
        abort_unless((int) $documentTermsTemplate->company_id === (int) $company->id, 404);

        $documentTermsTemplate->delete();

        return back()->with('success', 'Terms template deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'document_type' => ['required', 'string', Rule::in(['purchase_order', 'work_order'])],
            'template_profile' => ['nullable', 'string', Rule::enum(WorkOrderTemplateProfile::class)],
            'contractor_scope' => ['nullable', 'string', 'max:10000'],
            'company_scope' => ['nullable', 'string', 'max:10000'],
            'payment_terms' => ['nullable', 'string', 'max:10000'],
            'safety_terms' => ['nullable', 'string', 'max:10000'],
            'general_terms' => ['nullable', 'string', 'max:10000'],
            'penalty_terms' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
