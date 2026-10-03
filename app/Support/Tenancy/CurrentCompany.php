<?php

namespace App\Support\Tenancy;

use App\Exceptions\NoCompanyContextException;
use App\Models\Core\Company;
use Closure;

/**
 * Holds the active company for the current request / job (registered as a scoped singleton).
 *
 * Tenant scoping is fail-closed: when no company is set and bypass is not explicitly enabled,
 * company-scoped queries return nothing (see CompanyScope).
 */
class CurrentCompany
{
    private ?Company $company = null;

    private bool $bypass = false;

    public function set(Company $company): void
    {
        $this->company = $company;
    }

    public function forget(): void
    {
        $this->company = null;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?int
    {
        return $this->company?->id;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function require(): Company
    {
        return $this->company ?? throw new NoCompanyContextException;
    }

    public function isBypassed(): bool
    {
        return $this->bypass;
    }

    /**
     * Run a callback as a specific company (seeders, queued jobs, console commands).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Company $company, Closure $callback): mixed
    {
        $previous = $this->company;
        $this->company = $company;

        try {
            return $callback();
        } finally {
            $this->company = $previous;
        }
    }

    /**
     * Run a callback without tenant filtering. Platform-level tooling only (super admin, provisioning).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutScope(Closure $callback): mixed
    {
        $previous = $this->bypass;
        $this->bypass = true;

        try {
            return $callback();
        } finally {
            $this->bypass = $previous;
        }
    }
}
