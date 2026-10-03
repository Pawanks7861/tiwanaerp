<?php

use App\Models\Core\AuditLog;
use App\Models\Masters\Vendor;
use App\Models\User;
use App\Support\Permissions\DefaultRoles;

beforeEach(function () {
    $this->company = $this->createCompany();
    $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
});

test('master changes are recorded with the user, company and changed values', function () {
    $as = $this->actingInCompany($this->admin, $this->company);
    $as->post(route('masters.store', 'vendors'), ['name' => 'Shree Cement Traders'])->assertSessionHasNoErrors();

    $vendor = $this->inCompany($this->company, fn () => Vendor::query()->sole());
    $as->put(route('masters.update', ['vendors', $vendor->id]), ['code' => $vendor->code, 'name' => 'Shree Cement Traders', 'city' => 'Ludhiana'])
        ->assertSessionHasNoErrors();

    $logs = AuditLog::query()->where('auditable_type', 'vendor')->where('auditable_id', $vendor->id)->orderBy('id')->get();

    expect($logs->pluck('event')->all())->toBe(['created', 'updated'])
        ->and($logs->pluck('company_id')->unique()->all())->toBe([$this->company->id])
        ->and($logs->pluck('user_id')->unique()->all())->toBe([$this->admin->id])
        ->and($logs[0]->new_values['name'])->toBe('Shree Cement Traders')
        ->and($logs[1]->old_values)->toBe(['city' => null])
        ->and($logs[1]->new_values)->toBe(['city' => 'Ludhiana']);
});

test('passwords and tokens never reach the audit log', function () {
    $this->actingInCompany($this->admin, $this->company)->post(route('admin.users.store'), [
        'name' => 'Ravi Kumar',
        'email' => 'ravi@example.test',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
        'roles' => [],
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'ravi@example.test')->sole();
    $log = AuditLog::query()->where('auditable_type', 'user')->where('auditable_id', $user->id)->where('event', 'created')->sole();

    expect($log->new_values)->not->toHaveKeys(['password', 'remember_token'])
        ->and($log->company_id)->toBe($this->company->id);
});

test('audit logs cannot be edited or deleted', function () {
    $this->inCompany($this->company, fn () => Vendor::query()->create(['code' => 'V1', 'name' => 'A']));
    $log = AuditLog::query()->where('auditable_type', 'vendor')->sole();

    expect(fn () => $log->update(['event' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $log->delete())->toThrow(LogicException::class);
});
