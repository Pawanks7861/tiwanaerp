<?php

namespace App\Support\Masters;

use App\Models\Masters\MasterModel;
use App\Policies\MasterPolicy;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Describes one company master for the shared master CRUD (controller, request, Vue screen).
 * Field and column arrays are sent to the frontend; rules() is the server-side source of truth.
 */
abstract class MasterDefinition
{
    abstract public function slug(): string;

    /** @return class-string<MasterModel> */
    abstract public function model(): string;

    abstract public function title(): string;

    abstract public function singular(): string;

    /**
     * Form fields: name, label, type (text|textarea|email|tel|number|money|rate|qty|percent|select|switch),
     * optional: required, options (key into options()), span (full), section, uppercase, maxlength, help, placeholder.
     *
     * @return list<array<string, mixed>>
     */
    abstract public function fields(): array;

    /**
     * @return array<string, mixed>
     */
    abstract public function rules(?Model $record): array;

    /**
     * List columns: key, label, type (text|code|money|rate|qty|percent|status), optional: mobile (bool).
     *
     * @return list<array<string, mixed>>
     */
    abstract public function columns(): array;

    public function permission(): string
    {
        return MasterPolicy::PERMISSIONS[$this->model()];
    }

    /** Document number type used to auto-generate the code when left blank. */
    public function numberType(): ?string
    {
        return null;
    }

    public function nameColumn(): string
    {
        return 'name';
    }

    public function hasAttachments(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function with(): array
    {
        return [];
    }

    /**
     * Select options for the form, keyed by the field's "options" key.
     *
     * @return array<string, list<array{value: mixed, label: string}>>
     */
    public function options(Request $request): array
    {
        return [];
    }

    /**
     * Normalise validated data before saving (derive values, uppercase identifiers, ...).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(array $data, ?Model $record): array
    {
        return $data;
    }

    /**
     * Fields uppercased before validation (codes, GSTIN, PAN, IFSC).
     *
     * @return list<string>
     */
    public function uppercase(): array
    {
        return array_values(array_map(
            fn ($f) => $f['name'],
            array_filter($this->fields(), fn ($f) => $f['uppercase'] ?? false),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Model $record): array
    {
        $row = ['id' => $record->getKey()];
        foreach ($this->fields() as $field) {
            $value = $record->getAttribute($field['name']);
            $row[$field['name']] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $row;
    }

    /**
     * @param  Builder<MasterModel>  $query
     */
    public function applySort(Builder $query): void
    {
        $query->orderBy($this->nameColumn());
    }

    /**
     * @return array<string, mixed>
     */
    public function toFrontend(): array
    {
        return [
            'slug' => $this->slug(),
            'title' => $this->title(),
            'singular' => $this->singular(),
            'fields' => $this->fields(),
            'columns' => $this->columns(),
            'nameColumn' => $this->nameColumn(),
            'autoCode' => $this->numberType() !== null,
            'hasAttachments' => $this->hasAttachments(),
        ];
    }

    protected function unique(string $table, string $column, ?Model $record): Unique
    {
        return Rule::unique($table, $column)
            ->where('company_id', app(CurrentCompany::class)->id())
            ->ignore($record?->getKey());
    }

    /**
     * Code rule: optional when auto-numbered on create, required otherwise.
     *
     * @return list<mixed>
     */
    protected function codeRules(string $table, ?Model $record): array
    {
        $presence = $this->numberType() !== null && $record === null ? 'nullable' : 'required';

        return [$presence, 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9\-\/_.]*$/', $this->unique($table, 'code', $record)];
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<array{value: mixed, label: string}>
     */
    protected function toOptions(iterable $models, string $label = 'name'): array
    {
        $options = [];
        foreach ($models as $model) {
            $options[] = ['value' => $model->getKey(), 'label' => (string) $model->getAttribute($label)];
        }

        return $options;
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return list<array{value: string, label: string}>
     */
    protected function enumOptions(string $enum): array
    {
        return array_map(
            fn ($case) => ['value' => $case->value, 'label' => method_exists($case, 'label') ? $case->label() : $case->name],
            $enum::cases(),
        );
    }
}
