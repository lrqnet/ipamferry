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
use App\Domain\Migration\SandboxConnection;
use App\Domain\Migration\SourceNormalizer;
use App\Domain\Migration\SqlDumpParser;
use App\Enums\MigrationExecutionStatus;
use App\Enums\MigrationProjectStatus;
use App\Enums\UserRole;
use App\Jobs\BuildMigrationPlan;
use App\Models\MigrationProject;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

$root = (string) (getenv('IPAMFERRY_APP_PATH') ?: dirname(__DIR__, 2));
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$dumpPath = (string) getenv('IPAMFERRY_EXTERNAL_DUMP_PATH');
$mappingPath = (string) getenv('IPAMFERRY_EXTERNAL_MAPPING_PATH');
if (! is_readable($dumpPath) || ! is_readable($mappingPath)) {
    throw new RuntimeException('A readable anonymized SQL dump and mapping JSON file are required.');
}
if (filesize($dumpPath) > 536870912) {
    throw new RuntimeException('The external SQL dump exceeds the 512 MiB validation limit.');
}

$mapping = json_decode((string) file_get_contents($mappingPath), true, 512, JSON_THROW_ON_ERROR);
if (! is_array($mapping)) {
    throw new RuntimeException('The external mapping must be a JSON object.');
}
$policy = new MappingPolicy($mapping);
if ($policy->validationIssues() !== []) {
    throw new RuntimeException('The external mapping is invalid.');
}

$parser = app(SqlDumpParser::class);
$parsed = $parser->parseFile($dumpPath);
$inventory = [
    'schema_version' => 2,
    'instance' => ['kind' => 'phpipam_dump', 'fingerprint' => hash_file('sha256', $dumpPath)],
    'objects' => $parser->toInventoryObjects($parsed),
    'custom_fields' => $parser->customFieldDefinitions($parsed),
    'warnings' => $parsed['_warnings'] ?? [],
];
$source = app(SourceNormalizer::class)->normalize($inventory);
$serializedSource = json_encode($source, JSON_THROW_ON_ERROR);
if (preg_match('/(?:snmp|password|token|secret|credential|community|vault|api[_-]?key)/i', $serializedSource) === 1) {
    throw new RuntimeException('The normalized external source contains a sensitive field name or value.');
}

$sandbox = app(SandboxConnection::class)->credentials();
$target = (new NetBoxClient($sandbox['url'], $sandbox['token']))->inventory();
$user = User::query()->firstOrCreate(
    ['email' => 'external-dump-runner@example.test'],
    [
        'name' => 'External Dump Runner',
        'password' => password_hash(Str::random(40), PASSWORD_DEFAULT),
        'role' => UserRole::Owner,
        'is_active' => true,
    ],
);
$project = MigrationProject::query()->create([
    'name' => 'External dump '.now()->format('YmdHis'),
    'source_kind' => 'dump',
    'locale' => 'en',
    'status' => MigrationProjectStatus::Discovered,
    'created_by' => $user->id,
    'mapping' => $policy->all(),
    'source_instance' => $source['instance'],
    'target_instance' => $target['instance'],
    'source_snapshot' => $source,
    'target_snapshot' => $target,
]);
(new BuildMigrationPlan($project->id, $user->id))->handle(
    app(MigrationPlanner::class),
    app(MigrationAudit::class),
    app(MigrationOperationLock::class),
);
$plan = $project->plans()->latest('id')->firstOrFail();
if ($plan->conflicts !== []) {
    throw new RuntimeException('The external dump plan has blocking conflicts. Resolve the supplied mapping before rerunning.');
}
if ($plan->actions === []) {
    throw new RuntimeException('The external dump plan has no applicable actions.');
}
$plan->approve($user);
$execution = null;
do {
    $execution = app(MigrationApplier::class)->apply(
        $project->fresh(),
        $plan->fresh(),
        $sandbox['url'],
        $sandbox['token'],
        $user->id,
        1,
    );
} while ($execution->status === MigrationExecutionStatus::Applying);
if ($execution->status !== MigrationExecutionStatus::Applied) {
    throw new RuntimeException('The external dump plan was not applied.');
}
$verification = app(MigrationVerifier::class)->verify($project->fresh(), $plan->fresh(), $execution, $sandbox['url'], $sandbox['token'], $user->id);
if (($verification['passed'] ?? false) !== true) {
    throw new RuntimeException('The external dump verification failed.');
}
$sameExecution = app(MigrationApplier::class)->apply($project->fresh(), $plan->fresh(), $sandbox['url'], $sandbox['token'], $user->id);
if ($sameExecution->id !== $execution->id || $sameExecution->status !== MigrationExecutionStatus::Verified) {
    throw new RuntimeException('The external dump rerun was not idempotent.');
}
$bundle = app(BundleBuilder::class)->build($project->fresh(), $plan->fresh());
$zip = new ZipArchive;
if ($zip->open($bundle) !== true) {
    throw new RuntimeException('The external dump bundle could not be inspected.');
}
for ($index = 0; $index < $zip->numFiles; $index++) {
    $contents = (string) $zip->getFromIndex($index);
    if (preg_match('/(?:snmp|password|token|secret|credential|community|vault|api[_-]?key)/i', $contents) === 1) {
        $zip->close();
        throw new RuntimeException('The external dump bundle contains a sensitive field name or value.');
    }
}
$zip->close();
@unlink($bundle);

echo json_encode([
    'status' => 'passed',
    'actions' => count($plan->actions),
    'preserved_categories' => array_keys(array_filter($plan->preservation, 'is_array')),
], JSON_THROW_ON_ERROR).PHP_EOL;
