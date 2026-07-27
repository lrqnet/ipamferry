<?php

declare(strict_types=1);

use App\Domain\Migration\BundleBuilder;
use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationApplier;
use App\Domain\Migration\MigrationAudit;
use App\Domain\Migration\MigrationOperationLock;
use App\Domain\Migration\MigrationPlanner;
use App\Domain\Migration\MigrationVerifier;
use App\Domain\Migration\NetBoxClient;
use App\Domain\Migration\PhpIpamClient;
use App\Domain\Migration\SandboxConnection;
use App\Domain\Migration\SourceNormalizer;
use App\Domain\Migration\SqlDumpParser;
use App\Enums\MigrationExecutionStatus;
use App\Enums\MigrationProjectStatus;
use App\Enums\UserRole;
use App\Jobs\BuildMigrationPlan;
use App\Models\MigrationObjectLink;
use App\Models\MigrationProject;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

function requireLabObject(array $objects, string $field, string $value, string $label): array
{
    foreach ($objects as $object) {
        if (is_array($object) && (string) ($object[$field] ?? '') === $value) {
            return $object;
        }
    }

    throw new RuntimeException("Missing {$label}: {$field}={$value}");
}

function requireLabValue(array $object, string $field, string $value, string $label): void
{
    if ((string) ($object[$field] ?? '') !== $value) {
        throw new RuntimeException("Unexpected {$label} {$field} value.");
    }
}

function requireLabTypedCustomField(array $fields, string $field, mixed $expected): void
{
    $actual = $fields[$field] ?? null;
    // NetBox API versions serialize JSON custom fields either as a decoded
    // value or as a JSON string. Compare the semantic payload, not transport
    // representation, while keeping all other field types strict.
    if (is_array($expected) && is_string($actual)) {
        $decoded = json_decode($actual, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $actual = $decoded;
        }
    }
    $matches = is_array($expected) && is_array($actual)
        ? $actual == $expected
        : $actual === $expected;
    if (! $matches) {
        throw new RuntimeException("NetBox custom field {$field} did not retain its typed value (expected ".get_debug_type($expected).', received '.get_debug_type($actual).').');
    }
}

function assertLabSecretsAbsent(string $contents, string $scope): void
{
    foreach (['lab-snmp-community-do-not-export', 'lab-snmp-password-do-not-export', 'sensitive-value-excluded'] as $marker) {
        if (str_contains($contents, $marker)) {
            throw new RuntimeException("Sensitive laboratory value leaked into {$scope}.");
        }
    }
    if (preg_match_all('/"([^"]+)"\s*:/', $contents, $matches) === false) {
        throw new RuntimeException("Unable to inspect laboratory fields in {$scope}.");
    }
    foreach ($matches[1] as $field) {
        if (preg_match('/^(?:snmp_.*|permissions?|users?(?:name|groups?)?|api(?:_|-)?(?:key|token|secret)|vault(?:_|-).*)$/i', $field) === 1) {
            // Reporting only the field name is safe and makes the real-lab
            // guard actionable without exposing any secret value.
            throw new RuntimeException("Sensitive laboratory field {$field} leaked into {$scope}.");
        }
    }
}

/**
 * Compare only properties that both supported phpIPAM transport paths expose.
 * The dump intentionally has additional modules/rows, so absence from the API
 * is not a discrepancy; a disagreement for a record exposed by both paths is.
 *
 * @param  array<string, mixed>  $apiSource
 * @param  array<string, mixed>  $dumpSource
 */
function assertCommonApiDumpEquivalence(array $apiSource, array $dumpSource): void
{
    $fields = [
        'vrfs' => ['name', 'rd', 'tenant_source_id'],
        'vlan_groups' => ['name', 'description'],
        'vlans' => ['vid', 'name', 'vlan_group_source_id', 'tenant_source_id'],
        'prefixes' => ['prefix', 'vrf_source_id', 'vlan_source_id', 'tenant_source_id', 'is_pool', 'mark_utilized', 'is_folder'],
        'ip_addresses' => ['address', 'prefix_source_id', 'vrf_source_id', 'hostname', 'dns_name', 'tenant_source_id'],
    ];
    foreach ($fields as $type => $properties) {
        $apiObjects = [];
        foreach ($apiSource['objects'][$type] ?? [] as $object) {
            if (is_array($object) && ($sourceId = (string) ($object['source_id'] ?? '')) !== '') {
                $apiObjects[$sourceId] = $object;
            }
        }
        $shared = 0;
        foreach ($dumpSource['objects'][$type] ?? [] as $object) {
            if (! is_array($object) || ($sourceId = (string) ($object['source_id'] ?? '')) === '' || ! isset($apiObjects[$sourceId])) {
                continue;
            }
            $shared++;
            foreach ($properties as $property) {
                if (($apiObjects[$sourceId][$property] ?? null) !== ($object[$property] ?? null)) {
                    throw new RuntimeException("phpIPAM API/dump mismatch for {$type} {$sourceId} field {$property}.");
                }
            }
        }
        if ($shared === 0) {
            throw new RuntimeException("phpIPAM API/dump comparison has no shared {$type} records.");
        }
    }
}

/** @param array<string, mixed> $source */
function approvedExtendedMapping(array $source): array
{
    $mapping = MappingPolicy::v2Defaults();
    foreach ([
        'customer' => 'tenant', 'section' => 'tag', 'tag' => 'tag', 'location' => 'location',
        'rack' => 'rack', 'device_role' => 'device_role', 'device' => 'device',
        'interface' => 'interface', 'mac_address' => 'mac_address', 'provider' => 'provider',
        'circuit_type' => 'circuit_type', 'circuit' => 'circuit', 'asn' => 'asn',
    ] as $sourceType => $targetType) {
        $mapping['object_policies'][$sourceType] = ['policy' => 'migrate', 'target_type' => $targetType];
    }
    $mapping['update_rules']['ip_address'] = ['assigned_object_type', 'assigned_object_id'];

    $objects = is_array($source['objects'] ?? null) ? $source['objects'] : [];
    $locations = is_array($objects['locations'] ?? null) ? $objects['locations'] : [];
    $locationRules = [];
    $campusId = null;
    foreach ($locations as $index => $location) {
        if (! is_array($location)) {
            continue;
        }
        $sourceId = (string) ($location['source_id'] ?? '');
        if ($sourceId === '') {
            continue;
        }
        if ($index === 0) {
            $campusId = $sourceId;
            $locationRules[$sourceId] = [
                'kind' => 'site', 'name' => (string) ($location['name'] ?? 'Lab site'),
                'slug' => 'lab-site-'.$sourceId, 'approved' => true,
            ];
        } else {
            $locationRules[$sourceId] = [
                'kind' => 'location', 'name' => (string) ($location['name'] ?? 'Lab location'),
                'slug' => 'lab-location-'.$sourceId, 'site_source_id' => $campusId, 'approved' => true,
            ];
        }
    }

    $categories = [];
    foreach ($objects['devices'] ?? [] as $device) {
        if (! is_array($device) || ($categoryId = (string) ($device['category_source_id'] ?? '')) === '') {
            continue;
        }
        $categories[$categoryId] = [
            'manufacturer' => ['name' => 'IpamFerry Lab', 'slug' => 'ipamferry-lab', 'approved' => true],
            'device_type' => ['model' => 'phpIPAM device category '.$categoryId, 'slug' => 'phpipam-category-'.$categoryId, 'approved' => true],
            'interface_type' => '1000base-t',
        ];
    }
    $completeCircuitIds = [];
    foreach ($objects['circuits'] ?? [] as $circuit) {
        if (is_array($circuit)
            && ($circuit['location_a_source_id'] ?? null) !== null
            && ($circuit['location_z_source_id'] ?? null) !== null) {
            $completeCircuitIds[] = (string) $circuit['source_id'];
        }
    }
    $confirmedNatIds = [];
    foreach ($objects['nat_relations'] ?? [] as $nat) {
        // Older phpIPAM API controllers do not consistently expose the NAT
        // display name. Use the canonical source ID selected by the preview;
        // only static, port-less rows are preselected in this laboratory.
        if (is_array($nat) && (($nat['source_kind'] ?? null) === 'nat_table') && (($nat['has_ports'] ?? false) !== true)) {
            $confirmedNatIds[] = (string) $nat['source_id'];
        }
    }
    $mapping['relation_rules'] = [
        ['id' => 'lab-location-classification', 'relation' => 'location_classification', 'enabled' => true, 'settings' => ['locations' => $locationRules]],
        ['id' => 'lab-device-defaults', 'relation' => 'device_defaults', 'enabled' => true, 'settings' => ['categories' => $categories]],
        ['id' => 'lab-primary-ip', 'relation' => 'primary_ip', 'enabled' => true, 'settings' => []],
        ['id' => 'lab-customer-contacts', 'relation' => 'customer_contacts', 'enabled' => true, 'settings' => [
            'contact_role' => ['id' => 'lab-customer', 'name' => 'Customer', 'slug' => 'customer', 'approved' => true],
        ]],
        ['id' => 'lab-circuit-terminations', 'relation' => 'circuit_terminations', 'enabled' => true, 'settings' => ['circuit_ids' => $completeCircuitIds]],
        ['id' => 'lab-asn-defaults', 'relation' => 'asn_defaults', 'enabled' => true, 'settings' => [
            'rir' => ['id' => 'lab-private-rir', 'name' => 'Private', 'slug' => 'private', 'is_private' => true, 'approved' => true],
        ]],
        ['id' => 'lab-nat-safety', 'relation' => 'nat_1to1', 'enabled' => true, 'settings' => ['confirmed' => true, 'relation_ids' => $confirmedNatIds]],
    ];

    return $mapping;
}

$root = (string) (getenv('IPAMFERRY_APP_PATH') ?: dirname(__DIR__, 2));
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$readToken = (string) getenv('IPAMFERRY_LAB_READ_TOKEN');
$dumpPath = (string) getenv('IPAMFERRY_LAB_DUMP_PATH');
$primaryFamily = (string) (getenv('IPAMFERRY_LAB_PRIMARY_FAMILY') ?: 'ipv4');
if ($readToken === '' || ! is_readable($dumpPath)) {
    throw new RuntimeException('The laboratory read token and a readable real dump are required.');
}
if (! in_array($primaryFamily, ['ipv4', 'ipv6'], true)) {
    throw new RuntimeException('The laboratory primary IP family must be ipv4 or ipv6.');
}

$sandbox = app(SandboxConnection::class)->credentials();
$apiInventory = (new PhpIpamClient('https://phpipam-proxy:8443', 'ipamferry-read', $readToken))->inventory();
$parser = app(SqlDumpParser::class);
$parsed = $parser->parseFile($dumpPath);
$dumpInventory = [
    'schema_version' => 2,
    'instance' => ['kind' => 'phpipam_dump', 'fingerprint' => hash_file('sha256', $dumpPath)],
    'objects' => $parser->toInventoryObjects($parsed),
    'custom_fields' => $parser->customFieldDefinitions($parsed),
    'warnings' => $parsed['_warnings'] ?? [],
];
$normalizer = app(SourceNormalizer::class);
$apiSource = $normalizer->normalize($apiInventory);
$dumpSource = $normalizer->normalize($dumpInventory);
assertLabSecretsAbsent(json_encode([$apiSource, $dumpSource], JSON_THROW_ON_ERROR), 'normalized source snapshots');
foreach (['api', 'users'] as $sensitiveTable) {
    if (array_key_exists($sensitiveTable, $parsed)) {
        throw new RuntimeException("Sensitive phpIPAM {$sensitiveTable} rows reached the dump parser output.");
    }
}
if (! isset($dumpSource['sensitive_excluded']['scan_agents'])) {
    throw new RuntimeException('phpIPAM scan-agent records were not classified as sensitive_excluded.');
}

foreach (['vrfs', 'vlan_groups', 'vlans', 'prefixes', 'ip_addresses'] as $type) {
    if (count($apiSource['objects'][$type] ?? []) === 0 || count($dumpSource['objects'][$type] ?? []) === 0) {
        throw new RuntimeException("Real API/dump comparison has no {$type} records.");
    }
}
assertCommonApiDumpEquivalence($apiSource, $dumpSource);

foreach (['0', '4095'] as $invalidVid) {
    requireLabObject($dumpSource['objects']['vlans'] ?? [], 'vid', $invalidVid, 'invalid VLAN source record');
}

$unicodeDescription = "Comentários IPv6 — Bogotá\r\nsecond line with \"quotes\" & symbols";
foreach ([$apiSource, $dumpSource] as $source) {
    foreach ([
        '10.120.1.0/31', '10.120.1.2/32', '2001:db8:120:1::/127', '2001:db8:120:3::/126', '2001:db8:120:2::/128',
        '0.0.0.0/0', '128.0.0.0/1', '198.51.100.0/30', 'fe80::/64', 'fd12:3456:789a::/64', 'ff3e::/64',
    ] as $prefix) {
        requireLabObject($source['objects']['prefixes'] ?? [], 'prefix', $prefix, 'normalized source prefix');
    }
    foreach ([
        '10.120.1.0/31', '10.120.1.1/31', '10.120.1.2/32', '2001:db8:120:1::/127', '2001:db8:120:1::1/127', '2001:db8:120:3::3/126', '2001:db8:120:2::/128',
        '10.120.0.1/24', '10.120.0.2/24', '10.120.0.3/24', '10.121.0.2/24',
        '0.0.0.0/0', '128.0.0.1/1', '198.51.100.3/30', 'fe80::1234/64', 'fd12:3456:789a::abcd/64', 'ff3e::8000:1/64',
    ] as $address) {
        requireLabObject($source['objects']['ip_addresses'] ?? [], 'address', $address, 'normalized source address');
    }
}
$sourceCustomPrefix = requireLabObject($dumpSource['objects']['prefixes'] ?? [], 'prefix', '10.120.0.0/24', 'source custom-field prefix');
$apiCustomPrefix = null;
foreach ($apiSource['objects']['prefixes'] ?? [] as $prefix) {
    if (is_array($prefix) && ($prefix['legacy']['lab_cf_text'] ?? null) === 'Unicode text Ñandú') {
        $apiCustomPrefix = $prefix;
        break;
    }
}
if (! is_array($apiCustomPrefix)) {
    throw new RuntimeException('The phpIPAM API did not expose the seeded custom-field prefix.');
}
foreach ([
    'lab_cf_text' => 'Unicode text Ñandú',
    'lab_cf_integer' => '42',
    'lab_cf_boolean' => '1',
    'lab_cf_date' => '2026-07-26',
    'lab_cf_url' => 'https://example.test/ipamferry',
    'lab_cf_json' => '{"environment":"lab","enabled":true}',
    'lab_cf_selection' => 'production',
] as $field => $expected) {
    if ((string) (($sourceCustomPrefix['legacy'][$field] ?? null)) !== $expected) {
        throw new RuntimeException("Real dump custom field {$field} was not retained safely.");
    }
    if ((string) (($apiCustomPrefix['legacy'][$field] ?? null)) !== $expected) {
        throw new RuntimeException("phpIPAM API custom field {$field} diverges from the real dump.");
    }
}

$target = (new NetBoxClient($sandbox['url'], $sandbox['token']))->inventory();
$sourceBoundaryAddress = requireLabObject($dumpSource['objects']['ip_addresses'] ?? [], 'address', '10.120.1.2/32', 'source IPv4 host route');
$boundaryDescription = (string) ($sourceBoundaryAddress['description'] ?? '');
$boundaryComment = (string) (($sourceBoundaryAddress['legacy']['note'] ?? '') ?: '');
$unicodeVlanName = (string) (requireLabObject(
    $dumpSource['objects']['vlans'] ?? [],
    'vid',
    '121',
    'source Unicode VLAN',
)['name'] ?? '');
if ($boundaryDescription === '' || (int) ($target['write_schema']['ip_address']['description']['max_length'] ?? 0) < mb_strlen($boundaryDescription)) {
    throw new RuntimeException('The shared phpIPAM/NetBox description length constraint is incompatible.');
}
$mapping = MappingPolicy::v2Defaults();
$mapping['field_rules'] = [[
    'id' => 'lab-note-to-comments',
    'source_type' => 'ip_address',
    'source_field' => 'note',
    'target' => 'comments',
    'target_kind' => 'field',
    'action' => 'copy',
], [
    'id' => 'lab-environment-choice',
    'source_type' => 'ip_address',
    'target' => 'lab_environment',
    'target_kind' => 'custom_field',
    'action' => 'fixed',
    'value' => 'production',
    'data_type' => 'select',
    'choice_set' => [
        'name' => 'IpamFerry laboratory environment',
        'choices' => ['production', 'staging'],
        'approved' => true,
    ],
], [
    'id' => 'lab-prefix-text', 'source_type' => 'prefix', 'source_field' => 'lab_cf_text',
    'target' => 'lab_prefix_text', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'text',
], [
    'id' => 'lab-prefix-integer', 'source_type' => 'prefix', 'source_field' => 'lab_cf_integer',
    'target' => 'lab_prefix_integer', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'integer',
], [
    'id' => 'lab-prefix-boolean', 'source_type' => 'prefix', 'source_field' => 'lab_cf_boolean',
    'target' => 'lab_prefix_boolean', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'boolean',
], [
    'id' => 'lab-prefix-date', 'source_type' => 'prefix', 'source_field' => 'lab_cf_date',
    'target' => 'lab_prefix_date', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'date',
], [
    'id' => 'lab-prefix-url', 'source_type' => 'prefix', 'source_field' => 'lab_cf_url',
    'target' => 'lab_prefix_url', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'url',
], [
    'id' => 'lab-prefix-json', 'source_type' => 'prefix', 'source_field' => 'lab_cf_json',
    'target' => 'lab_prefix_json', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'json',
], [
    'id' => 'lab-prefix-selection', 'source_type' => 'prefix', 'source_field' => 'lab_cf_selection',
    'target' => 'lab_prefix_environment', 'target_kind' => 'custom_field', 'action' => 'copy', 'data_type' => 'select',
    'choice_set' => [
        'name' => 'IpamFerry prefix environment',
        'choices' => ['production', 'staging'],
        'approved' => true,
    ],
]];
$unapprovedMapping = $mapping;
foreach ($unapprovedMapping['field_rules'] as &$rule) {
    if (($rule['id'] ?? null) === 'lab-prefix-selection') {
        $rule['choice_set']['approved'] = false;
    }
}
unset($rule);
$unapprovedPlan = app(MigrationPlanner::class)->plan($dumpSource, $target, $unapprovedMapping);
if (! in_array('auxiliary_creation_unapproved', array_column($unapprovedPlan['conflicts'], 'reason'), true)) {
    throw new RuntimeException('An unapproved custom-field choice set was not blocked before apply.');
}
$user = User::query()->firstOrCreate(
    ['email' => 'lab-runner@example.test'],
    [
        'name' => 'Laboratory Runner',
        'password' => password_hash(Str::random(40), PASSWORD_DEFAULT),
        'role' => UserRole::Owner,
        'is_active' => true,
    ],
);
$project = MigrationProject::query()->create([
    'name' => 'Real lab '.now()->format('YmdHis'),
    'source_kind' => 'dump',
    'locale' => 'en',
    'status' => MigrationProjectStatus::Discovered,
    'created_by' => $user->id,
    'mapping' => $mapping,
    'source_instance' => $dumpSource['instance'],
    'target_instance' => $target['instance'],
    'source_snapshot' => $dumpSource,
    'target_snapshot' => $target,
]);
(new BuildMigrationPlan($project->id, $user->id))->handle(
    app(MigrationPlanner::class),
    app(MigrationAudit::class),
    app(MigrationOperationLock::class),
);
$plan = $project->plans()->latest('id')->firstOrFail();
if ($plan->conflicts !== []) {
    throw new RuntimeException('The real dump plan has conflicts: '.json_encode($plan->conflicts, JSON_THROW_ON_ERROR));
}
assertLabSecretsAbsent(json_encode([$project->source_snapshot, $plan->actions, $plan->warnings], JSON_THROW_ON_ERROR), 'IpamFerry project and plan');
if ($plan->actions === []) {
    throw new RuntimeException('The real dump plan has no actions.');
}
if (! str_contains(implode("\n", $plan->warnings), 'netbox_prefix_zero_length_preserved')
    || ! str_contains(implode("\n", $plan->warnings), 'netbox_ip_address_zero_length_preserved')
    || ! str_contains(implode("\n", $plan->warnings), 'vlan_vid_out_of_range_preserved')
    || ! str_contains(implode("\n", $plan->warnings), 'prefix_folder_preserved')) {
    throw new RuntimeException('Unsafe zero-length, folder, or VLAN records were not preserved explicitly before apply.');
}
$plan->approve($user);
try {
    // Deliberately stop after every action and resume the same execution.
    // This exercises persisted checkpoints across all resource phases with a
    // real NetBox API, rather than treating a single in-process apply as
    // evidence of recovery safety.
    $execution = null;
    $checkpointCount = 0;
    do {
        $execution = app(MigrationApplier::class)->apply(
            $project->fresh(),
            $plan->fresh(),
            $sandbox['url'],
            $sandbox['token'],
            $user->id,
            1,
        );
        $checkpointCount++;
        if ($checkpointCount > count($plan->actions) + 1) {
            throw new RuntimeException('Checkpoint recovery did not make forward progress.');
        }
    } while ($execution->status === MigrationExecutionStatus::Applying);
} catch (Throwable $exception) {
    $failed = $project->executions()->latest('id')->first()?->actionResults()
        ->where('status', 'failed')
        ->first();
    $action = is_object($failed)
        ? ($plan->actions[$failed->action_index] ?? null)
        : null;
    $identity = is_array($action) ? [
        'target_type' => $action['target_type'] ?? null,
        'source_type' => $action['source_type'] ?? null,
        'source_id' => $action['source_id'] ?? null,
        'natural_key' => $action['natural_key'] ?? null,
    ] : ['action' => 'unavailable'];

    throw new RuntimeException('Real dump apply failed for sanitized action '.json_encode($identity, JSON_THROW_ON_ERROR), previous: $exception);
}
if ($execution->status !== MigrationExecutionStatus::Applied) {
    throw new RuntimeException('The real dump plan was not applied.');
}
if ($checkpointCount < 2 || $execution->summary['completed'] !== count($plan->actions)) {
    throw new RuntimeException('The real dump plan did not persist and resume every apply checkpoint.');
}
$verification = app(MigrationVerifier::class)->verify($project->fresh(), $plan->fresh(), $execution, $sandbox['url'], $sandbox['token'], $user->id);
if (! ($verification['passed'] ?? false)) {
    throw new RuntimeException('The real dump plan verification failed.');
}
$sameExecution = app(MigrationApplier::class)->apply($project->fresh(), $plan->fresh(), $sandbox['url'], $sandbox['token'], $user->id);
if ($sameExecution->id !== $execution->id || $sameExecution->status !== MigrationExecutionStatus::Verified) {
    throw new RuntimeException('The repeated apply was not idempotent.');
}
$bundlePath = app(BundleBuilder::class)->build($project->fresh(), $plan->fresh());
$bundle = new ZipArchive;
if ($bundle->open($bundlePath) !== true) {
    throw new RuntimeException('Unable to inspect the real migration bundle.');
}
try {
    for ($index = 0; $index < $bundle->numFiles; $index++) {
        $entry = $bundle->getFromIndex($index);
        if (is_string($entry)) {
            assertLabSecretsAbsent($entry, 'migration bundle');
        }
    }
} finally {
    $bundle->close();
}
$databaseEvidence = [
    'project' => $project->fresh()->only([
        'mapping', 'source_snapshot', 'target_snapshot', 'source_instance', 'target_instance', 'discovery_manifest', 'last_error',
    ]),
    'plans' => $project->plans()->get()->map(fn ($item): array => $item->only([
        'mapping_snapshot', 'preservation', 'actions', 'conflicts', 'warnings', 'target_instance',
    ]))->all(),
    'executions' => $project->executions()->with('actionResults')->get()->map(fn ($item): array => [
        ...$item->only(['summary', 'last_error']),
        'action_results' => $item->actionResults->map(fn ($result): array => $result->only(['error', 'result']))->all(),
    ])->all(),
    'events' => $project->events()->get()->map(fn ($event): array => $event->only(['kind', 'level', 'context']))->all(),
];
assertLabSecretsAbsent(json_encode($databaseEvidence, JSON_THROW_ON_ERROR), 'IpamFerry database records');
foreach (glob(storage_path('logs/*')) ?: [] as $logPath) {
    if (is_file($logPath)) {
        assertLabSecretsAbsent((string) file_get_contents($logPath), 'IpamFerry application logs');
    }
}
$after = (new NetBoxClient($sandbox['url'], $sandbox['token']))->inventory();
foreach ([
    '10.120.1.0/31', '10.120.1.2/32', '2001:db8:120:1::/127', '2001:db8:120:3::/126', '2001:db8:120:2::/128',
    '10.120.0.0/25', '10.121.0.0/24', '10.121.1.0/24', '128.0.0.0/1', '198.51.100.0/30',
    'fe80::/64', 'fd12:3456:789a::/64', 'ff3e::/64',
] as $prefix) {
    requireLabObject($after['objects']['prefixes'] ?? [], 'prefix', $prefix, 'NetBox prefix');
}
foreach ($after['objects']['prefixes'] ?? [] as $prefix) {
    if (($prefix['prefix'] ?? null) === '0.0.0.0/0') {
        throw new RuntimeException('NetBox must not receive an unsupported zero-length prefix.');
    }
}
foreach ([1, 4094, 121, 122] as $vid) {
    requireLabObject($after['objects']['vlans'] ?? [], 'vid', (string) $vid, 'NetBox VLAN');
}
foreach ([0, 4095] as $invalidVid) {
    foreach ($after['objects']['vlans'] ?? [] as $vlan) {
        if ((int) ($vlan['vid'] ?? -1) === $invalidVid) {
            throw new RuntimeException('NetBox must not receive an unsupported VLAN ID.');
        }
    }
}
requireLabObject($after['objects']['vlans'] ?? [], 'name', $unicodeVlanName, 'NetBox Unicode VLAN');
$poolPrefix = requireLabObject($after['objects']['prefixes'] ?? [], 'prefix', '10.121.0.0/24', 'NetBox pool prefix');
$fullPrefix = requireLabObject($after['objects']['prefixes'] ?? [], 'prefix', '10.121.1.0/24', 'NetBox utilized prefix');
if (($poolPrefix['is_pool'] ?? false) !== true || ($fullPrefix['mark_utilized'] ?? false) !== true) {
    throw new RuntimeException('Pool or utilized prefix state was not retained by NetBox.');
}
$boundaryAddress = requireLabObject($after['objects']['ip_addresses'] ?? [], 'address', '10.120.1.2/32', 'NetBox IPv4 host route');
requireLabValue($boundaryAddress, 'description', $boundaryDescription, 'NetBox IPv4 host route');
requireLabValue($boundaryAddress, 'comments', $boundaryComment, 'NetBox IPv4 host route');
$unicodeAddress = requireLabObject($after['objects']['ip_addresses'] ?? [], 'address', '2001:db8:120:1::1/127', 'NetBox IPv6 point-to-point endpoint');
requireLabValue($unicodeAddress, 'description', $unicodeDescription, 'NetBox IPv6 point-to-point endpoint');
$invalidDnsAddress = requireLabObject($after['objects']['ip_addresses'] ?? [], 'address', '10.120.0.5/24', 'NetBox IP with preserved invalid DNS name');
if (($invalidDnsAddress['dns_name'] ?? '') !== '') {
    throw new RuntimeException('An invalid phpIPAM hostname was sent to NetBox instead of being preserved.');
}
requireLabValue($unicodeAddress['custom_fields'] ?? [], 'lab_environment', 'production', 'NetBox selection custom field');
$choiceSet = requireLabObject($after['objects']['custom_field_choice_sets'] ?? [], 'name', 'IpamFerry laboratory environment', 'NetBox custom-field choice set');
if (count($choiceSet['extra_choices'] ?? []) !== 2) {
    throw new RuntimeException('NetBox selection custom-field choice set is incomplete.');
}
$targetCustomPrefix = null;
foreach ($after['objects']['prefixes'] ?? [] as $prefix) {
    if (is_array($prefix) && ($prefix['custom_fields']['lab_prefix_text'] ?? null) === 'Unicode text Ñandú') {
        $targetCustomPrefix = $prefix;
        break;
    }
}
if (! is_array($targetCustomPrefix)) {
    throw new RuntimeException('NetBox prefix with the real custom-field payload is missing.');
}
$prefixFields = $targetCustomPrefix['custom_fields'] ?? [];
foreach ([
    'lab_prefix_text' => 'Unicode text Ñandú',
    'lab_prefix_integer' => 42,
    'lab_prefix_boolean' => true,
    'lab_prefix_date' => '2026-07-26',
    'lab_prefix_url' => 'https://example.test/ipamferry',
    'lab_prefix_json' => ['environment' => 'lab', 'enabled' => true],
    'lab_prefix_environment' => 'production',
] as $field => $expected) {
    requireLabTypedCustomField($prefixFields, $field, $expected);
}
$extendedMapping = approvedExtendedMapping($dumpSource);
$extendedProject = MigrationProject::query()->create([
    'name' => 'Real extended dump lab '.now()->format('YmdHis'),
    'source_kind' => 'dump',
    'locale' => 'en',
    'status' => MigrationProjectStatus::Discovered,
    'created_by' => $user->id,
    'mapping' => $extendedMapping,
    'source_instance' => $dumpSource['instance'],
    'target_instance' => $after['instance'],
    'source_snapshot' => $dumpSource,
    'target_snapshot' => $after,
]);
(new BuildMigrationPlan($extendedProject->id, $user->id))->handle(
    app(MigrationPlanner::class),
    app(MigrationAudit::class),
    app(MigrationOperationLock::class),
);
$extendedPlan = $extendedProject->plans()->latest('id')->firstOrFail();
if ($extendedPlan->conflicts !== []) {
    throw new RuntimeException('The approved extended dump plan has conflicts: '.json_encode($extendedPlan->conflicts, JSON_THROW_ON_ERROR));
}
foreach (['tenant', 'site', 'location', 'rack', 'device', 'interface', 'mac_address', 'provider', 'circuit', 'asn'] as $type) {
    if (! in_array($type, array_column($extendedPlan->actions, 'target_type'), true)) {
        throw new RuntimeException("The approved extended dump plan is missing {$type}.");
    }
}
foreach (['nat_ip_pair_required', 'pat_preserved', 'nat_cross_vrf_preserved', 'nat_many_to_many_preserved'] as $warning) {
    if (! str_contains(implode("\n", $extendedPlan->warnings), $warning)) {
        throw new RuntimeException("The laboratory NAT scenario {$warning} was not preserved explicitly: ".json_encode([
            'confirmed_relation_ids' => collect($extendedMapping['relation_rules'])->firstWhere('relation', 'nat_1to1')['settings']['relation_ids'] ?? [],
            'relations' => array_map(fn (array $relation): array => array_intersect_key($relation, array_flip([
                'source_id', 'source_kind', 'inside_ip_source_id', 'outside_ip_source_id', 'inside_vrf_source_id', 'outside_vrf_source_id', 'has_ports',
            ])), $dumpSource['objects']['nat_relations'] ?? []),
            'warnings' => $extendedPlan->warnings,
        ], JSON_THROW_ON_ERROR));
    }
}
if (! str_contains(implode("\n", $extendedPlan->warnings), 'customer_contact_invalid_preserved')) {
    throw new RuntimeException('The invalid customer contact was not preserved explicitly.');
}
if (! str_contains(implode("\n", $extendedPlan->warnings), 'ip_dns_name_invalid_preserved')) {
    throw new RuntimeException('The invalid phpIPAM hostname was not preserved explicitly.');
}
if (! in_array('nat_1to1', array_column(array_filter(
    $extendedPlan->actions,
    fn (array $action): bool => ($action['operation'] ?? null) === 'relation',
), 'relation'), true)) {
    $natRule = collect($extendedMapping['relation_rules'])->firstWhere('relation', 'nat_1to1');
    throw new RuntimeException('The confirmed same-VRF static NAT relation was not planned: '.json_encode([
        'confirmed_relation_ids' => $natRule['settings']['relation_ids'] ?? [],
        'relations' => $dumpSource['objects']['nat_relations'] ?? [],
        'warnings' => $extendedPlan->warnings,
    ], JSON_THROW_ON_ERROR));
}
$extendedPlan->approve($user);
try {
    $extendedExecution = app(MigrationApplier::class)->apply($extendedProject->fresh(), $extendedPlan->fresh(), $sandbox['url'], $sandbox['token'], $user->id);
} catch (Throwable $exception) {
    $failedExecution = $extendedProject->executions()->latest('id')->first();
    $failedResult = $failedExecution?->actionResults()->where('status', 'failed')->first();
    $failedAction = is_object($failedResult)
        ? ($extendedPlan->actions[$failedResult->action_index] ?? null)
        : null;
    $preceding = $failedExecution?->actionResults()
        ->whereIn('target_type', ['interface', 'ip_address'])
        ->get()
        ->map(fn ($result): array => $result->only(['action_index', 'operation', 'status', 'target_type', 'target_id', 'error', 'result']))
        ->all() ?? [];
    throw new RuntimeException('Extended apply failed with sanitized dependency state: '.json_encode([
        'failed_action' => $failedAction,
        'ip_and_interface_results' => $preceding,
    ], JSON_THROW_ON_ERROR), previous: $exception);
}
$extendedVerification = app(MigrationVerifier::class)->verify($extendedProject->fresh(), $extendedPlan->fresh(), $extendedExecution, $sandbox['url'], $sandbox['token'], $user->id);
if ($extendedExecution->refresh()->status !== MigrationExecutionStatus::Verified || ! ($extendedVerification['passed'] ?? false)) {
    throw new RuntimeException('The approved extended dump plan did not apply and verify.');
}
$extendedAfter = (new NetBoxClient($sandbox['url'], $sandbox['token']))->inventory();
foreach ([
    ['tenants', 'name', 'Example Tenant'], ['sites', 'name', 'Lab Campus'], ['locations', 'name', 'Lab Room A'],
    ['racks', 'name', 'LAB-RACK-01'], ['devices', 'name', 'lab-rtr-01'], ['interfaces', 'name', 'ge-0/0/0'],
    ['providers', 'name', 'Example Carrier'], ['circuits', 'cid', 'LAB-CIR-001'], ['asns', 'asn', '65000'],
] as [$collection, $field, $value]) {
    requireLabObject($extendedAfter['objects'][$collection] ?? [], $field, $value, "NetBox extended {$collection}");
}
requireLabObject($extendedAfter['objects']['tenants'] ?? [], 'name', 'Invalid Contact Tenant', 'NetBox tenant with preserved contact');
foreach ($extendedAfter['objects']['contacts'] ?? [] as $contact) {
    if (($contact['email'] ?? null) === 'invalid contact@example.test') {
        throw new RuntimeException('NetBox received a contact with an invalid phpIPAM email address.');
    }
}
$natOutside = requireLabObject($extendedAfter['objects']['ip_addresses'] ?? [], 'address', '10.121.0.2/24', 'NetBox static NAT outside IP');
$natInside = $natOutside['nat_inside'] ?? null;
if (! is_array($natInside) || (string) ($natInside['address'] ?? '') !== '10.120.0.2/24') {
    throw new RuntimeException('The confirmed static NAT relation was not applied to the expected NetBox IP pair.');
}
$extendedDevice = requireLabObject($extendedAfter['objects']['devices'] ?? [], 'name', 'lab-rtr-01', 'NetBox device primary IP');
$primaryField = $primaryFamily === 'ipv6' ? 'primary_ip6' : 'primary_ip4';
$primaryAddress = $primaryFamily === 'ipv6' ? '2001:db8:120::2/64' : '10.120.0.2/24';
$primaryIp = $extendedDevice[$primaryField] ?? null;
if (! is_array($primaryIp) || (string) ($primaryIp['address'] ?? '') !== $primaryAddress) {
    throw new RuntimeException("The unambiguous device {$primaryFamily} primary relation was not applied.");
}
if (($extendedDevice[$primaryFamily === 'ipv6' ? 'primary_ip4' : 'primary_ip6'] ?? null) !== null) {
    throw new RuntimeException('The device primary IP family does not match the explicitly seeded primary source.');
}
$apiProject = MigrationProject::query()->create([
    'name' => 'Real API lab '.now()->format('YmdHis'),
    'source_kind' => 'api',
    'locale' => 'en',
    'status' => MigrationProjectStatus::Discovered,
    'created_by' => $user->id,
    'mapping' => $mapping,
    'source_instance' => $apiSource['instance'],
    // The extended dump plan may legitimately update IP assignments and
    // device primary IPs. Discover the destination again before producing
    // the API-source sibling plan so its ETags describe that real state.
    'target_instance' => $extendedAfter['instance'],
    'source_snapshot' => $apiSource,
    'target_snapshot' => $extendedAfter,
]);
(new BuildMigrationPlan($apiProject->id, $user->id))->handle(
    app(MigrationPlanner::class),
    app(MigrationAudit::class),
    app(MigrationOperationLock::class),
);
$apiPlan = $apiProject->plans()->latest('id')->firstOrFail();
if ($apiPlan->conflicts !== [] || $apiPlan->actions === []) {
    throw new RuntimeException('The real API plan is not applicable.');
}
$apiPlan->approve($user);
$apiExecution = app(MigrationApplier::class)->apply($apiProject->fresh(), $apiPlan->fresh(), $sandbox['url'], $sandbox['token'], $user->id);
$apiVerification = app(MigrationVerifier::class)->verify($apiProject->fresh(), $apiPlan->fresh(), $apiExecution, $sandbox['url'], $sandbox['token'], $user->id);
if ($apiExecution->refresh()->status !== MigrationExecutionStatus::Verified || ! ($apiVerification['passed'] ?? false)) {
    throw new RuntimeException('The real API plan did not apply and verify.');
}
$links = MigrationObjectLink::query()->where('project_id', $project->id)->count();
$result = [
    'project_id' => $project->id,
    'plan_id' => $plan->id,
    'execution_id' => $execution->id,
    'apply_checkpoints' => $checkpointCount,
    'api_counts' => array_map('count', $apiSource['objects']),
    'dump_counts' => array_map('count', $dumpSource['objects']),
    'actions' => count($plan->actions),
    'warnings' => count($plan->warnings),
    'verified_objects' => $verification['checked'],
    'object_links' => $links,
    'api_plan_actions' => count($apiPlan->actions),
    'api_verified_objects' => $apiVerification['checked'],
    'extended_plan_actions' => count($extendedPlan->actions),
    'extended_verified_objects' => $extendedVerification['checked'],
    'netbox_counts' => array_map(
        'count',
        array_intersect_key($after['objects'], array_flip(['vrfs', 'vlan_groups', 'vlans', 'prefixes', 'ip_addresses'])),
    ),
];

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
fwrite(STDOUT, "IPAMFERRY_LAB_SUCCESS\n");
