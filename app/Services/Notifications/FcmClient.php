<?php

namespace App\Services\Notifications;

use App\Models\Core\DeviceToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends one Firebase Cloud Messaging HTTP v1 message. Does nothing until a project id and
 * service-account file are configured. An unregistered token is removed so it is not retried.
 */
class FcmClient
{
    public function configured(): bool
    {
        $path = (string) config('fcm.credentials');

        return filled(config('fcm.project_id')) && $path !== '' && is_file($path);
    }

    /**
     * Public web config for the browser. Null until every web key is set.
     *
     * @return array{apiKey: string, authDomain: string, projectId: string, messagingSenderId: string, appId: string, vapidKey: string}|null
     */
    public function webConfig(): ?array
    {
        $web = config('fcm.web');
        $projectId = (string) config('fcm.project_id');
        if ($projectId === '' || ! is_array($web)) {
            return null;
        }

        foreach (['api_key', 'auth_domain', 'messaging_sender_id', 'app_id', 'vapid_key'] as $key) {
            if (blank($web[$key] ?? null)) {
                return null;
            }
        }

        return [
            'apiKey' => (string) $web['api_key'],
            'authDomain' => (string) $web['auth_domain'],
            'projectId' => $projectId,
            'messagingSenderId' => (string) $web['messaging_sender_id'],
            'appId' => (string) $web['app_id'],
            'vapidKey' => (string) $web['vapid_key'],
        ];
    }

    /**
     * @param  array{title: string, body: string}  $notification
     * @param  array<string, string>  $data
     */
    public function send(string $token, array $notification, array $data): void
    {
        if (! $this->configured()) {
            return;
        }

        $projectId = (string) config('fcm.project_id');
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $notification['title'],
                        'body' => $notification['body'],
                    ],
                    'data' => $data,
                    'webpush' => [
                        'fcm_options' => [
                            'link' => $data['url'] !== '' ? $data['url'] : url('/'),
                        ],
                    ],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        if ($this->unregistered($response->json())) {
            DeviceToken::query()->where('token', $token)->delete();

            return;
        }

        throw new RuntimeException('FCM rejected the message with HTTP '.$response->status().'.');
    }

    private function accessToken(): string
    {
        $path = (string) config('fcm.credentials');
        $cacheKey = 'fcm.access_token.'.hash('sha256', $path.'|'.(string) filemtime($path));

        return Cache::remember($cacheKey, 3300, function () use ($path) {
            $response = Http::asForm()->acceptJson()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion($path),
            ]);

            $token = $response->json('access_token');
            if (! $response->successful() || ! is_string($token) || $token === '') {
                throw new RuntimeException('FCM could not obtain an access token (HTTP '.$response->status().').');
            }

            return $token;
        });
    }

    private function assertion(string $path): string
    {
        $json = json_decode((string) file_get_contents($path), true);
        $email = is_array($json) ? ($json['client_email'] ?? null) : null;
        $privateKey = is_array($json) ? ($json['private_key'] ?? null) : null;
        if (! is_string($email) || ! is_string($privateKey) || $privateKey === '') {
            throw new RuntimeException('The FCM service-account file is missing client_email or private_key.');
        }

        $header = $this->encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $claims = $this->encode([
            'iss' => $email,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        $input = $header.'.'.$claims;

        $signature = '';
        if (! openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The FCM service-account key could not be used to sign the request.');
        }

        return $input.'.'.$this->encode($signature, raw: true);
    }

    private function encode(array|string $value, bool $raw = false): string
    {
        $json = $raw ? $value : json_encode($value, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function unregistered(?array $body): bool
    {
        $encoded = json_encode($body ?? []);

        return is_string($encoded) && (str_contains($encoded, 'UNREGISTERED') || str_contains($encoded, 'NOT_FOUND'));
    }
}
