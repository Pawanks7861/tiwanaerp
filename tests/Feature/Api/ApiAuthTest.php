<?php

use App\Enums\ProjectRole;
use App\Models\Projects\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use App\Support\Permissions\DefaultRoles;

beforeEach(function () {
    $this->company = $this->createCompany(['name' => 'Tiwana Constructions']);
    $this->engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER, ['email' => 'engineer@example.test']);
});

function apiLogin($test, string $email = 'engineer@example.test', string $password = 'password')
{
    return $test->postJson(route('api.v1.auth.login'), ['email' => $email, 'password' => $password, 'device_name' => 'Pixel 8']);
}

function apiAs($test, string $token, ?int $companyId = null)
{
    app('auth')->forgetGuards();

    return $test->withHeaders(array_filter([
        'Authorization' => "Bearer {$token}",
        'X-Company-Id' => $companyId ? (string) $companyId : null,
    ]));
}

test('a member logs in with email and password and receives a token and their companies', function () {
    $response = apiLogin($this)->assertOk()
        ->assertJsonPath('user.email', 'engineer@example.test')
        ->assertJsonPath('companies.0.id', $this->company->id)
        ->assertJsonMissingPath('user.password');

    expect($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($this->engineer->fresh()->last_login_at)->not->toBeNull();
});

test('wrong passwords and inactive accounts are refused', function () {
    apiLogin($this, password: 'wrong')->assertUnprocessable()->assertJsonValidationErrors('email');

    User::factory()->inactive()->create(['email' => 'gone@example.test']);
    apiLogin($this, 'gone@example.test')->assertUnprocessable();
});

test('login attempts are rate limited', function () {
    foreach (range(1, 5) as $_) {
        apiLogin($this, password: 'wrong')->assertUnprocessable();
    }

    apiLogin($this, password: 'wrong')->assertTooManyRequests();
});

test('protected endpoints need a token', function () {
    $this->getJson(route('api.v1.me'))->assertUnauthorized();
});

test('the company comes from the X-Company-Id header and must be one the user belongs to', function () {
    $token = apiLogin($this)->json('token');

    apiAs($this, $token, $this->company->id)->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('company.id', $this->company->id)
        ->assertJson(fn ($json) => $json->has('permissions')->etc());

    $foreign = $this->createCompany();
    apiAs($this, $token, $foreign->id)->getJson(route('api.v1.me'))->assertForbidden();
});

test('project listings are scoped to the company and to the user\'s project access', function () {
    [$assigned, $unassigned] = $this->inCompany($this->company, fn () => [
        Project::factory()->create(['name' => 'Assigned Tower']),
        Project::factory()->create(['name' => 'Other Tower']),
    ]);
    $this->inCompany($this->company, fn () => app(ProjectService::class)
        ->assignMember($assigned, $this->engineer->id, ProjectRole::Engineer));

    $foreign = $this->createCompany();
    $foreignProject = $this->inCompany($foreign, fn () => Project::factory()->create());

    $token = apiLogin($this)->json('token');

    apiAs($this, $token, $this->company->id)->getJson(route('api.v1.projects.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $assigned->id);

    apiAs($this, $token, $this->company->id)->getJson(route('api.v1.projects.show', $assigned))->assertOk();
    apiAs($this, $token, $this->company->id)->getJson(route('api.v1.projects.show', $unassigned))->assertForbidden();
    apiAs($this, $token, $this->company->id)->getJson(route('api.v1.projects.show', $foreignProject->id))->assertNotFound();
});

test('logging out revokes the token', function () {
    $token = apiLogin($this)->json('token');

    apiAs($this, $token, $this->company->id)->postJson(route('api.v1.auth.logout'))->assertOk();
    apiAs($this, $token, $this->company->id)->getJson(route('api.v1.me'))->assertUnauthorized();
});

test('deactivated users are refused even with a valid token', function () {
    $token = apiLogin($this)->json('token');
    $this->engineer->forceFill(['is_active' => false])->save();

    apiAs($this, $token, $this->company->id)->getJson(route('api.v1.me'))->assertForbidden();
});
