<?php

namespace App\Integrations\Tally;

use Illuminate\Validation\ValidationException;

/**
 * Tally may talk to localhost and private LAN addresses only.
 * Public, link-local, and cloud-metadata addresses are rejected, including mapped IPv6 forms.
 */
class TallyHostGuard
{
    public function assertAllowed(string $host): void
    {
        $host = strtolower(trim($host));
        if ($host === '' || str_contains($host, '://') || str_contains($host, '/') || str_contains($host, '\\') || str_contains($host, '%')) {
            $this->fail('Enter a host name or IP, without a URL path.');
        }

        if ($this->isIp($host)) {
            if (! $this->isPermittedAddress($host)) {
                $this->fail('Tally must stay on localhost, a private LAN, or a VPN. Do not expose port 9000 to the internet.');
            }

            return;
        }

        if ($host === 'localhost') {
            return;
        }

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)) {
            $this->fail('Enter a host name or IP, without a URL path.');
        }

        $addresses = $this->resolve($host);
        if ($addresses === []) {
            $this->fail('That Tally host name does not resolve to a private address.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPermittedAddress($address)) {
                $this->fail('Tally must stay on localhost, a private LAN, or a VPN. Do not expose port 9000 to the internet.');
            }
        }
    }

    public function isPermittedAddress(string $ip): bool
    {
        $ip = $this->unwrapMapped($ip);
        if (! $this->isIp($ip)) {
            return false;
        }

        if ($this->isDenied($ip)) {
            return false;
        }

        if ($this->isLoopback($ip) || $this->isPrivateLan($ip)) {
            return true;
        }

        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

        return $public === false;
    }

    /**
     * @return list<string>
     */
    private function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $addresses[] = $ip;
            }
        }

        return $addresses;
    }

    private function isIp(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private function unwrapMapped(string $ip): string
    {
        if (str_starts_with($ip, '::ffff:')) {
            $mapped = substr($ip, 7);
            if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $mapped;
            }
        }

        return $ip;
    }

    private function isDenied(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->ipv4In($ip, '0.0.0.0', 8)
                || $this->ipv4In($ip, '169.254.0.0', 16)
                || $this->ipv4In($ip, '224.0.0.0', 4)
                || $this->ipv4In($ip, '240.0.0.0', 4);
        }

        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        $first = ord($packed[0]);
        $second = ord($packed[1]);

        if (($first & 0xFE) === 0xFC) {
            return false;
        }

        if ($first === 0xFE && ($second & 0xC0) === 0x80) {
            return true;
        }

        if ($first === 0xFF) {
            return true;
        }

        return false;
    }

    private function ipv4In(string $ip, string $network, int $bits): bool
    {
        $address = inet_pton($ip);
        $net = inet_pton($network);
        if ($address === false || $net === false) {
            return true;
        }

        $ipLong = unpack('N', $address)[1];
        $netLong = unpack('N', $net)[1];
        $mask = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);

        return ($ipLong & $mask) === ($netLong & $mask);
    }

    private function isLoopback(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($ip, '127.');
        }

        return $ip === '::1';
    }

    private function isPrivateLan(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
                && ! str_starts_with($ip, '127.');
        }

        $packed = inet_pton($ip);

        return $packed !== false && (ord($packed[0]) & 0xFE) === 0xFC;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['host' => $message]);
    }
}
