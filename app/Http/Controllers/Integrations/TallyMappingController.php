<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\Tally\TallyMappingCatalog;
use App\Models\Crm\Client;
use App\Models\Integrations\TallyCostCentreMapping;
use App\Models\Integrations\TallyLedgerMapping;
use App\Models\Masters\ExpenseCategory;
use App\Models\Masters\Subcontractor;
use App\Models\Masters\Vendor;
use App\Models\Projects\Project;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TallyMappingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): Response
    {
        abort_unless($request->user()->can('tally.mapping') || $request->user()->can('tally.view'), 403);
        $saved = TallyLedgerMapping::query()->get()->keyBy('map_key');

        return Inertia::render('Integrations/Tally/Mappings', [
            'systems' => collect(TallyMappingCatalog::systems())->map(function (array $row) use ($saved) {
                $key = TallyMappingCatalog::mapKey($row['key']);
                $mapping = $saved->get($key);

                return [
                    'map_key' => $key,
                    'mapping_type' => $row['type'],
                    'label' => $row['label'],
                    'tally_ledger_name' => $mapping->tally_ledger_name ?? '',
                    'tally_parent_group' => $mapping->tally_parent_group ?? $row['parent'],
                    'auto_create_allowed' => (bool) ($mapping->auto_create_allowed ?? false),
                ];
            })->all(),
            'parties' => $saved->filter(fn (TallyLedgerMapping $mapping) => $mapping->source_id !== null)->values()->map(fn (TallyLedgerMapping $mapping) => [
                'id' => $mapping->id,
                'mapping_type' => $mapping->mapping_type,
                'source_type' => $mapping->source_type,
                'source_id' => $mapping->source_id,
                'tally_ledger_name' => $mapping->tally_ledger_name,
                'tally_parent_group' => $mapping->tally_parent_group,
                'auto_create_allowed' => $mapping->auto_create_allowed,
            ]),
            'cost_centres' => TallyCostCentreMapping::query()->with('project:id,code,name')->get()->map(fn (TallyCostCentreMapping $mapping) => [
                'id' => $mapping->id,
                'project_id' => $mapping->project_id,
                'project' => $mapping->project ? $mapping->project->code.' — '.$mapping->project->name : 'Project',
                'tally_cost_centre_name' => $mapping->tally_cost_centre_name,
            ]),
            'sources' => [
                'client' => Client::query()->orderBy('company_name')->get(['id', 'company_name'])->map(fn (Client $client) => ['value' => $client->id, 'label' => $client->company_name]),
                'vendor' => Vendor::query()->orderBy('name')->get(['id', 'name'])->map(fn (Vendor $vendor) => ['value' => $vendor->id, 'label' => $vendor->name]),
                'subcontractor' => Subcontractor::query()->orderBy('name')->get(['id', 'name'])->map(fn (Subcontractor $subcontractor) => ['value' => $subcontractor->id, 'label' => $subcontractor->name]),
                'expense_category' => ExpenseCategory::query()->orderBy('name')->get(['id', 'name'])->map(fn (ExpenseCategory $category) => ['value' => $category->id, 'label' => $category->name]),
            ],
            'projects' => Project::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn (Project $project) => [
                'value' => $project->id, 'label' => $project->code.' — '.$project->name,
            ]),
            'can' => ['mapping' => $request->user()->can('tally.mapping')],
        ]);
    }

    public function updateSystems(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tally.mapping'), 403);
        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.map_key' => ['required', 'string', 'max:120'],
            'rows.*.mapping_type' => ['required', 'string', 'max:40'],
            'rows.*.tally_ledger_name' => ['nullable', 'string', 'max:255'],
            'rows.*.tally_parent_group' => ['nullable', 'string', 'max:255'],
            'rows.*.auto_create_allowed' => ['required', 'boolean'],
        ]);
        $allowed = collect(TallyMappingCatalog::systems())->map(fn (array $row) => TallyMappingCatalog::mapKey($row['key']))->all();
        foreach ($data['rows'] as $row) {
            if (! in_array($row['map_key'], $allowed, true) || trim((string) $row['tally_ledger_name']) === '') {
                continue;
            }
            $mapping = TallyLedgerMapping::query()->firstOrNew(['map_key' => $row['map_key']]);
            $mapping->forceFill([
                'mapping_type' => $row['mapping_type'],
                'source_key' => str_replace('system:', '', $row['map_key']),
                'tally_ledger_name' => trim($row['tally_ledger_name']),
                'tally_parent_group' => $row['tally_parent_group'] ?: null,
                'auto_create_allowed' => $row['auto_create_allowed'],
                'active' => true,
            ])->save();
            $this->audit->record($mapping, 'tally_mapping_updated', null, ['map_key' => $mapping->map_key, 'ledger' => $mapping->tally_ledger_name]);
        }

        return back()->with('success', 'Ledger mappings saved.');
    }

    public function storeParty(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tally.mapping'), 403);
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['client', 'vendor', 'subcontractor', 'expense_category'])],
            'source_id' => ['required', 'integer'],
            'tally_ledger_name' => ['required', 'string', 'max:255'],
            'tally_parent_group' => ['nullable', 'string', 'max:255'],
            'auto_create_allowed' => ['required', 'boolean'],
        ]);
        $key = $data['source_type'].':'.$data['source_id'];
        $mapping = TallyLedgerMapping::query()->firstOrNew(['map_key' => $key]);
        $mapping->forceFill([
            'mapping_type' => $data['source_type'],
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'],
            'tally_ledger_name' => trim($data['tally_ledger_name']),
            'tally_parent_group' => $data['tally_parent_group'] ?: $this->parent($data['source_type']),
            'auto_create_allowed' => $data['auto_create_allowed'],
            'active' => true,
        ])->save();
        $this->audit->record($mapping, 'tally_mapping_updated', null, ['map_key' => $key, 'ledger' => $mapping->tally_ledger_name]);

        return back()->with('success', 'Party mapping saved.');
    }

    public function storeCostCentre(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('tally.mapping'), 403);
        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'tally_cost_centre_name' => ['required', 'string', 'max:255'],
        ]);
        Project::query()->findOrFail($data['project_id']);
        $mapping = TallyCostCentreMapping::query()->firstOrNew(['project_id' => $data['project_id']]);
        $mapping->forceFill([
            'tally_cost_centre_name' => trim($data['tally_cost_centre_name']),
            'status' => 'mapped',
        ])->save();

        return back()->with('success', 'Cost centre mapping saved.');
    }

    private function parent(string $type): string
    {
        return match ($type) {
            'vendor', 'subcontractor' => 'Sundry Creditors',
            'expense_category' => 'Indirect Expenses',
            default => 'Sundry Debtors',
        };
    }
}
