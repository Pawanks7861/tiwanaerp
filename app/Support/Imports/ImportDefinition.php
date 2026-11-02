<?php

namespace App\Support\Imports;

use App\Enums\Imports\ImportMode;
use App\Models\Projects\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One importable ERP module. Validation and persistence go through existing services —
 * definitions never write ledgers or approve documents directly.
 */
abstract class ImportDefinition
{
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function permission(): string;

    /**
     * @return list<ImportMode>
     */
    abstract public function modes(): array;

    /**
     * @return list<ImportField>
     */
    abstract public function fields(): array;

    /**
     * @return list<array<string, mixed>>
     */
    abstract public function exampleRows(): array;

    public function instructions(): string
    {
        return 'Do not change the header row. Example rows are labelled EXAMPLE and are ignored. Blank rows are skipped.';
    }

    /**
     * Detailed lines for the Instructions sheet of the downloadable template.
     *
     * @return list<string>
     */
    public function instructionLines(): array
    {
        $required = [];
        $optional = [];
        foreach ($this->fields() as $field) {
            $line = $field->label.($field->help ? " — {$field->help}" : '');
            if ($field->required) {
                $required[] = $line;
            } else {
                $optional[] = $line;
            }
        }

        $modes = implode(', ', array_map(fn (ImportMode $m) => $m->label(), $this->modes()));
        $unique = $this->uniqueKey();

        $lines = [
            'Module: '.$this->label(),
            'Import key: '.$this->key(),
            '',
            $this->instructions(),
            '',
            'Supported import modes: '.$modes,
            'Date format: YYYY-MM-DD (e.g. 2026-04-01). Excel date cells are also accepted.',
            'Numbers: use plain digits without currency symbols. Decimals use a dot (e.g. 12.5).',
            'Duplicate handling: '.($unique
                ? "unique key is \"{$unique}\"; create mode rejects existing keys, update/upsert use it to match."
                : 'rows are appended; in-file duplicates are reported during validation.'),
            'Project scope: '.($this->requiresProject()
                ? 'Open Import from the project (or document) context. Cross-project rows are rejected.'
                : 'Company-scoped. No project is required.'),
            'Example rows: any cell starting with EXAMPLE is ignored and must not be treated as real data.',
            'Do not set approval or workflow status in the spreadsheet — imported records stay draft / unapproved.',
            '',
            'Required fields:',
            ...($required === [] ? ['(none)'] : array_map(fn ($l) => '• '.$l, $required)),
            '',
            'Optional fields:',
            ...($optional === [] ? ['(none)'] : array_map(fn ($l) => '• '.$l, $optional)),
        ];

        foreach ($this->fields() as $field) {
            if ($field->allowed) {
                $lines[] = '';
                $lines[] = "Allowed values for {$field->label}: ".implode(', ', $field->allowed);
            }
        }

        return $lines;
    }

    /**
     * Reference sheets for the template workbook (permission-scoped).
     * Each sheet: title, headings, rows (list of cell lists), optional note.
     *
     * @return list<array{title: string, headings: list<string>, rows: list<list<mixed>>, note?: string}>
     */
    public function referenceData(User $user, ?Project $project = null): array
    {
        $sheets = [];
        foreach ($this->fields() as $field) {
            if ($field->allowed === null || $field->allowed === []) {
                continue;
            }
            $sheets[] = [
                'title' => Str::limit($field->label, 28, ''),
                'headings' => [$field->label],
                'rows' => array_map(fn ($v) => [$v], $field->allowed),
                'note' => 'Use these values in the Import Data sheet for '.$field->label.'.',
            ];
        }

        return $sheets;
    }

    /**
     * Download basename without extension, e.g. Tiwana_ERP_Materials_Import_Template.
     */
    public function templateFilename(): string
    {
        $slug = Str::slug($this->label(), '_');
        $slug = $slug !== '' ? $slug : Str::slug(str_replace('.', '_', $this->key()), '_');
        $slug = preg_replace('/[^A-Za-z0-9_]+/', '', (string) $slug) ?: 'import';
        $slug = Str::studly(str_replace('_', ' ', $slug));

        return 'Tiwana_ERP_'.$slug.'_Import_Template';
    }

    public function requiresProject(): bool
    {
        return false;
    }

    public function uniqueKey(): ?string
    {
        return 'code';
    }

    public function authorize(User $user, ?Project $project = null): bool
    {
        return $user->can($this->permission());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function modeOptions(): array
    {
        return array_map(
            fn (ImportMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
            $this->modes(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fieldsPayload(): array
    {
        return array_map(fn (ImportField $field) => $field->toArray(), $this->fields());
    }

    /**
     * Resolve lookups and return attributes ready for the module service.
     * Throw ValidationException with attribute keys for field-level errors.
     *
     * @param  array<string, mixed>  $mapped  heading-mapped raw values
     * @return array{action: 'create'|'update'|'skip', data: array<string, mixed>, existing_id: int|null, preview: array<string, mixed>}
     */
    abstract public function prepareRow(array $mapped, ImportMode $mode, ImportContext $context): array;

    /**
     * Persist one prepared row through the existing service layer.
     *
     * @param  array{action: string, data: array<string, mixed>, existing_id: int|null}  $prepared
     * @return 'created'|'updated'|'skipped'
     */
    abstract public function commitRow(array $prepared, ImportMode $mode, ImportContext $context): string;

    /**
     * Optional post-pass after every row is prepared (e.g. group checklist checkpoints).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $summary
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    public function consolidate(array $rows, array $summary, ImportContext $context): array
    {
        return [$rows, $summary];
    }

    /**
     * Optional hook after all rows in a confirmed import are committed.
     */
    public function afterCommit(ImportContext $context): void
    {
        //
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function blankRow(array $mapped): bool
    {
        foreach ($mapped as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    protected function fail(array $errors): never
    {
        throw ValidationException::withMessages($errors);
    }
}
