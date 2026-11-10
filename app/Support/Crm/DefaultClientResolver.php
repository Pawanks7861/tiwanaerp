<?php

namespace App\Support\Crm;

use App\Models\Core\Company;
use App\Models\Crm\Client;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the company default client without hard-coding numeric IDs.
 * Stable identity: company setting → client code → exact company name.
 */
final class DefaultClientResolver
{
    public const SETTING_KEY = 'crm.default_client_id';

    public const CLIENT_CODE = 'TIWANA-NUTRITION';

    public const CLIENT_NAME = 'Tiwana Nutrition Global Private Limited';

    public function id(?Company $company = null): ?int
    {
        $company ??= app(CurrentCompany::class)->get();
        if ($company === null) {
            return null;
        }

        $stored = $company->setting(self::SETTING_KEY);
        if (is_numeric($stored)) {
            $id = (int) $stored;
            if ($this->findActiveInCompany($company, $id) !== null) {
                return $id;
            }
        }

        $client = $this->findByStableIdentity($company);
        if ($client !== null) {
            $this->set($company, $client);

            return (int) $client->id;
        }

        return null;
    }

    public function client(?Company $company = null): ?Client
    {
        $company ??= app(CurrentCompany::class)->get();
        if ($company === null) {
            return null;
        }

        $id = $this->id($company);

        return $id !== null ? $this->findActiveInCompany($company, $id) : null;
    }

    public function require(?Company $company = null): Client
    {
        $company ??= app(CurrentCompany::class)->require();
        $client = $this->client($company);
        if ($client === null) {
            throw ValidationException::withMessages([
                'client_id' => 'Default client is not configured for this company.',
            ]);
        }

        return $client;
    }

    /**
     * Idempotently ensure the Tiwana Nutrition client exists and is configured as default.
     * Creates the client only when neither the stable code nor exact name exists.
     */
    public function ensure(Company $company): Client
    {
        $client = $this->findByStableIdentity($company);

        if ($client === null) {
            $client = new Client;
            $client->forceFill([
                'company_id' => $company->id,
                'code' => self::CLIENT_CODE,
                'company_name' => self::CLIENT_NAME,
                'is_active' => true,
            ])->save();
        } else {
            $updates = [];
            if (strcasecmp((string) $client->code, self::CLIENT_CODE) !== 0
                && ! Client::query()->where('company_id', $company->id)->whereRaw('LOWER(code) = ?', [strtolower(self::CLIENT_CODE)])->whereKeyNot($client->id)->exists()) {
                $updates['code'] = self::CLIENT_CODE;
            }
            if (trim((string) $client->company_name) === '') {
                $updates['company_name'] = self::CLIENT_NAME;
            }
            if (! $client->is_active) {
                $updates['is_active'] = true;
            }
            if ($updates !== []) {
                $client->forceFill($updates)->save();
            }
        }

        $this->set($company, $client->fresh());

        return $client->fresh();
    }

    public function set(Company $company, Client $client): void
    {
        if ((int) $client->company_id !== (int) $company->id) {
            throw ValidationException::withMessages([
                'client_id' => 'Default client must belong to this company.',
            ]);
        }

        $company->settings()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => (int) $client->id],
        );
    }

    public function assertMatchesDefault(int $clientId, ?Company $company = null): void
    {
        $defaultId = $this->id($company);
        if ($defaultId === null) {
            throw ValidationException::withMessages([
                'client_id' => 'Default client is not configured for this company.',
            ]);
        }

        if ($clientId !== $defaultId) {
            throw ValidationException::withMessages([
                'client_id' => 'Client must be '.$this->require($company)->company_name.'.',
            ]);
        }
    }

    public function payload(?Company $company = null): ?array
    {
        $client = $this->client($company);
        if ($client === null) {
            return null;
        }

        return $client->only(['id', 'code', 'company_name']);
    }

    private function findByStableIdentity(Company $company): ?Client
    {
        $byCode = Client::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(code) = ?', [strtolower(self::CLIENT_CODE)])
            ->first();

        if ($byCode !== null) {
            return $byCode;
        }

        return Client::query()
            ->where('company_id', $company->id)
            ->whereRaw('LOWER(company_name) = ?', [strtolower(self::CLIENT_NAME)])
            ->first();
    }

    private function findActiveInCompany(Company $company, int $id): ?Client
    {
        return Client::query()
            ->where('company_id', $company->id)
            ->whereKey($id)
            ->where('is_active', true)
            ->first();
    }
}
