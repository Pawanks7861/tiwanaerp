<?php

namespace App\Services\Auth;

/**
 * RFC 6238 TOTP (SHA-1, 30 second step, 6 digits). Compatible with common authenticator apps.
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $length = 32): string
    {
        $secret = '';
        $bytes = random_bytes($length);
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[ord($bytes[$i]) & 31];
        }

        return $secret;
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $slice = intdiv(time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::codeAt($secret, $slice + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function current(string $secret): string
    {
        return self::codeAt($secret, intdiv(time(), 30));
    }

    private static function codeAt(string $secret, int $slice): string
    {
        $key = self::decode($secret);
        $counter = pack('N*', 0).pack('N*', $slice);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        $buffer = 0;
        $bits = 0;
        $output = '';
        $length = strlen($secret);
        for ($i = 0; $i < $length; $i++) {
            $value = strpos(self::ALPHABET, $secret[$i]);
            if ($value === false) {
                continue;
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $output;
    }
}
