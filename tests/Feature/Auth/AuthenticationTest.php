<?php

use App\Models\Core\Company;
use App\Models\User;

test('login screen can be rendered', function () {
    $this->get('/login')->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->memberOf(Company::factory()->create())->create();

    $response = $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    $this->assertGuest();
});

test('deactivated users can not authenticate', function () {
    $user = User::factory()->inactive()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user deactivated during a session is logged out on the next request', function () {
    $company = Company::factory()->create();
    $user = User::factory()->memberOf($company)->create();
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});
