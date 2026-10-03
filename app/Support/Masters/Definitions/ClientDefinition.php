<?php

namespace App\Support\Masters\Definitions;

use App\Enums\IndianState;
use App\Models\Crm\Client;
use App\Support\Masters\Definitions\Concerns\PartyFields;
use App\Support\Masters\MasterDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ClientDefinition extends MasterDefinition
{
    use PartyFields;

    public function slug(): string
    {
        return 'clients';
    }

    public function model(): string
    {
        return Client::class;
    }

    public function title(): string
    {
        return 'Clients';
    }

    public function singular(): string
    {
        return 'Client';
    }

    public function numberType(): ?string
    {
        return 'client';
    }

    public function nameColumn(): string
    {
        return 'company_name';
    }

    public function hasAttachments(): bool
    {
        return true;
    }

    public function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'maxlength' => 30, 'uppercase' => true, 'section' => 'General'],
            ['name' => 'company_name', 'label' => 'Client / company name', 'type' => 'text', 'required' => true, 'maxlength' => 200, 'section' => 'General'],
            ...$this->contactFields(),
            ...$this->taxFields(),
            ['name' => 'billing_address', 'label' => 'Billing address', 'type' => 'textarea', 'span' => 'full', 'section' => 'Address'],
            ['name' => 'shipping_address', 'label' => 'Site / shipping address', 'type' => 'textarea', 'span' => 'full', 'section' => 'Address'],
            ['name' => 'city', 'label' => 'City', 'type' => 'text', 'maxlength' => 100, 'section' => 'Address'],
            ['name' => 'pincode', 'label' => 'PIN code', 'type' => 'text', 'maxlength' => 6, 'section' => 'Address'],
            ['name' => 'is_active', 'label' => 'Active', 'type' => 'switch', 'section' => 'General'],
        ];
    }

    public function rules(?Model $record): array
    {
        return [
            'code' => $this->codeRules('clients', $record),
            'company_name' => ['required', 'string', 'max:200'],
            ...$this->contactRules(),
            ...$this->taxRules(),
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
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
            ['key' => 'company_name', 'label' => 'Client'],
            ['key' => 'contact_person', 'label' => 'Contact', 'mobile' => false],
            ['key' => 'mobile', 'label' => 'Mobile'],
            ['key' => 'gstin', 'label' => 'GSTIN', 'type' => 'code', 'mobile' => false],
            ['key' => 'is_active', 'label' => 'Status', 'type' => 'status'],
        ];
    }

    public function options(Request $request): array
    {
        return ['states' => IndianState::options()];
    }
}
