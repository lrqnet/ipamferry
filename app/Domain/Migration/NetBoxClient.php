<?php

namespace App\Domain\Migration;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class NetBoxClient
{
    private string $url;

    private ResourceRegistry $resources;

    public function __construct(
        string $url,
        private readonly string $token,
        ?EndpointPolicy $policy = null,
        ?ResourceRegistry $resources = null,
    ) {
        $this->url = ($policy ?? new EndpointPolicy)->canonicalize($url);
        $this->resources = $resources ?? new ResourceRegistry;
    }

    public function inspect(): array
    {
        $response = $this->sendRead('GET', 'status/', [], 'checking API status');
        $status = $this->jsonObject($response, 'reading the NetBox status response');
        $version = $status['netbox-version'] ?? $status['netbox_version'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new ExternalApiException('NetBox', 'reading the NetBox version');
        }
        $apiVersion = $response->header('API-Version');
        if ($apiVersion === '' && preg_match('/^(\d+\.\d+)/', $version, $matches)) {
            $apiVersion = $matches[1];
        }

        return [
            'version' => $version,
            'api_version' => $apiVersion ?: null,
            'plugins' => $status['plugins'] ?? [],
        ];
    }

    public function inventory(): array
    {
        $capabilities = $this->inspect();
        if (! (new CompatibilityMatrix)->netBox((string) $capabilities['version'])) {
            throw new ExternalApiException('NetBox', 'checking supported version 4.4 through 4.6');
        }
        $warnings = [];
        $objects = [
            'ipam_roles' => $this->getAll('ipam/roles/'),
            'route_targets' => $this->getAll('ipam/route-targets/'),
        ];
        $writeSchema = [];
        foreach ($this->resources->all() as $type => $definition) {
            $endpoint = $definition['endpoint'];
            $objects[$definition['collection']] = $this->getAllOptional($endpoint, $warnings);
            $writeSchema[$type] = $this->writeSchema($endpoint, $warnings);
        }

        return [
            'schema_version' => 2,
            'instance' => (new EndpointPolicy)->instance('netbox', $this->url, $capabilities),
            'discovered_at' => now()->toIso8601String(),
            'objects' => $objects,
            'write_schema' => $writeSchema,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function findMatches(string $targetType, array $naturalKey): array
    {
        $endpoint = $this->resources->endpoint($targetType);
        $filters = match ($targetType) {
            'tenant', 'site', 'manufacturer', 'device_role', 'provider', 'circuit_type', 'rir', 'tag', 'contact_role' => [
                'slug' => $naturalKey['slug'],
            ],
            'device_type' => [
                'slug' => $naturalKey['slug'],
                'manufacturer_id' => $naturalKey['manufacturer_id'],
            ],
            'contact' => [
                'name' => $naturalKey['name'],
                'email' => $naturalKey['email'] ?? '',
            ],
            'contact_assignment' => [
                'object_type' => $naturalKey['object_type'],
                'object_id' => $naturalKey['object_id'],
                'contact_id' => $naturalKey['contact_id'],
                'role_id' => $naturalKey['role_id'],
            ],
            'location' => [
                'slug' => $naturalKey['slug'],
                ...$this->scopeFilter('site_id', $naturalKey['site_id'] ?? null),
                ...$this->scopeFilter('parent_id', $naturalKey['parent_id'] ?? null),
            ],
            'rack' => [
                'name__ie' => $naturalKey['name'],
                ...$this->scopeFilter('site_id', $naturalKey['site_id'] ?? null),
                ...$this->scopeFilter('location_id', $naturalKey['location_id'] ?? null),
            ],
            'device' => [
                'name__ie' => $naturalKey['name'],
                'site_id' => $naturalKey['site_id'],
            ],
            'interface' => [
                'name' => $naturalKey['name'],
                'device_id' => $naturalKey['device_id'],
            ],
            'mac_address' => ['mac_address' => $naturalKey['mac_address']],
            'circuit' => [
                'cid' => $naturalKey['cid'],
                'provider_id' => $naturalKey['provider_id'],
            ],
            'circuit_termination' => [
                'circuit_id' => $naturalKey['circuit_id'],
                'term_side' => $naturalKey['term_side'],
            ],
            'asn' => ['asn' => $naturalKey['asn']],
            'vrf' => isset($naturalKey['rd']) && $naturalKey['rd'] !== ''
                ? ['rd' => $naturalKey['rd']]
                : ['name__ie' => $naturalKey['name']],
            'vlan_group' => [
                'name__ie' => $naturalKey['name'],
                ...$this->scopeFilter('scope_id', $naturalKey['scope_id'] ?? null),
            ],
            'vlan' => [
                'vid' => $naturalKey['vid'],
                ...$this->scopeFilter('group_id', $naturalKey['group_id'] ?? null),
            ],
            'prefix' => [
                'prefix' => $naturalKey['prefix'],
                ...$this->scopeFilter('vrf_id', $naturalKey['vrf_id'] ?? null),
            ],
            'ip_address' => [
                'address' => $naturalKey['address'],
                ...$this->scopeFilter('vrf_id', $naturalKey['vrf_id'] ?? null),
            ],
            'custom_field' => ['name' => $naturalKey['name']],
            'custom_field_choice_set' => ['name__ie' => $naturalKey['name']],
            default => throw new \InvalidArgumentException("Unsupported NetBox natural key for {$targetType}"),
        };

        return array_values(array_filter(
            $this->getAll($endpoint, $filters),
            fn (array $object): bool => $this->matchesNaturalKey($targetType, $object, $naturalKey),
        ));
    }

    public function detail(string $targetType, int $id): array
    {
        $endpoint = $this->resources->endpoint($targetType);
        $response = $this->sendRead('GET', "{$endpoint}{$id}/", [], "reading {$targetType} {$id}");

        return [
            'data' => $this->objectResponse($response, "reading {$targetType} {$id}"),
            'etag' => $response->header('ETag'),
            'request_id' => $response->header('X-Request-ID'),
        ];
    }

    public function create(string $targetType, array $payload, string $changelogMessage): array
    {
        $endpoint = $this->resources->endpoint($targetType);
        $response = $this->sendWrite(
            'POST',
            $endpoint,
            ['json' => [...$payload, 'changelog_message' => $changelogMessage]],
            "creating {$targetType}",
        );

        return [
            'data' => $this->objectResponse($response, "creating {$targetType}"),
            'request_id' => $response->header('X-Request-ID'),
        ];
    }

    public function update(string $targetType, int $id, array $payload, ?string $etag, string $changelogMessage): array
    {
        $endpoint = $this->resources->endpoint($targetType);
        $request = $this->request(false);
        if ($etag !== null && $etag !== '') {
            $request = $request->withHeaders(['If-Match' => $etag]);
        }

        $response = $this->sendWrite(
            'PATCH',
            "{$endpoint}{$id}/",
            ['json' => [...$payload, 'changelog_message' => $changelogMessage]],
            "updating {$targetType} {$id}",
            $request,
        );

        return [
            'data' => $this->objectResponse($response, "updating {$targetType} {$id}"),
            'request_id' => $response->header('X-Request-ID'),
        ];
    }

    public function instance(): array
    {
        return (new EndpointPolicy)->instance('netbox', $this->url, $this->inspect());
    }

    private function writeSchema(string $endpoint, array &$warnings): ?array
    {
        try {
            $response = $this->sendRead('OPTIONS', $endpoint, [], "inspecting write schema for {$endpoint}");
            $schema = $response->json('actions.POST');
            if (! is_array($schema) || $schema === []) {
                $warnings[] = "NetBox did not expose a writable POST schema for {$endpoint}";

                return null;
            }

            return $schema;
        } catch (ExternalApiException $exception) {
            $warnings[] = $exception->getMessage();

            return null;
        }
    }

    private function getAll(string $endpoint, array $filters = []): array
    {
        $limit = max(1, min((int) config('ipamferry.netbox_page_size'), 1000));
        $maximum = max($limit, (int) config('ipamferry.netbox_max_objects_per_type'));
        $offset = 0;
        $all = [];

        do {
            $response = $this->sendRead(
                'GET',
                $endpoint,
                ['query' => [...$filters, 'limit' => $limit, 'offset' => $offset]],
                "listing {$endpoint}",
            );
            $document = $this->jsonObject($response, "decoding {$endpoint}");
            $page = $document['results'] ?? null;
            if (! is_array($page) || ! array_is_list($page) || array_filter($page, fn (mixed $item): bool => ! is_array($item)) !== []) {
                throw new ExternalApiException('NetBox', "decoding {$endpoint}", $response->status());
            }

            array_push($all, ...$page);
            if (count($all) > $maximum) {
                throw new ExternalApiException('NetBox', "listing {$endpoint}: configured object limit exceeded");
            }

            $offset += count($page);
            $next = $document['next'] ?? null;
        } while ($page !== [] && $next !== null);

        return $all;
    }

    private function getAllOptional(string $endpoint, array &$warnings): array
    {
        try {
            return $this->getAll($endpoint);
        } catch (ExternalApiException $exception) {
            $warnings[] = $exception->getMessage();

            return [];
        }
    }

    public function matchesNaturalKey(string $type, array $object, array $key): bool
    {
        $relatedId = fn (mixed $value): ?int => is_array($value) ? ($value['id'] ?? null) : ($value === null ? null : (int) $value);

        return match ($type) {
            'tenant', 'site', 'manufacturer', 'device_role', 'provider', 'circuit_type', 'rir', 'tag', 'contact_role' => (string) ($object['slug'] ?? '') === (string) ($key['slug'] ?? ''),
            'device_type' => (string) ($object['slug'] ?? '') === (string) ($key['slug'] ?? '')
                && $relatedId($object['manufacturer'] ?? null) === ($key['manufacturer_id'] ?? null),
            'contact' => (string) ($object['name'] ?? '') === (string) ($key['name'] ?? '')
                && (string) ($object['email'] ?? '') === (string) ($key['email'] ?? ''),
            'contact_assignment' => (string) ($object['object_type'] ?? '') === (string) ($key['object_type'] ?? '')
                && $relatedId($object['object'] ?? $object['object_id'] ?? null) === ($key['object_id'] ?? null)
                && $relatedId($object['contact'] ?? null) === ($key['contact_id'] ?? null)
                && $relatedId($object['role'] ?? null) === ($key['role_id'] ?? null),
            'location' => (string) ($object['slug'] ?? '') === (string) ($key['slug'] ?? '')
                && $relatedId($object['site'] ?? null) === ($key['site_id'] ?? null)
                && $relatedId($object['parent'] ?? null) === ($key['parent_id'] ?? null),
            'rack' => mb_strtolower((string) ($object['name'] ?? '')) === mb_strtolower((string) ($key['name'] ?? ''))
                && $relatedId($object['site'] ?? null) === ($key['site_id'] ?? null)
                && $relatedId($object['location'] ?? null) === ($key['location_id'] ?? null),
            'device' => mb_strtolower((string) ($object['name'] ?? '')) === mb_strtolower((string) ($key['name'] ?? ''))
                && $relatedId($object['site'] ?? null) === ($key['site_id'] ?? null),
            'interface' => (string) ($object['name'] ?? '') === (string) ($key['name'] ?? '')
                && $relatedId($object['device'] ?? null) === ($key['device_id'] ?? null),
            'mac_address' => strtoupper((string) ($object['mac_address'] ?? '')) === strtoupper((string) ($key['mac_address'] ?? '')),
            'circuit' => (string) ($object['cid'] ?? '') === (string) ($key['cid'] ?? '')
                && $relatedId($object['provider'] ?? null) === ($key['provider_id'] ?? null),
            'circuit_termination' => $relatedId($object['circuit'] ?? null) === ($key['circuit_id'] ?? null)
                && (string) ($object['term_side']['value'] ?? $object['term_side'] ?? '') === (string) ($key['term_side'] ?? ''),
            'asn' => (int) ($object['asn'] ?? 0) === (int) ($key['asn'] ?? -1),
            'vrf' => isset($key['rd']) && $key['rd'] !== ''
                ? (string) ($object['rd'] ?? '') === (string) $key['rd']
                : mb_strtolower((string) ($object['name'] ?? '')) === mb_strtolower((string) $key['name']),
            'vlan_group' => mb_strtolower((string) ($object['name'] ?? '')) === mb_strtolower((string) $key['name'])
                && $relatedId($object['scope'] ?? null) === ($key['scope_id'] ?? null),
            'vlan' => (int) ($object['vid'] ?? -1) === (int) $key['vid']
                && $relatedId($object['group'] ?? null) === ($key['group_id'] ?? null),
            'prefix' => (string) ($object['prefix'] ?? '') === (string) $key['prefix']
                && $relatedId($object['vrf'] ?? null) === ($key['vrf_id'] ?? null),
            'ip_address' => (string) ($object['address'] ?? '') === (string) $key['address']
                && $relatedId($object['vrf'] ?? null) === ($key['vrf_id'] ?? null),
            'custom_field' => (string) ($object['name'] ?? '') === (string) $key['name'],
            'custom_field_choice_set' => (string) ($object['name'] ?? '') === (string) $key['name'],
            default => false,
        };
    }

    private function sendRead(string $method, string $path, array $options, string $operation): Response
    {
        try {
            $response = $this->request(true)->send($method, $path, $options);
        } catch (Throwable) {
            throw new ExternalApiException('NetBox', $operation);
        }

        if ($response->failed()) {
            throw new ExternalApiException('NetBox', $this->writeFailureOperation($operation, $response), $response->status());
        }
        $this->assertResponseSize($response, $operation);

        return $response;
    }

    private function sendWrite(string $method, string $path, array $options, string $operation, ?PendingRequest $request = null): Response
    {
        try {
            $response = ($request ?? $this->request(false))->send($method, $path, $options);
        } catch (Throwable) {
            throw new ExternalApiException('NetBox', $operation);
        }

        if ($response->failed()) {
            throw new ExternalApiException('NetBox', $this->writeFailureOperation($operation, $response), $response->status());
        }
        $this->assertResponseSize($response, $operation);

        return $response;
    }

    private function assertResponseSize(Response $response, string $operation): void
    {
        $maximum = max(1, (int) config('ipamferry.api_max_response_bytes'));
        $declared = (int) ($response->header('Content-Length') ?: 0);
        if ($declared > $maximum || strlen($response->body()) > $maximum) {
            throw new ExternalApiException('NetBox', "{$operation}: configured response size limit exceeded");
        }
    }

    private function writeFailureOperation(string $operation, Response $response): string
    {
        $body = $response->json();
        if (! is_array($body)) {
            return $operation;
        }

        $fields = array_values(array_filter(array_keys($body), 'is_string'));
        if ($fields === []) {
            return $operation;
        }

        // API validation responses can echo submitted values. Expose only the
        // bounded field names, never their messages or values, to preserve the
        // no-secrets-in-logs invariant.
        return $operation.' (validation fields: '.implode(', ', array_slice($fields, 0, 5)).')';
    }

    private function objectResponse(Response $response, string $operation): array
    {
        $data = $this->jsonObject($response, "{$operation}: decoding the object response");
        if ((int) ($data['id'] ?? 0) <= 0) {
            throw new ExternalApiException('NetBox', "{$operation}: decoding the object response", $response->status());
        }

        return $data;
    }

    private function jsonObject(Response $response, string $operation): array
    {
        try {
            $data = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ExternalApiException('NetBox', $operation, $response->status());
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new ExternalApiException('NetBox', $operation, $response->status());
        }

        return $data;
    }

    private function request(bool $safeToRetry): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->url, '/').'/api/')
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Authorization' => $this->authorizationHeader()])
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(30);

        return $safeToRetry
            ? $request->retry(2, 250, fn (Throwable $exception) => $exception instanceof ConnectionException, false)
            : $request;
    }

    private function authorizationHeader(): string
    {
        return str_starts_with($this->token, 'nbt_')
            ? "Bearer {$this->token}"
            : "Token {$this->token}";
    }

    private function scopeFilter(string $field, mixed $value): array
    {
        return $value === null ? ["{$field}__empty" => 'true'] : [$field => (int) $value];
    }
}
