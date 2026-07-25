<?php

namespace App\Domain\Migration;

use InvalidArgumentException;

final class EndpointPolicy
{
    public function canonicalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('The API URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        $sandbox = $this->isSandboxEndpoint($parts);
        if ($scheme !== 'https'
            && ! ($scheme === 'http' && (config('ipamferry.allow_insecure_http') || $sandbox))
        ) {
            throw new InvalidArgumentException('API URLs must use HTTPS unless insecure HTTP is explicitly enabled.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('API URLs cannot contain credentials, query parameters, or fragments.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($this->isForbiddenHost($host) || $this->resolvesToForbiddenAddress($host)) {
            throw new InvalidArgumentException('The API URL points to a forbidden metadata or link-local address.');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $trimmedPath = trim((string) ($parts['path'] ?? ''), '/');
        $path = $trimmedPath === '' ? '' : "/{$trimmedPath}";

        return "{$scheme}://{$host}{$port}{$path}";
    }

    public function instance(string $kind, string $url, array $capabilities): array
    {
        $identity = [
            'kind' => $kind,
            'url' => $this->canonicalize($url),
            'version' => $capabilities['version'] ?? null,
            'api_version' => $capabilities['api_version'] ?? null,
        ];

        return [
            ...$identity,
            'capabilities' => $capabilities,
            'fingerprint' => CanonicalJson::fingerprint($identity),
        ];
    }

    private function isForbiddenHost(string $host): bool
    {
        if (in_array($host, [
            'metadata.google.internal',
            'metadata.aws.internal',
            'instance-data',
        ], true)) {
            return true;
        }

        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            $unsigned = (int) sprintf('%u', $long);

            return $this->inIpv4Range($unsigned, '0.0.0.0', 8)
                || $this->inIpv4Range($unsigned, '127.0.0.0', 8)
                || $this->inIpv4Range($unsigned, '169.254.0.0', 16)
                || $this->inIpv4Range($unsigned, '224.0.0.0', 4)
                || $ip === '100.100.100.200';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($ip);

            return $normalized === '::'
                || $normalized === '::1'
                || $normalized === 'fd00:ec2::254'
                || str_starts_with($normalized, 'fe8')
                || str_starts_with($normalized, 'fe9')
                || str_starts_with($normalized, 'fea')
                || str_starts_with($normalized, 'feb')
                || str_starts_with($normalized, 'ff');
        }

        return false;
    }

    private function resolvesToForbiddenAddress(string $host): bool
    {
        $plainHost = trim($host, '[]');
        if (filter_var($plainHost, FILTER_VALIDATE_IP)) {
            return false;
        }

        $addresses = gethostbynamel($plainHost) ?: [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($plainHost, DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        foreach (array_unique($addresses) as $address) {
            if ($this->isForbiddenHost($address)) {
                return true;
            }
        }

        return false;
    }

    private function inIpv4Range(int $ip, string $network, int $prefix): bool
    {
        $networkLong = (int) sprintf('%u', ip2long($network));
        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;

        return ($ip & $mask) === ($networkLong & $mask);
    }

    private function isSandboxEndpoint(array $parts): bool
    {
        $sandbox = parse_url((string) config('ipamferry.sandbox_url'));
        if (! is_array($sandbox)) {
            return false;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === strtolower((string) ($sandbox['scheme'] ?? ''))
            && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) ($sandbox['host'] ?? ''))
            && ($parts['port'] ?? null) === ($sandbox['port'] ?? null)
            && '/'.trim((string) ($parts['path'] ?? ''), '/') === '/'.trim((string) ($sandbox['path'] ?? ''), '/');
    }
}
