<?php

namespace App\Infrastructure\Billing;

use App\Domain\Billing\Exceptions\BillingException;

final class SafeAudioUrl
{
    public function resolve(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
            throw new BillingException('Use a public HTTP or HTTPS audio link.', 422);
        }
        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new BillingException('This audio link uses an unsupported port.', 422);
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = array_values(array_filter(array_map(
                fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
                $records ?: [],
            )));
        }
        if ($addresses === []) {
            throw new BillingException('The audio host could not be resolved.', 422);
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with(strtolower($address), '::ffff:')) {
                throw new BillingException('Private network audio links are not allowed.', 422);
            }
        }
        $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return ['proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip], CURLOPT_PROXY => '']];
    }
}
