<?php

namespace App\Support\Masters\Definitions;

use App\Enums\Labour\IdProofType;
use App\Models\Labour\Labour;
use App\Models\Masters\LabourTrade;
use App\Models\Masters\Subcontractor;
use App\Models\Projects\Project;
use App\Rules\ExistsInCompany;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Labour register. The ID number is personal data: users without labour.update only see its
 * last four characters. ID copies and photos are private attachments.
 */
class LabourDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'labour';
    }

    public function model(): string
    {
        return Labour::class;
    }

    public function title(): string
    {
        return 'Labour Register';
    }

    public function singular(): string
    {
        return 'Labourer';
    }

    public function numberType(): ?string
    {
        return 'labour';
    }

    public function hasAttachments(): bool
    {
        return true;
    }

    public function with(): array
    {
        return ['trade:id,name', 'subcontractor:id,name', 'currentProject:id,code,name'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 30, 'uppercase' => true, 'section' => 'General'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'section' => 'General'],
            ['name' => 'mobile', 'label' => 'Mobile', 'type' => 'tel', 'maxlength' => 20, 'section' => 'General'],
            ['name' => 'labour_trade_id', 'label' => 'Trade', 'type' => 'select', 'options' => 'trades', 'required' => true, 'section' => 'General'],
            ['name' => 'subcontractor_id', 'label' => 'Subcontractor', 'type' => 'select', 'options' => 'subcontractors', 'section' => 'General', 'help' => 'Leave blank for company (departmental) labour.'],
            ['name' => 'current_project_id', 'label' => 'Current project', 'type' => 'select', 'options' => 'projects', 'section' => 'General', 'help' => 'Labourers of a project appear on its attendance sheet.'],
            ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'section' => 'General'],
            ['name' => 'daily_wage', 'label' => 'Daily wage', 'type' => 'money', 'section' => 'Wages', 'help' => 'Leave blank to use the trade\'s default wage.'],
            ['name' => 'ot_rate_per_hour', 'label' => 'OT rate per hour', 'type' => 'rate', 'section' => 'Wages'],
            ['name' => 'id_proof_type', 'label' => 'ID proof', 'type' => 'select', 'options' => 'id_proof_types', 'section' => 'Identity'],
            ['name' => 'id_proof_no', 'label' => 'ID number', 'type' => 'text', 'maxlength' => 50, 'uppercase' => true, 'section' => 'Identity'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => $this->codeRules('labours', $record),
            'name' => ['required', 'string', 'max:150'],
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\- ]{6,20}$/'],
            'labour_trade_id' => ['required', new ExistsInCompany(LabourTrade::class)],
            'subcontractor_id' => ['nullable', new ExistsInCompany(Subcontractor::class)],
            'current_project_id' => ['nullable', new ExistsInCompany(Project::class, fn ($q) => $q->visibleTo(auth()->user()))],
            'joining_date' => ['nullable', 'date', 'before_or_equal:today'],
            'daily_wage' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'ot_rate_per_hour' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,4'],
            'id_proof_type' => ['nullable', Rule::enum(IdProofType::class)],
            'id_proof_no' => ['nullable', 'required_with:id_proof_type', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ];
    }

    public function prepare(array $data, ?Model $record): array
    {
        if (blank($data['daily_wage'] ?? null)) {
            $default = LabourTrade::query()->whereKey($data['labour_trade_id'] ?? 0)->value('default_daily_wage');
            if ($default === null) {
                throw ValidationException::withMessages(['daily_wage' => 'Enter the daily wage (the trade has no default wage).']);
            }
            $data['daily_wage'] = $default;
        }
        $data['ot_rate_per_hour'] = blank($data['ot_rate_per_hour'] ?? null) ? '0' : $data['ot_rate_per_hour'];

        return $data;
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'type' => 'code'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'trade_name', 'label' => 'Trade'],
            ['key' => 'daily_wage', 'label' => 'Daily wage', 'type' => 'money'],
            ['key' => 'project_name', 'label' => 'Project', 'mobile' => false],
            ['key' => 'subcontractor_name', 'label' => 'Subcontractor', 'mobile' => false],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function row(Model $record): array
    {
        /** @var Labour $record */
        $row = parent::row($record);
        $row['joining_date'] = $record->joining_date?->toDateString();
        if (! auth()->user()?->can('labour.update')) {
            $row['id_proof_no'] = $record->maskedIdProofNo();
        }

        return $row + [
            'trade_name' => $record->trade?->name,
            'subcontractor_name' => $record->subcontractor?->name,
            'project_name' => $record->currentProject ? "{$record->currentProject->code} · {$record->currentProject->name}" : null,
        ];
    }

    public function options(Request $request): array
    {
        return [
            'trades' => $this->toOptions(LabourTrade::query()->active()->orderBy('name')->get(['id', 'name'])),
            'subcontractors' => $this->toOptions(Subcontractor::query()->active()->orderBy('name')->get(['id', 'name'])),
            'projects' => Project::query()->visibleTo($request->user())->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn ($p) => ['value' => $p->id, 'label' => "{$p->code} · {$p->name}"])->all(),
            'id_proof_types' => IdProofType::options(),
        ];
    }
}
