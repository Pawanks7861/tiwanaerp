<?php

namespace App\Http\Requests\Masters;

use App\Models\Masters\MasterModel;
use App\Support\Masters\MasterDefinition;
use App\Support\Masters\MasterRegistry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create / update request for any master. Rules come from the master's definition; the record
 * is resolved through the company-scoped model (another company's ID is a 404).
 */
class MasterRequest extends FormRequest
{
    private ?MasterModel $resolvedRecord = null;

    public function definition(): MasterDefinition
    {
        return MasterRegistry::get((string) $this->route('master'));
    }

    public function record(): ?MasterModel
    {
        $id = $this->route('record');
        if ($id === null) {
            return null;
        }

        return $this->resolvedRecord ??= $this->definition()->model()::query()->findOrFail($id);
    }

    public function authorize(): bool
    {
        $record = $this->record();

        return $record
            ? $this->user()->can('update', $record)
            : $this->user()->can('create', $this->definition()->model());
    }

    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach ($this->definition()->fields() as $field) {
            $name = $field['name'];
            if (! $this->has($name)) {
                continue;
            }

            $value = $this->input($name);
            if (is_string($value)) {
                $value = trim($value);
                if (in_array($name, $this->definition()->uppercase(), true)) {
                    $value = strtoupper($value);
                }
                $normalised[$name] = $value === '' ? null : $value;
            }
        }

        if (! $this->has('is_active') && $this->route('record') === null) {
            $normalised['is_active'] = true;
        }

        $this->merge($normalised);
    }

    public function rules(): array
    {
        return $this->definition()->rules($this->record());
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->definition()->fields() as $field) {
            $attributes[$field['name']] = strtolower($field['label']);
        }

        return $attributes;
    }
}
