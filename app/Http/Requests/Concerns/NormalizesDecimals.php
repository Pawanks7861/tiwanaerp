<?php

namespace App\Http\Requests\Concerns;

/**
 * JSON numbers arrive as int/float; decimal fields must reach the services as strings
 * (Decimal rejects floats). Paths may use "*" for list items, e.g. "rows.*.quantity".
 */
trait NormalizesDecimals
{
    /**
     * @return list<string>
     */
    abstract protected function decimalFields(): array;

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        foreach ($this->decimalFields() as $path) {
            $input = $this->normaliseDecimalPath($input, explode('.', $path));
        }
        $this->replace($input);
    }

    /**
     * @param  list<string>  $segments
     */
    private function normaliseDecimalPath(mixed $data, array $segments): mixed
    {
        if (! is_array($data) || $segments === []) {
            return $data;
        }

        $segment = array_shift($segments);
        $keys = $segment === '*' ? array_keys($data) : [$segment];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $data[$key] = $segments === [] ? $this->normaliseDecimalValue($data[$key]) : $this->normaliseDecimalPath($data[$key], $segments);
        }

        return $data;
    }

    private function normaliseDecimalValue(mixed $value): mixed
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
        }
        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
