<?php

use App\Models\Core\Company;
use App\Models\User;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->memberOf($this->company)->create();
});

test('profile page is displayed', function () {
    $this->actingAs($this->user)->get('/profile')->assertOk();
});

test('profile information can be updated', function () {
    $response = $this->actingAs($this->user)->patch('/profile', [
        'name' => 'Test User',
        'email' => 'test@example.com',
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect('/profile');

    $this->user->refresh();
    expect($this->user->name)->toBe('Test User')
        ->and($this->user->email)->toBe('test@example.com')
        ->and($this->user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $this->actingAs($this->user)
        ->patch('/profile', ['name' => 'Test User', 'email' => $this->user->email])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    expect($this->user->refresh()->email_verified_at)->not->toBeNull();
});

test('users cannot delete their own account', function () {
    $this->actingAs($this->user)->delete('/profile', ['password' => 'password'])->assertMethodNotAllowed();

    expect($this->user->fresh())->not->toBeNull();
});

test('public registration is disabled', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});
