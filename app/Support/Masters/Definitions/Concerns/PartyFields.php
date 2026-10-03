<?php

namespace App\Support\Masters\Definitions\Concerns;

use App\Enums\IndianState;
use App\Support\Masters\IndianFormats;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared contact / tax / address fields for vendors, subcontractors and clients.
 */
trait PartyFields
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function contactFields(): array
    {
        return [
            ['name' => 'contact_person', 'label' => 'Contact person', 'type' => 'text', 'maxlength' => 150, 'section' => 'Contact'],
            ['name' => 'mobile', 'label' => 'Mobile', 'type' => 'tel', 'maxlength' => 20, 'section' => 'Contact'],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'section' => 'Contact'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function taxFields(): array
    {
        return [
            ['name' => 'gstin', 'label' => 'GSTIN', 'type' => 'text', 'maxlength' => 15, 'uppercase' => true, 'section' => 'Tax', 'help' => 'State is taken from the GSTIN when left blank.'],
            ['name' => 'pan', 'label' => 'PAN', 'type' => 'text', 'maxlength' => 10, 'uppercase' => true, 'section' => 'Tax'],
            ['name' => 'state_code', 'label' => 'State', 'type' => 'select', 'options' => 'states', 'section' => 'Tax'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function contactRules(): array
    {
        return [
            'contact_person' => ['nullable', 'string', 'max:150'],
            'mobile' => ['nullable', 'string', 'max:20', 'regex:'.IndianFormats::MOBILE],
            'email' => ['nullable', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function taxRules(): array
    {
        return [
            'gstin' => ['nullable', 'string', 'size:15', 'regex:'.IndianFormats::GSTIN],
            'pan' => ['nullable', 'string', 'size:10', 'regex:'.IndianFormats::PAN],
            'state_code' => ['nullable', Rule::enum(IndianState::class)],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'regex:'.IndianFormats::PINCODE],
        ];
    }

    /**
     * Derive state and PAN from the GSTIN; a GSTIN must match the chosen state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareTaxIdentity(array $data): array
    {
        $gstin = $data['gstin'] ?? null;
        if (! $gstin) {
            return $data;
        }

        $data['state_code'] = ($data['state_code'] ?? null) ?: substr($gstin, 0, 2);
        $data['pan'] = ($data['pan'] ?? null) ?: substr($gstin, 2, 10);

        if ($data['state_code'] !== substr($gstin, 0, 2)) {
            throw ValidationException::withMessages([
                'state_code' => 'The state does not match the GSTIN (first two digits are the state code).',
            ]);
        }

        if ($data['pan'] !== substr($gstin, 2, 10)) {
            throw ValidationException::withMessages([
                'pan' => 'The PAN does not match the GSTIN.',
            ]);
        }

        return $data;
    }
}
