<?php

namespace Tests;

use App\Http\Middleware\SetCurrentCompany;
use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Each test request starts without tenant context, like a fresh PHP-FPM request would.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            $this->app->forgetScopedInstances();
            $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }

    /**
     * A fully provisioned company (default roles, masters, workflows).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createCompany(array $attributes = []): Company
    {
        return $this->app->make(CompanyService::class)->create(Company::factory()->raw($attributes));
    }

    /**
     * @param  string|list<string>  $roles  role names in that company
     * @param  array<string, mixed>  $attributes
     */
    public function createMember(Company $company, string|array $roles = [], array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['current_company_id' => $company->id]);
        $this->app->make(CompanyService::class)->addMember($company, $user, (array) $roles);

        return $user->fresh();
    }

    public function actingInCompany(User $user, Company $company): static
    {
        return $this->actingAs($user)->withSession([SetCurrentCompany::SESSION_KEY => $company->id]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function inCompany(Company $company, Closure $callback): mixed
    {
        return $this->app->make(CurrentCompany::class)->runAs($company, $callback);
    }
}
