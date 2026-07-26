<?php

namespace App\Domain\Migration;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class PhpIpamClient
{
    private string $url;

    public function __construct(
        string $url,
        private readonly string $appId,
        private readonly string $token,
        ?EndpointPolicy $policy = null,
    ) {
        $this->url = ($policy ?? new EndpointPolicy)->canonicalize($url);
    }

    public function inspect(): array
    {
        $response = $this->send('OPTIONS', $this->rootPath(), [], 'checking API capabilities');
        $data = $response->json('data', []);
        $permissions = is_array($data) ? ($data['permissions'] ?? null) : null;
        $controllers = is_array($data) ? ($data['controllers'] ?? $data) : [];

        $user = $this->optionalGet('user/', 'checking token validity');

        return [
            'version' => $response->header('phpipam-version') ?: null,
            'api_version' => $response->header('api-version') ?: null,
            'permissions' => $permissions,
            'controllers' => $controllers,
            'token_expires' => $user['expires'] ?? null,
        ];
    }

    public function inventory(): array
    {
        $capabilities = $this->inspect();
        if (is_string($capabilities['version'] ?? null)
            && $capabilities['version'] !== ''
            && ! (new CompatibilityMatrix)->phpIpam($capabilities['version'])
        ) {
            throw new ExternalApiException('phpIPAM', 'checking supported version 1.5 through 1.8');
        }
        $warnings = [];
        $required = [
            'sections' => 'sections/',
            'subnets' => 'subnets/all/',
            'addresses' => 'addresses/all/',
            'vlans' => 'vlan/all/',
            'vrfs' => 'vrf/',
            'l2domains' => 'l2domains/all/',
            'devices' => 'devices/all/',
        ];
        $optional = [
            'customers' => 'tools/customers/',
            'locations' => 'tools/locations/',
            'racks' => 'tools/racks/',
            'tags' => 'tools/tags/',
            'nat' => 'tools/nat/',
            'device_types' => 'tools/device_types/',
            'nameservers' => 'tools/nameservers/',
            'scan_agents' => 'tools/scanagents/',
            'circuit_providers' => 'circuits/providers/',
            'circuits' => 'circuits/',
        ];
        $objects = [];

        foreach ($required as $key => $path) {
            $objects[$key] = $this->get($path, "discovering {$key}");
        }

        foreach ($optional as $key => $path) {
            $objects[$key] = $this->optionalGet($path, "discovering {$key}", $warnings);
        }
        $objects['circuit_types'] = [];
        $warnings[] = 'Circuit Type definitions are available only from an approved SQL dump; the official phpIPAM API exposes circuits and providers but no Circuit Type collection.';
        $objects['routing_bgp'] = [];
        $warnings[] = 'BGP sessions are available only from an approved SQL dump and are preserved; the public phpIPAM API exposes no stable cross-version session controller.';

        $customFields = [];
        foreach ([
            'sections',
            'subnets',
            'addresses',
            'vlan',
            'vrf',
            'l2domains',
        ] as $controller) {
            $customFields[$controller] = $this->optionalGet(
                "{$controller}/custom_fields/",
                "discovering {$controller} custom fields",
                $warnings,
            );
        }

        $instance = (new EndpointPolicy)->instance('phpipam', $this->url, $capabilities);

        return [
            'schema_version' => 1,
            'instance' => $instance,
            'discovered_at' => now()->toIso8601String(),
            'objects' => $objects,
            'custom_fields' => $customFields,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function get(string $path, string $operation): array
    {
        $response = $this->send('GET', $this->rootPath().$path, [], $operation);
        if ($response->json('success') === false) {
            throw new ExternalApiException('phpIPAM', $operation, $response->status());
        }

        $data = $response->json('data', []);
        if (is_array($data) && count($data) > (int) config('ipamferry.phpipam_max_objects_per_type')) {
            throw new ExternalApiException('phpIPAM', "{$operation}: configured object limit exceeded");
        }

        return is_array($data) ? $data : [];
    }

    private function optionalGet(string $path, string $operation, array &$warnings = []): array
    {
        try {
            return $this->get($path, $operation);
        } catch (ExternalApiException $exception) {
            $warnings[] = $exception->getMessage();

            return [];
        }
    }

    private function send(string $method, string $path, array $options, string $operation): Response
    {
        try {
            $response = $this->request()->send($method, $path, $options);
        } catch (ConnectionException) {
            throw new ExternalApiException('phpIPAM', $operation);
        } catch (Throwable) {
            throw new ExternalApiException('phpIPAM', $operation);
        }

        if ($response->failed()) {
            throw new ExternalApiException('phpIPAM', $operation, $response->status());
        }
        $this->assertResponseSize($response, $operation);

        return $response;
    }

    private function assertResponseSize(Response $response, string $operation): void
    {
        $maximum = max(1, (int) config('ipamferry.api_max_response_bytes'));
        $declared = (int) ($response->header('Content-Length') ?: 0);
        if ($declared > $maximum || strlen($response->body()) > $maximum) {
            throw new ExternalApiException('phpIPAM', "{$operation}: configured response size limit exceeded");
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->url, '/').'/')
            ->acceptJson()
            ->asJson()
            ->withHeaders(['phpipam-token' => $this->token])
            ->withoutRedirecting()
            ->connectTimeout(5)
            ->timeout(30)
            ->retry(2, 250, fn (Throwable $exception) => $exception instanceof ConnectionException, false);
    }

    private function rootPath(): string
    {
        return 'api/'.rawurlencode($this->appId).'/';
    }
}
