<?php

use App\Services\Branding\CompanyBranding;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake(config('uploads.disk'));
    $this->company = $this->createCompany(['name' => 'Brand Co']);
    $this->other = $this->createCompany(['name' => 'Other Co']);
    $this->admin = $this->createMember($this->company, DefaultRoles::COMPANY_ADMIN);
    $this->engineer = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
});

test('a company admin can upload replace and remove a logo', function () {
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.logo.store'), ['file' => UploadedFile::fake()->image('logo.png', 80, 40)])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $first = $this->company->fresh()->logo_path;
    expect($first)->toStartWith('company/'.$this->company->id.'/branding/');
    Storage::disk(config('uploads.disk'))->assertExists($first);

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('company.branding.show', 'logo'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.logo.store'), ['file' => UploadedFile::fake()->image('next.jpg', 40, 40)])
        ->assertSessionHasNoErrors();

    $second = $this->company->fresh()->logo_path;
    expect($second)->not->toBe($first);
    Storage::disk(config('uploads.disk'))->assertMissing($first);

    $this->actingInCompany($this->admin, $this->company)
        ->delete(route('admin.company.logo.destroy'))
        ->assertRedirect();

    expect($this->company->fresh()->logo_path)->toBeNull();
    Storage::disk(config('uploads.disk'))->assertMissing($second);
});

test('a company admin can upload replace and remove a favicon including ico', function () {
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.favicon.store'), ['file' => UploadedFile::fake()->image('icon.png', 32, 32)])
        ->assertSessionHasNoErrors();

    $first = $this->company->fresh()->favicon_path;
    expect($first)->toEndWith('.png');

    $ico = UploadedFile::fake()->createWithContent('mark.ico', "\x00\x00\x01\x00".str_repeat("\x00", 18));
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.favicon.store'), ['file' => $ico])
        ->assertSessionHasNoErrors();

    expect($this->company->fresh()->favicon_path)->toEndWith('.ico');
    Storage::disk(config('uploads.disk'))->assertMissing($first);

    $this->actingInCompany($this->admin, $this->company)
        ->delete(route('admin.company.favicon.destroy'))
        ->assertRedirect();

    expect($this->company->fresh()->favicon_path)->toBeNull();
});

test('branding rejects the wrong type and an oversized file', function () {
    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.logo.store'), [
            'file' => UploadedFile::fake()->createWithContent('mark.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
        ])->assertSessionHasErrors('file');

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.favicon.store'), [
            'file' => UploadedFile::fake()->createWithContent('bad.ico', 'not-an-icon'),
        ])->assertSessionHasErrors('file');

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.logo.store'), [
            'file' => UploadedFile::fake()->image('huge.png')->size(CompanyBranding::MAX_KB + 200),
        ])->assertSessionHasErrors('file');

    expect($this->company->fresh()->logo_path)->toBeNull();
});

test('another company and a user without settings permission cannot change branding', function () {
    $this->inCompany($this->other, fn () => app(CompanyBranding::class)->storeLogo(
        $this->other,
        UploadedFile::fake()->image('other.png'),
    ));
    $kept = $this->other->fresh()->logo_path;

    $this->actingInCompany($this->engineer, $this->company)
        ->post(route('admin.company.logo.store'), ['file' => UploadedFile::fake()->image('nope.png')])
        ->assertForbidden();

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.logo.store'), ['file' => UploadedFile::fake()->image('ours.png')])
        ->assertSessionHasNoErrors();

    $this->actingInCompany($this->admin, $this->company)
        ->delete(route('admin.company.logo.destroy'))
        ->assertRedirect();

    expect($this->other->fresh()->logo_path)->toBe($kept);
    Storage::disk(config('uploads.disk'))->assertExists($kept);
});

test('the shell uses the active company logo and falls back when it is absent', function () {
    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('branding.logo_url', null)->where('branding.favicon_url', null));

    $this->actingInCompany($this->admin, $this->company)
        ->post(route('admin.company.logo.store'), ['file' => UploadedFile::fake()->image('logo.png')]);

    $this->actingInCompany($this->engineer, $this->company)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('branding.logo_url', fn ($url) => str_contains((string) $url, '/branding/logo')));

    $otherAdmin = $this->createMember($this->other, DefaultRoles::COMPANY_ADMIN);
    $this->actingInCompany($otherAdmin, $this->other)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('branding.logo_url', null));
});
