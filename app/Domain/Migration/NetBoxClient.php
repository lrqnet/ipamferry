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
    private const ENDPOINTS = [
        'vrf' => 'ipam/vrfs/',
        'vlan_group' => 'ipam/vlan-groups/',
        'vlan' => 'ipam/vlans/',
        'prefix' => 'ipam/prefixes/',
        'ip_address' => 'ipam/ip-addresses/',
        'custom_field' => 'extras/custom-fields/',
    ];

    private string $url;

    public function __construct(
        string $url,
        private readonly string $token,
        ?EndpointPolicy $policy = null,
    ) {
        $this->url = ($policy ?? new EndpointPolicy)->canonicalize($url);
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
        $warnings = [];
        $objects = [
            'vrfs' => $this->getAll('ipam/vrfs/'),
            'vlan_groups' => $this->getAll('ipam/vlan-groups/'),
            'vlans' => $this->getAll('ipam/vlans/'),
            'prefixes' => $this->getAll('ipam/prefixes/'),
            'ip_addresses' => $this->getAll('ipam/ip-addresses/'),
            'ipam_roles' => $this->getAll('ipam/roles/'),
            'route_targets' => $this->getAll('ipam/route-targets/'),
            'tags' => $this->getAll('extras/tags/'),
            'custom_fields' => $this->getAll('extras/custom-fields/'),
        ];
        $writeSchema = [];
        foreach (self::ENDPOINTS as $type => $endpoint) {
            $writeSchema[$type] = $this->writeSchema($endpoint, $warnings);
        }

        return [
            'schema_version' => 1,
            'instance' => (new EndpointPolicy)->instance('netbox', $this->url, $capabilities),
            'discovered_at' => now()->toIso8601String(),
            'objects' => $objects,
            'write_schema' => $writeSchema,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function findMatches(string $targetType, array $naturalKey): array
    {
        $endpoint = self::ENDPOINTS[$targetType] ?? throw new \InvalidArgumentException("Unsupported NetBox target type: {$targetType}");
        $filters = match ($targetType) {
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
        };

        return array_values(array_filter(
            $this->getAll($endpoint, $filters),
            fn (array $object): bool => $this->matchesNaturalKey($targetType, $object, $naturalKey),
        ));
    }

    public function detail(string $targetType, int $id): array
    {
        $endpoint = self::ENDPOINTS[$targetType] ?? throw new \InvalidArgumentException("Unsupported NetBox target type: {$targetType}");
        $response = $this->sendRead('GET', "{$endpoint}{$id}/", [], "reading {$targetType} {$id}");

        return [
            'data' => $this->objectResponse($response, "reading {$targetType} {$id}"),
            'etag' => $response->header('ETag'),
            'request_id' => $response->header('X-Request-ID'),
        ];
    }

    public function create(string $targetType, array $payload, string $changelogMessage): array
    {
        $endpoint = self::ENDPOINTS[$targetType] ?? throw new \InvalidArgumentException("Unsupported NetBox target type: {$targetType}");
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
        $endpoint = self::ENDPOINTS[$targetType] ?? throw new \InvalidArgumentException("Unsupported NetBox target type: {$targetType}");
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

    public function matchesNaturalKey(string $type, array $object, array $key): bool
    {
        $relatedId = fn (mixed $value): ?int => is_array($value) ? ($value['id'] ?? null) : ($value === null ? null : (int) $value);

        return match ($type) {
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
            throw new ExternalApiException('NetBox', $operation, $response->status());
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
            throw new ExternalApiException('NetBox', $operation, $response->status());
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
