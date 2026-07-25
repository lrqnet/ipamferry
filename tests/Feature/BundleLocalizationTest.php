<?php

namespace Tests\Feature;

use App\Domain\Migration\BundleBuilder;
use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationAudit;
use App\Domain\Migration\MigrationOperationLock;
use App\Domain\Migration\MigrationPlanner;
use App\Domain\Migration\SnapshotFingerprint;
use App\Enums\MigrationProjectStatus;
use App\Enums\UserRole;
use App\Jobs\BuildMigrationPlan;
use App\Models\MigrationPlan;
use App\Models\MigrationProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class BundleLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bundle_uses_project_locale_but_keeps_machine_plan_canonical(): void
    {
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password', 'role' => UserRole::Owner, 'locale' => 'en', 'is_active' => true]);
        $mapping = MappingPolicy::defaults();
        $sourceFingerprint = SnapshotFingerprint::make([]);
        $targetFingerprint = SnapshotFingerprint::make([]);
        $mappingFingerprint = CanonicalJson::fingerprint($mapping);
        $actions = [['operation' => 'create']];
        $conflicts = [];
        $warnings = ['devices require mapping review before export to NetBox.'];
        $preservation = ['unmigrated' => ['devices' => [['id' => 1]]]];
        $fingerprint = CanonicalJson::fingerprint([
            'schema_version' => 1,
            'engine_version' => config('ipamferry.version'),
            'source' => $sourceFingerprint,
            'target' => $targetFingerprint,
            'mapping' => $mappingFingerprint,
            'target_instance' => null,
            'plan' => compact('actions', 'conflicts', 'warnings', 'preservation'),
        ]);
        $project = MigrationProject::query()->create([
            'name' => 'Projeto',
            'source_kind' => 'dump',
            'locale' => 'pt_BR',
            'status' => MigrationProjectStatus::Planned,
            'created_by' => $user->id,
            'mapping' => $mapping,
            'source_snapshot' => [],
            'target_snapshot' => [],
        ]);
        $plan = MigrationPlan::query()->create([
            'project_id' => $project->id,
            'schema_version' => 1,
            'engine_version' => config('ipamferry.version'),
            'locale' => 'pt_BR',
            'fingerprint' => $fingerprint,
            'source_fingerprint' => $sourceFingerprint,
            'target_fingerprint' => $targetFingerprint,
            'mapping_fingerprint' => $mappingFingerprint,
            'mapping_snapshot' => $mapping,
            'preservation' => $preservation,
            'actions' => $actions,
            'conflicts' => $conflicts,
            'warnings' => $warnings,
        ]);

        $path = app(BundleBuilder::class)->build($project, $plan);
        $zip = new ZipArchive;
        $zip->open($path);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $report = json_decode((string) $zip->getFromName('report.json'), true, 512, JSON_THROW_ON_ERROR);
        $planJson = json_decode((string) $zip->getFromName('plan.json'), true, 512, JSON_THROW_ON_ERROR);
        $zip->close();

        self::assertSame('pt_BR', $manifest['locale']);
        self::assertSame('Relatório de migração do IpamFerry', $report['title']);
        self::assertSame(1, $report['preservation']['categories']['unmigrated']);
        self::assertSame('create', $planJson['actions'][0]['operation']);
    }

    public function test_changing_artifact_locale_produces_a_distinct_immutable_plan(): void
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'locale-owner@example.test',
            'password' => 'LongerPassword1!',
            'role' => UserRole::Owner,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $targetInstance = [
            'kind' => 'netbox',
            'url' => 'https://netbox.example.test',
            'version' => '4.6.1',
            'api_version' => '4.6',
            'fingerprint' => str_repeat('b', 64),
        ];
        $project = MigrationProject::query()->create([
            'name' => 'Locale plan',
            'source_kind' => 'dump',
            'locale' => 'en',
            'status' => MigrationProjectStatus::Discovered,
            'created_by' => $user->id,
            'mapping' => MappingPolicy::defaults(),
            'source_snapshot' => ['objects' => []],
            'target_snapshot' => ['objects' => [], 'instance' => $targetInstance],
            'source_instance' => ['fingerprint' => str_repeat('a', 64)],
            'target_instance' => $targetInstance,
        ]);

        $this->buildPlan($project);
        $englishPlan = $project->plans()->latest('id')->firstOrFail();
        $project->update(['locale' => 'es']);
        $this->buildPlan($project->fresh());
        $spanishPlan = $project->plans()->latest('id')->firstOrFail();

        self::assertNotSame($englishPlan->id, $spanishPlan->id);
        self::assertNotSame($englishPlan->fingerprint, $spanishPlan->fingerprint);
        self::assertSame('en', $englishPlan->locale);
        self::assertSame('es', $spanishPlan->locale);
    }

    private function buildPlan(MigrationProject $project): void
    {
        (new BuildMigrationPlan($project->id))->handle(
            app(MigrationPlanner::class),
            app(MigrationAudit::class),
            app(MigrationOperationLock::class),
        );
    }
}
