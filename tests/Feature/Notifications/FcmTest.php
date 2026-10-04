<?php

use App\Models\Core\DeviceToken;
use App\Models\Core\NotificationPreference;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\GeneralNotification;
use App\Support\Permissions\DefaultRoles;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->company = $this->createCompany();
    $this->user = $this->createMember($this->company, DefaultRoles::SITE_ENGINEER);
    $this->other = $this->createMember($this->company, DefaultRoles::PROJECT_MANAGER);
});

test('a user can register a device token and another user cannot remove it', function () {
    $this->actingInCompany($this->user, $this->company)
        ->postJson(route('devices.store'), ['token' => 'token-a', 'platform' => 'web'])
        ->assertOk();

    expect(DeviceToken::query()->where('token', 'token-a')->value('user_id'))->toBe($this->user->id);

    $this->actingInCompany($this->other, $this->company)
        ->deleteJson(route('devices.destroy'), ['token' => 'token-a'])
        ->assertOk();

    expect(DeviceToken::query()->where('token', 'token-a')->exists())->toBeTrue();

    $this->actingInCompany($this->other, $this->company)
        ->postJson(route('devices.store'), ['token' => 'token-a', 'platform' => 'android'])
        ->assertOk();

    expect(DeviceToken::query()->where('token', 'token-a')->value('user_id'))->toBe($this->other->id);
});

test('the mobile api registers a token for the authenticated user', function () {
    $login = $this->postJson(route('api.v1.auth.login'), [
        'email' => $this->user->email,
        'password' => 'password',
        'device_name' => 'Pixel',
    ])->assertOk();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$login->json('token'),
        'X-Company-Id' => (string) $this->company->id,
    ])->postJson(route('api.v1.devices.store'), ['token' => 'phone-token', 'platform' => 'android'])
        ->assertOk();

    expect(DeviceToken::query()->where('token', 'phone-token')->value('user_id'))->toBe($this->user->id);
});

test('push is saved as a preference and is off unless chosen', function () {
    $this->actingInCompany($this->user, $this->company)
        ->put(route('notifications.preferences.update'), [
            'preferences' => [
                ['type' => 'chat.message_received', 'database' => true, 'push' => true],
            ],
        ])->assertSessionHasNoErrors();

    expect(NotificationPreference::query()->where('user_id', $this->user->id)->where('notification_type', 'chat.message_received')->value('channels'))
        ->toBe(['database', 'push']);

    $notice = new GeneralNotification($this->company->id, 'chat.message_received', 'New message', 'Hello', '/chat/1');
    expect($notice->via($this->user))->toContain(FcmChannel::class);

    $plain = new GeneralNotification($this->company->id, 'planning.task_assigned', 'Task', 'Assigned', '/tasks');
    expect($plain->via($this->user))->not->toContain(FcmChannel::class);
});

test('a push notification is sent to the registered token', function () {
    configureFcm();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/demo/messages/1']),
    ]);

    DeviceToken::query()->create([
        'user_id' => $this->user->id,
        'platform' => 'web',
        'token' => 'token-a',
        'last_seen_at' => now(),
    ]);
    NotificationPreference::query()->create([
        'user_id' => $this->user->id,
        'notification_type' => 'chat.message_received',
        'channels' => ['push'],
    ]);

    $this->user->notify(new GeneralNotification($this->company->id, 'chat.message_received', 'New message', 'Hello', '/chat/1', ['message_id' => 9]));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'messages:send')
            && $request['message']['token'] === 'token-a'
            && $request['message']['notification']['title'] === 'New message'
            && $request['message']['data']['kind'] === 'chat.message_received'
            && $request['message']['data']['company_id'] === (string) $this->company->id;
    });
});

test('an unregistered token is removed and a missing firebase setup sends nothing', function () {
    configureFcm();
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
    ]);

    DeviceToken::query()->create(['user_id' => $this->user->id, 'platform' => 'web', 'token' => 'gone']);
    NotificationPreference::query()->create([
        'user_id' => $this->user->id,
        'notification_type' => 'chat.message_received',
        'channels' => ['push'],
    ]);

    $this->user->notify(new GeneralNotification($this->company->id, 'chat.message_received', 'New message', 'Hello', '/chat/1'));

    expect(DeviceToken::query()->where('token', 'gone')->exists())->toBeFalse();

    config(['fcm.credentials' => null]);
    Http::fake();
    DeviceToken::query()->create(['user_id' => $this->user->id, 'platform' => 'web', 'token' => 'kept']);
    $this->user->notify(new GeneralNotification($this->company->id, 'chat.message_received', 'New message', 'Still here', '/chat/1'));

    Http::assertNothingSent();
    expect(DeviceToken::query()->where('token', 'kept')->exists())->toBeTrue();
});

test('the service worker contains the public web config and not the service account', function () {
    config([
        'fcm.project_id' => 'demo-project',
        'fcm.web.api_key' => 'web-key',
        'fcm.web.auth_domain' => 'demo-project.firebaseapp.com',
        'fcm.web.messaging_sender_id' => '123',
        'fcm.web.app_id' => '1:123:web:abc',
        'fcm.web.vapid_key' => 'vapid',
    ]);

    $body = $this->get(route('fcm.worker'))->assertOk()->assertHeader('content-type', 'application/javascript; charset=UTF-8')->getContent();

    expect($body)->toContain('demo-project')->toContain('web-key')->not->toContain('private_key');
});

function configureFcm(): void
{
    $openssl = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $cnf = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf';
    if (is_file($cnf)) {
        $openssl['config'] = $cnf;
    }

    $key = openssl_pkey_new($openssl);
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $private, null, $openssl);

    $path = storage_path('framework/testing-fcm.json');
    file_put_contents($path, json_encode([
        'client_email' => 'fcm@test.iam.gserviceaccount.com',
        'private_key' => $private,
    ]));

    config([
        'fcm.project_id' => 'demo-project',
        'fcm.credentials' => $path,
    ]);
}
