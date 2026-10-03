<?php

namespace App\Support\Masters\Definitions;

use App\Enums\WarehouseType;
use App\Models\Masters\Warehouse;
use App\Models\Projects\Project;
use App\Models\Projects\Site;
use App\Rules\ExistsInCompany;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseDefinition extends MasterDefinition
{
    public function slug(): string
    {
        return 'warehouses';
    }

    public function model(): string
    {
        return Warehouse::class;
    }

    public function title(): string
    {
        return 'Warehouses / Stores';
    }

    public function singular(): string
    {
        return 'Warehouse';
    }

    public function numberType(): ?string
    {
        return 'warehouse';
    }

    public function with(): array
    {
        return ['project:id,name,code', 'site:id,name'];
    }

    public function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 30, 'uppercase' => true],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 150],
            ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => 'types', 'required' => true, 'help' => 'Site stores belong to a project; central stores serve all projects.'],
            ['name' => 'project_id', 'label' => 'Project', 'type' => 'select', 'options' => 'projects', 'showWhen' => ['type' => 'site']],
            ['name' => 'site_id', 'label' => 'Site', 'type' => 'select', 'options' => 'sites', 'filterBy' => 'project_id', 'showWhen' => ['type' => 'site']],
            ['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'span' => 'full'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => $this->codeRules('warehouses', $record),
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(WarehouseType::class)],
            'project_id' => ['nullable', 'required_if:type,site', new ExistsInCompany(Project::class, fn ($q) => $q->visibleTo(auth()->user()))],
            'site_id' => ['nullable', new ExistsInCompany(Site::class)],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    public function prepare(array $data, ?Model $record): array
    {
        if (($data['type'] ?? null) === WarehouseType::Central->value) {
            $data['project_id'] = null;
            $data['site_id'] = null;

            return $data;
        }

        if (! empty($data['site_id'])) {
            $belongs = Site::query()->whereKey($data['site_id'])->where('project_id', $data['project_id'] ?? 0)->exists();
            if (! $belongs) {
                throw ValidationException::withMessages(['site_id' => 'The selected site does not belong to the selected project.']);
            }
        }

        return $data;
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'type' => 'code'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'type_label', 'label' => 'Type'],
            ['key' => 'project_name', 'label' => 'Project', 'mobile' => false],
            ['key' => 'site_name', 'label' => 'Site', 'mobile' => false],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function row(Model $record): array
    {
        /** @var Warehouse $record */
        return parent::row($record) + [
            'type_label' => $record->type?->label(),
            'project_name' => $record->project ? "{$record->project->code} · {$record->project->name}" : null,
            'site_name' => $record->site?->name,
        ];
    }

    public function options(Request $request): array
    {
        $projects = Project::query()->visibleTo($request->user())->orderBy('name')->get(['id', 'code', 'name']);

        return [
            'types' => WarehouseType::options(),
            'projects' => $projects->map(fn ($p) => ['value' => $p->id, 'label' => "{$p->code} · {$p->name}"])->all(),
            'sites' => Site::query()->whereIn('project_id', $projects->pluck('id'))->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'project_id'])
                ->map(fn ($s) => ['value' => $s->id, 'label' => $s->name, 'project_id' => $s->project_id])->all(),
        ];
    }
}
