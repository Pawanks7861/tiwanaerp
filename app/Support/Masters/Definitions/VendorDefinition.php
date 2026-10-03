<?php

namespace App\Support\Masters\Definitions;

use App\Enums\IndianState;
use App\Models\Masters\Vendor;
use App\Support\Masters\Definitions\Concerns\PartyFields;
use App\Support\Masters\IndianFormats;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class VendorDefinition extends MasterDefinition
{
    use PartyFields;

    public function slug(): string
    {
        return 'vendors';
    }

    public function model(): string
    {
        return Vendor::class;
    }

    public function title(): string
    {
        return 'Vendors';
    }

    public function singular(): string
    {
        return 'Vendor';
    }

    public function numberType(): ?string
    {
        return 'vendor';
    }

    public function hasAttachments(): bool
    {
        return true;
    }

    protected function table(): string
    {
        return 'vendors';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function identityFields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 30, 'uppercase' => true, 'section' => 'General'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 200, 'section' => 'General'],
        ];
    }

    public function fields(): array
    {
        return [
            ...$this->identityFields(),
            ...$this->contactFields(),
            ...$this->taxFields(),
            ['name' => 'address', 'label' => 'Address', 'type' => 'textarea', 'span' => 'full', 'section' => 'Address'],
            ['name' => 'city', 'label' => 'City', 'type' => 'text', 'maxlength' => 100, 'section' => 'Address'],
            ['name' => 'pincode', 'label' => 'PIN code', 'type' => 'text', 'maxlength' => 6, 'section' => 'Address'],
            ['name' => 'payment_terms', 'label' => 'Payment terms', 'type' => 'text', 'maxlength' => 255, 'span' => 'full', 'section' => 'Bank & terms', 'placeholder' => '30 days from invoice'],
            ['name' => 'bank_name', 'label' => 'Bank name', 'type' => 'text', 'maxlength' => 150, 'section' => 'Bank & terms'],
            ['name' => 'bank_account_no', 'label' => 'Account number', 'type' => 'text', 'maxlength' => 30, 'section' => 'Bank & terms'],
            ['name' => 'bank_ifsc', 'label' => 'IFSC', 'type' => 'text', 'maxlength' => 11, 'uppercase' => true, 'section' => 'Bank & terms'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'section' => 'General'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => $this->codeRules($this->table(), $record),
            'name' => ['required', 'string', 'max:200'],
            ...$this->contactRules(),
            ...$this->taxRules(),
            'address' => ['nullable', 'string', 'max:1000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account_no' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]{6,30}$/'],
            'bank_ifsc' => ['nullable', 'string', 'regex:'.IndianFormats::IFSC],
            'is_active' => ['boolean'],
        ];
    }

    public function prepare(array $data, ?Model $record): array
    {
        return $this->prepareTaxIdentity($data);
    }

    public function columns(): array
    {
        return [
            ['key' => 'code', 'label' => 'Code', 'type' => 'code'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'contact_person', 'label' => 'Contact', 'mobile' => false],
            ['key' => 'mobile', 'label' => 'Mobile'],
            ['key' => 'gstin', 'label' => 'GSTIN', 'type' => 'code', 'mobile' => false],
            ['key' => 'city', 'label' => 'City', 'mobile' => false],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function options(Request $request): array
    {
        return ['states' => IndianState::options()];
    }
}
