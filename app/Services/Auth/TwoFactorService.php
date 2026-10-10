<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{secret: string, otpauth: string, recovery_codes: list<string>}
     */
    public function begin(User $user): array
    {
        $secret = Totp::secret();
        $codes = $this->plainCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $this->hashCodes($codes),
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'otpauth' => $this->otpauth($user, $secret),
            'recovery_codes' => $codes,
        ];
    }

    public function confirm(User $user, string $code): void
    {
        $secret = (string) $user->two_factor_secret;
        if ($secret === '' || ! Totp::verify($secret, $code)) {
            throw ValidationException::withMessages(['code' => 'That authentication code is not valid.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->audit->record($user, 'two_factor_enabled');
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->audit->record($user, 'two_factor_disabled');
    }

    /**
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        $codes = $this->plainCodes();
        $user->forceFill(['two_factor_recovery_codes' => $this->hashCodes($codes)])->save();
        $this->audit->record($user, 'two_factor_recovery_regenerated');

        return $codes;
    }

    public function challenge(User $user, string $code): bool
    {
        $code = trim($code);
        if (Totp::verify((string) $user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function otpauth(User $user, string $secret): string
    {
        $issuer = rawurlencode((string) config('app.name'));
        $label = rawurlencode((string) config('app.name').':'.$user->email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&digits=6&period=30";
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if (strlen($normalized) < 8) {
            return false;
        }

        $hashes = $user->two_factor_recovery_codes ?? [];
        foreach ($hashes as $index => $hash) {
            if (! is_string($hash) || ! Hash::check($normalized, $hash)) {
                continue;
            }
            unset($hashes[$index]);
            $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
            $this->audit->record($user, 'two_factor_recovery_used');

            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function plainCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::upper(Str::random(10));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function hashCodes(array $codes): array
    {
        return array_map(fn (string $code) => Hash::make($code), $codes);
    }
}
