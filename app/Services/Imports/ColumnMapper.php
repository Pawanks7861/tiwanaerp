<?php

namespace App\Services\Imports;

use App\Support\Imports\ImportDefinition;
use App\Support\Imports\ImportField;
use Illuminate\Support\Str;

class ColumnMapper
{
    /**
     * Suggest a mapping from spreadsheet headers to definition field keys.
     *
     * @param  list<string>  $headers  slugified headers from WithHeadingRow
     * @return array<string, string|null> fieldKey => headerKey
     */
    public function suggest(ImportDefinition $definition, array $headers): array
    {
        $available = [];
        foreach ($headers as $header) {
            $available[$this->normalize($header)] = $header;
        }

        $mapping = [];
        foreach ($definition->fields() as $field) {
            $mapping[$field->key] = $this->matchField($field, $available);
        }

        return $mapping;
    }

    /**
     * @param  array<string, string|null>  $mapping  fieldKey => headerKey
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function apply(array $mapping, array $row): array
    {
        $mapped = [];
        foreach ($mapping as $fieldKey => $headerKey) {
            if ($headerKey === null || $headerKey === '') {
                $mapped[$fieldKey] = null;

                continue;
            }

            $mapped[$fieldKey] = $row[$headerKey] ?? $row[Str::slug($headerKey, '_')] ?? null;
        }

        return $mapped;
    }

    /**
     * @param  array<string, string>  $available  normalized => original header key
     */
    private function matchField(ImportField $field, array $available): ?string
    {
        $candidates = array_merge(
            [$field->key, $field->label],
            $field->aliases,
        );

        foreach ($candidates as $candidate) {
            $normalized = $this->normalize($candidate);
            if (isset($available[$normalized])) {
                return $available[$normalized];
            }
        }

        return null;
    }

    private function normalize(string $value): string
    {
        return Str::slug(Str::lower($value), '_');
    }
}
