<?php

namespace App\Support\Imports\Definitions;

use App\Enums\Imports\ImportMode;
use App\Enums\IndianState;
use App\Models\Crm\Client;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Support\Crm\DefaultClientResolver;
use App\Support\Imports\Concerns\NormalizesImportValues;
use App\Support\Imports\ImportContext;
use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectImportDefinition extends ImportDefinition
{
    use NormalizesImportValues;

    public function __construct(private readonly ProjectService $projects) {}

    public function key(): string
    {
        return 'projects';
    }

    public function label(): string
    {
        return 'Projects';
    }

    public function permission(): string
    {
        return 'projects.import';
    }

    public function modes(): array
    {
        return [ImportMode::Create, ImportMode::Update, ImportMode::Upsert];
    }

    public function templateFilename(): string
    {
        return 'Tiwana_ERP_Projects_Import_Template';
    }

    public function fields(): array
    {
        return [
            new ImportField('code', 'Project code', false, ['project_code', 'project_number'], help: 'Optional on create; unique per company.'),
            new ImportField('name', 'Project name', true, ['project_name', 'title']),
            new ImportField('client', 'Client', false, ['client_name', 'client_code'], help: 'Existing client code or name.'),
            new ImportField('project_type', 'Project type', false, ['type']),
            new ImportField('city', 'Location / city', false, ['location', 'city_name']),
            new ImportField('state_code', 'State code', false, ['state'], help: 'Indian state code, e.g. HR.'),
            new ImportField('address', 'Address', false),
            new ImportField('start_date', 'Start date', false, ['start'], 'date'),
            new ImportField('expected_end_date', 'End date', false, ['end_date', 'expected_completion'], 'date'),
            new ImportField('project_manager', 'Project manager', false, ['manager', 'pm', 'project_manager_email'], help: 'Active company member email or name.'),
            new ImportField('contract_value', 'Contract value', false, ['value'], 'number'),
            new ImportField('description', 'Description', false),
        ];
    }

    public function exampleRows(): array
    {
        return [[
            'code' => 'EXAMPLE-PRJ',
            'name' => 'Example Residential Tower',
            'client' => '',
            'project_type' => 'Residential',
            'city' => 'Gurugram',
            'state_code' => 'HR',
            'address' => 'EXAMPLE ROW — ignored',
            'start_date' => '2026-04-01',
            'expected_end_date' => '2027-03-31',
            'project_manager' => '',
            'contract_value' => '10000000',
            'description' => 'EXAMPLE',
        ]];
    }

    public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array
    {
        if ($this->blankRow($mapped) || $this->isExampleRow($mapped)) {
            return ['action' => 'skip', 'data' => [], 'existing_id' => null, 'preview' => ['name' => '(skipped)']];
        }

        $code = $this->str($mapped['code'] ?? null);
        if ($code !== null) {
            $code = strtoupper($code);
        }

        $existing = $code
            ? Project::query()->whereRaw('LOWER(code) = ?', [strtolower($code)])->first()
            : null;

        $action = match ($mode) {
            ImportMode::Create => 'create',
            ImportMode::Update => 'update',
            ImportMode::Upsert => $existing ? 'update' : 'create',
        };

        if ($action === 'update' && ! $existing) {
            $this->fail(['code' => ['No existing project found for this code.']]);
        }
        if ($action === 'create' && $existing) {
            $this->fail(['code' => ['A project with this code already exists.']]);
        }

        $data = [
            'code' => $code,
            'name' => $this->str($mapped['name'] ?? null),
            'client_id' => $this->resolveClient($this->str($mapped['client'] ?? null), $context),
            'project_type' => $this->str($mapped['project_type'] ?? null),
            'city' => $this->str($mapped['city'] ?? null),
            'state_code' => ($state = $this->str($mapped['state_code'] ?? null)) ? strtoupper($state) : null,
            'address' => $this->str($mapped['address'] ?? null),
            'start_date' => $this->date($mapped['start_date'] ?? null),
            'expected_end_date' => $this->date($mapped['expected_end_date'] ?? null),
            'project_manager_id' => $this->resolveManager($this->str($mapped['project_manager'] ?? null)),
            'contract_value' => $this->number($mapped['contract_value'] ?? null) ?? '0',
            'description' => $this->str($mapped['description'] ?? null),
        ];

        $rules = [
            'code' => [
                $action === 'update' ? 'required' : 'nullable', 'string', 'max:12', 'regex:/^[A-Z0-9][A-Z0-9\-]*$/',
                Rule::unique('projects', 'code')
                    ->where('company_id', $context->company->id)
                    ->ignore($existing?->id),
            ],
            'name' => ['required', 'string', 'max:200'],
            'client_id' => ['nullable', 'integer'],
            'project_type' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:5000'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'state_code' => ['nullable', Rule::enum(IndianState::class)],
            'project_manager_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'expected_end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $validated['contract_value'] = $validated['contract_value'] ?? '0';

        return [
            'action' => $action,
            'data' => $validated,
            'existing_id' => $existing?->id,
            'preview' => [
                'code' => $validated['code'] ?? $existing?->code,
                'name' => $validated['name'],
            ],
        ];
    }

    public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string
    {
        if ($prepared['action'] === 'skip') {
            return 'skipped';
        }

        if ($prepared['action'] === 'update') {
            $project = Project::query()->whereKey($prepared['existing_id'])->firstOrFail();
            $this->projects->update($project, $prepared['data']);

            return 'updated';
        }

        $this->projects->create($prepared['data']);

        return 'created';
    }

    private function resolveClient(?string $text, ImportContext $context): ?int
    {
        $resolver = app(DefaultClientResolver::class);

        if ($text === null) {
            return $resolver->ensure($context->company)->id;
        }

        $client = Client::query()
            ->where('company_id', $context->company->id)
            ->where(function ($q) use ($text) {
                $q->whereRaw('LOWER(code) = ?', [strtolower($text)])
                    ->orWhereRaw('LOWER(company_name) = ?', [strtolower($text)]);
            })
            ->first();

        if (! $client) {
            $this->fail(['client' => ["Unknown client \"{$text}\". Create the client first."]]);
        }

        $defaultId = $resolver->id($context->company);
        if ($defaultId !== null && (int) $client->id !== $defaultId) {
            $this->fail(['client' => ['Client must be '.$resolver->require($context->company)->company_name.'.']]);
        }

        return (int) $client->id;
    }

    private function resolveManager(?string $text): ?int
    {
        if ($text === null) {
            return null;
        }

        $user = User::query()
            ->where(function ($q) use ($text) {
                $q->whereRaw('LOWER(email) = ?', [strtolower($text)])
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($text)]);
            })
            ->where('is_active', true)
            ->first();

        if (! $user) {
            $this->fail(['project_manager' => ["Unknown project manager \"{$text}\". Use an active user's email or name."]]);
        }

        return (int) $user->id;
    }
}
