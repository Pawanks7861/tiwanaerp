<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Discipline;
use App\Enums\Imports\ImportMode;
use App\Models\Projects\Project;
use App\Models\Quality\QualityChecklist;
use App\Models\User;
use App\Services\Quality\QualityChecklistService;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class QualityChecklistImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(private readonly QualityChecklistService $checklists) {}

    public function key(): string
    {
        return 'quality.checklists';
    }

    public function label(): string
    {
        return 'Quality checklists';
    }

    public function permission(): string
    {
        return 'quality.import';
    }

    public function modes(): array
    {
        return [ImportMode::Create];
    }

    public function uniqueKey(): ?string
    {
        return null;
    }

    public function authorize(User $user, ?Project $project = null): bool
    {
        return $user->can($this->permission()) && $user->can('quality.perform_inspection');
    }

    public function instructions(): string
    {
        return 'Imports checklist templates only. Rows that share the same checklist name are grouped into one template. Completed inspections are never imported. Example rows labelled EXAMPLE are ignored.';
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_Quality_Checklists_Import_Template';
    }

    public function fields(): array
    {
        return [
            new ImportField('name', 'Checklist name', true, ['template', 'checklist']),
            new ImportField(
                'discipline',
                'Discipline',
                true,
                [],
                'text',
                array_map(fn (Discipline $d) => $d->value, Discipline::cases()),
            ),
            new ImportField('activity', 'Activity', false),
            new ImportField('is_active', 'Active', false, ['active', 'status'], 'boolean', ['Yes', 'No']),
            new ImportField('checkpoint', 'Checkpoint', true, ['item', 'check_point']),
            new ImportField('acceptance_criteria', 'Acceptance criteria', false, ['criteria', 'acceptance']),
        ];
    }

    public function exampleRows(): array
    {
        return [
            [
                'name' => 'EXAMPLE Concrete pour',
                'discipline' => 'civil',
                'activity' => 'Slab pour',
                'is_active' => 'Yes',
                'checkpoint' => 'Formwork checked',
                'acceptance_criteria' => 'EXAMPLE — ignored',
            ],
            [
                'name' => 'EXAMPLE Concrete pour',
                'discipline' => 'civil',
                'activity' => 'Slab pour',
                'is_active' => 'Yes',
                'checkpoint' => 'Cover blocks in place',
                'acceptance_criteria' => '',
            ],
        ];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $name = $this->str($mapped['name'] ?? null);
        $checkpoint = $this->str($mapped['checkpoint'] ?? null);
        $disciplineRaw = strtolower(str_replace([' ', '-'], '_', (string) ($this->str($mapped['discipline'] ?? null) ?? '')));

        $errors = [];
        if ($name === null) {
            $errors['name'] = ['Checklist name is required.'];
        }
        if ($checkpoint === null) {
            $errors['checkpoint'] = ['Checkpoint is required.'];
        }
        if ($disciplineRaw === '') {
            $errors['discipline'] = ['Discipline is required.'];
        }
        if ($errors !== []) {
            $this->fail($errors);
        }

        $discipline = Discipline::tryFrom($disciplineRaw);
        if ($discipline === null) {
            foreach (Discipline::cases() as $case) {
                if (strcasecmp($case->label(), $disciplineRaw) === 0) {
                    $discipline = $case;
                    break;
                }
            }
        }
        if ($discipline === null) {
            $this->fail(['discipline' => ['Unknown discipline.']]);
        }

        if (QualityChecklist::query()->where('name', $name)->exists()) {
            $this->fail(['name' => ["A checklist named \"{$name}\" already exists."]]);
        }

        $data = [
            'name' => $name,
            'discipline' => $discipline->value,
            'activity' => $this->str($mapped['activity'] ?? null),
            'is_active' => $this->boolish($mapped['is_active'] ?? null, true),
            'checkpoint' => $checkpoint,
            'acceptance_criteria' => $this->str($mapped['acceptance_criteria'] ?? null),
        ];

        Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'discipline' => ['required', Rule::enum(Discipline::class)],
            'checkpoint' => ['required', 'string', 'max:500'],
            'acceptance_criteria' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return [
            'action' => 'create',
            'data' => $data,
            'existing_id' => null,
            'preview' => ['name' => $name.' · '.$checkpoint],
        ];
    }

    public function consolidate(array $rows, array $summary, ImportContext $context): array
    {
        $groups = [];
        $out = [];
        $create = 0;
        $skip = 0;
        $invalid = 0;
        $valid = 0;

        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'invalid') {
                $out[] = $row;
                $invalid++;

                continue;
            }
            if (($row['action'] ?? '') === 'skip' || ($row['status'] ?? '') === 'skipped') {
                $out[] = $row;
                $skip++;

                continue;
            }

            $name = $row['prepared']['data']['name'] ?? null;
            if ($name === null) {
                $out[] = $row;
                $valid++;
                $create++;

                continue;
            }

            if (! isset($groups[$name])) {
                $groups[$name] = $row;
                $groups[$name]['prepared']['data']['items'] = [[
                    'checkpoint' => $row['prepared']['data']['checkpoint'],
                    'acceptance_criteria' => $row['prepared']['data']['acceptance_criteria'] ?? null,
                ]];
                unset($groups[$name]['prepared']['data']['checkpoint'], $groups[$name]['prepared']['data']['acceptance_criteria']);
            } else {
                $groups[$name]['prepared']['data']['items'][] = [
                    'checkpoint' => $row['prepared']['data']['checkpoint'],
                    'acceptance_criteria' => $row['prepared']['data']['acceptance_criteria'] ?? null,
                ];
                $out[] = [
                    'row' => $row['row'],
                    'status' => 'skipped',
                    'action' => 'skip',
                    'errors' => [],
                    'preview' => ['name' => "Merged into {$name}"],
                    'prepared' => ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => []],
                ];
                $skip++;
            }
        }

        foreach ($groups as $row) {
            $row['preview'] = [
                'name' => ($row['prepared']['data']['name'] ?? '').' ('.count($row['prepared']['data']['items']).' checkpoints)',
            ];
            $out[] = $row;
            $valid++;
            $create++;
        }

        $summary['valid'] = $valid;
        $summary['invalid'] = $invalid;
        $summary['skip'] = $skip;
        $summary['create'] = $create;
        $summary['update'] = 0;
        $summary['duplicate'] = 0;
        $summary['total'] = count($out);

        return [$out, $summary];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] !== 'create') {
            return 'skipped';
        }

        $data = $prepared['data'];
        if (! isset($data['items'])) {
            $data['items'] = [[
                'checkpoint' => $data['checkpoint'],
                'acceptance_criteria' => $data['acceptance_criteria'] ?? null,
            ]];
            unset($data['checkpoint'], $data['acceptance_criteria']);
        }

        $this->checklists->create($data);

        return 'created';
    }
}
