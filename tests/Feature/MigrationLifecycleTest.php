<?php

namespace Tests\Feature;

use App\Domain\Migration\BundleBuilder;
use App\Domain\Migration\EndpointPolicy;
use App\Domain\Migration\ExternalApiException;
use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationApplier;
use App\Domain\Migration\MigrationAudit;
use App\Domain\Migration\MigrationOperationLock;
use App\Domain\Migration\MigrationPlanner;
use App\Domain\Migration\MigrationVerifier;
use App\Enums\MigrationExecutionStatus;
use App\Enums\MigrationProjectStatus;
use App\Enums\UserRole;
use App\Jobs\BuildMigrationPlan;
use App\Models\MigrationProject;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MigrationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_plan_applies_idempotently_and_verifies_the_exact_target(): void
    {
        [$project, $plan, $user] = $this->plannedProject(1);
        $objects = [];
        $posts = 0;
        $this->fakeNetBox($objects, $posts);

        $execution = app(MigrationApplier::class)->apply(
            $project,
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
        );

        self::assertSame(MigrationExecutionStatus::Applied, $execution->status);
        self::assertSame(1, $execution->summary['completed']);
        self::assertSame(1, $posts);
        self::assertSame(MigrationProjectStatus::Applied, $project->refresh()->status);

        $sameExecution = app(MigrationApplier::class)->apply(
            $project,
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
        );
        self::assertSame($execution->id, $sameExecution->id);
        self::assertSame(1, $posts);

        $verification = app(MigrationVerifier::class)->verify(
            $project,
            $plan,
            $sameExecution,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
        );
        self::assertTrue($verification['passed']);
        self::assertSame(1, $verification['checked']);
        self::assertSame(MigrationExecutionStatus::Verified, $sameExecution->refresh()->status);
        $this->actingAs($user)
            ->post(route('projects.plan', $project))
            ->assertStatus(422);
        self::assertCount(1, $project->plans);
        self::assertStringNotContainsString(
            'runtime-token',
            json_encode([
                DB::table('migration_projects')->get(),
                DB::table('migration_plans')->get(),
                DB::table('migration_executions')->get(),
                DB::table('migration_action_results')->get(),
                DB::table('migration_object_links')->get(),
                DB::table('migration_events')->get(),
            ], JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            ['plan.generated', 'apply.completed', 'verification.completed'],
            DB::table('migration_events')->where('project_id', $project->id)->orderBy('id')->pluck('kind')->all(),
        );
        $bundle = app(BundleBuilder::class)->build($project, $plan->fresh());
        self::assertStringNotContainsString('runtime-token', (string) file_get_contents($bundle));
    }

    public function test_definition_stays_locked_until_the_active_execution_is_verified(): void
    {
        [$project, $plan, $user] = $this->plannedProject(2);
        $objects = [];
        $posts = 0;
        $this->fakeNetBox($objects, $posts);

        $execution = app(MigrationApplier::class)->apply(
            $project,
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
            1,
        );

        self::assertSame(MigrationExecutionStatus::Applying, $execution->status);
        try {
            app(MigrationOperationLock::class)->assertDefinitionMutable($project);
            self::fail('An active execution must lock discovery, mapping, and planning.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('must be resumed and verified', $exception->getMessage());
        }

        $execution = app(MigrationApplier::class)->apply(
            $project,
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
        );
        app(MigrationVerifier::class)->verify(
            $project,
            $plan,
            $execution,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
        );

        app(MigrationOperationLock::class)->assertDefinitionMutable($project->fresh());
        self::assertSame(MigrationExecutionStatus::Verified, $execution->fresh()->status);
    }

    public function test_apply_uses_persistent_batches_and_resumes_without_duplicate_creation(): void
    {
        [$project, $plan, $user] = $this->plannedProject(2);
        $objects = [];
        $posts = 0;
        $this->fakeNetBox($objects, $posts);

        $first = app(MigrationApplier::class)->apply(
            $project,
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
            1,
        );
        self::assertSame(MigrationExecutionStatus::Applying, $first->status);
        self::assertSame(1, $first->summary['completed']);
        self::assertSame(1, $first->summary['remaining']);

        $second = app(MigrationApplier::class)->apply(
            $project,
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
            1,
        );
        self::assertSame(MigrationExecutionStatus::Applied, $second->status);
        self::assertSame(2, $second->summary['completed']);
        self::assertSame(2, $posts);
        self::assertSame($first->id, $second->id);
    }

    public function test_lost_create_response_is_recovered_by_preflight_on_resume(): void
    {
        [$project, $plan, $user] = $this->plannedProject(1);
        $objects = [];
        $posts = 0;
        $loseResponse = true;
        $this->fakeNetBox($objects, $posts, $loseResponse);

        try {
            app(MigrationApplier::class)->apply(
                $project,
                $plan,
                'https://netbox.example.test',
                'nbt_test.runtime-token',
                $user->id,
            );
            self::fail('The simulated connection loss should fail the first attempt.');
        } catch (\Throwable) {
            self::assertCount(1, $objects);
        }

        $execution = app(MigrationApplier::class)->apply(
            $project->fresh(),
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
        );

        self::assertSame(MigrationExecutionStatus::Applied, $execution->status);
        self::assertSame(1, $posts);
        self::assertSame('reused', $execution->actionResults->first()->status->value);
        self::assertTrue($execution->actionResults->first()->result['recovered_after_response_loss']);
    }

    public function test_object_appearing_before_first_create_attempt_requires_a_new_plan(): void
    {
        [$project, $plan, $user] = $this->plannedProject(1);
        $objects = [[
            'id' => 100,
            'name' => 'VRF 1',
            'rd' => '65000:1',
            'description' => '',
        ]];
        $posts = 0;
        $this->fakeNetBox($objects, $posts);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('appeared after planning');

        try {
            app(MigrationApplier::class)->apply(
                $project,
                $plan,
                'https://netbox.example.test',
                'nbt_test.runtime-token',
                $user->id,
            );
        } finally {
            self::assertSame(0, $posts);
        }
    }

    public function test_etag_drift_before_an_explicit_patch_blocks_apply_without_writing(): void
    {
        [$project, $plan, $user] = $this->plannedUpdateProject();
        $patches = 0;
        Http::fake(function (Request $request) use (&$patches) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/status/') {
                return Http::response(['netbox-version' => '4.5.3', 'plugins' => []], 200, ['API-Version' => '4.5']);
            }
            if ($path === '/api/ipam/vrfs/100/' && $request->method() === 'GET') {
                return Http::response([
                    'id' => 100,
                    'name' => 'Blue',
                    'rd' => '65000:1',
                    'description' => 'Changed by another operator',
                    'last_updated' => '2026-07-25T10:02:00Z',
                ], 200, ['ETag' => '"new-version"']);
            }
            if ($path === '/api/ipam/vrfs/100/' && $request->method() === 'PATCH') {
                $patches++;

                return Http::response([], 412);
            }

            return Http::response(['results' => [], 'next' => null]);
        });

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('changed after discovery');
        try {
            app(MigrationApplier::class)->apply(
                $project,
                $plan,
                'https://netbox.example.test',
                'nbt_test.runtime-token',
                $user->id,
            );
        } finally {
            self::assertSame(0, $patches);
        }
    }

    public function test_lost_update_response_is_recovered_without_a_second_patch(): void
    {
        [$project, $plan, $user] = $this->plannedUpdateProject();
        $object = [
            'id' => 100,
            'name' => 'Blue',
            'rd' => '65000:1',
            'description' => 'Before',
            'last_updated' => '2026-07-25T10:00:00Z',
        ];
        $patches = 0;
        $loseResponse = true;
        Http::fake(function (Request $request) use (&$object, &$patches, &$loseResponse) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/status/') {
                return Http::response(['netbox-version' => '4.5.3', 'plugins' => []], 200, ['API-Version' => '4.5']);
            }
            if ($path === '/api/ipam/vrfs/100/' && $request->method() === 'GET') {
                return Http::response($object);
            }
            if ($path === '/api/ipam/vrfs/100/' && $request->method() === 'PATCH') {
                $patches++;
                $object = [
                    ...$object,
                    ...array_intersect_key($request->data(), array_flip(['name', 'rd', 'description'])),
                    'last_updated' => '2026-07-25T10:01:00Z',
                ];
                if ($loseResponse) {
                    $loseResponse = false;
                    throw new ConnectionException('Simulated connection loss.');
                }

                return Http::response($object);
            }

            return Http::response(['results' => [], 'next' => null]);
        });

        try {
            app(MigrationApplier::class)->apply(
                $project,
                $plan,
                'https://netbox.example.test',
                'nbt_test.runtime-token',
                $user->id,
            );
            self::fail('The simulated connection loss should fail the first attempt.');
        } catch (ConnectionException|ExternalApiException) {
            self::assertSame('After', $object['description']);
        }

        $execution = app(MigrationApplier::class)->apply(
            $project->fresh(),
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
        );

        self::assertSame(1, $patches);
        self::assertSame('reused', $execution->actionResults->first()->status->value);
        self::assertTrue($execution->actionResults->first()->result['recovered_after_response_loss']);
    }

    public function test_plan_integrity_blocks_apply_after_project_mapping_changes(): void
    {
        [$project, $plan, $user] = $this->plannedProject(1);
        $project->update(['mapping' => ['ignore_types' => ['vrf']]]);
        $objects = [];
        $posts = 0;
        $this->fakeNetBox($objects, $posts);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('project changed');

        app(MigrationApplier::class)->apply(
            $project,
            $plan,
            'https://netbox.example.test',
            'nbt_test.runtime-token',
            $user->id,
        );
    }

    public function test_sensitive_tokens_are_never_flashed_after_validation_failure(): void
    {
        $user = $this->user(UserRole::Operator);
        $project = MigrationProject::query()->create([
            'name' => 'Validation test',
            'source_kind' => 'api',
            'locale' => 'en',
            'status' => MigrationProjectStatus::Draft,
            'created_by' => $user->id,
            'mapping' => MappingPolicy::defaults(),
        ]);

        $this->actingAs($user)->from(route('projects.show', $project))->post(route('projects.discover', $project), [
            'phpipam_url' => 'invalid-url',
            'phpipam_app_id' => 'ipamferry',
            'phpipam_token' => 'private-phpipam-token',
            'netbox_url' => 'invalid-url',
            'netbox_token' => 'private-netbox-token',
        ])->assertRedirect(route('projects.show', $project));

        $oldInput = session()->getOldInput();
        self::assertArrayNotHasKey('phpipam_token', $oldInput);
        self::assertArrayNotHasKey('netbox_token', $oldInput);
        self::assertStringNotContainsString('private-', json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_readers_cannot_create_or_mutate_projects_and_snapshots_are_not_sent_to_the_browser(): void
    {
        $reader = $this->user(UserRole::Reader);
        $project = MigrationProject::query()->create([
            'name' => 'Private inventory',
            'source_kind' => 'api',
            'locale' => 'en',
            'status' => MigrationProjectStatus::Discovered,
            'created_by' => $reader->id,
            'mapping' => MappingPolicy::defaults(),
            'source_snapshot' => ['objects' => ['vrfs' => [['name' => 'Sensitive name']]]],
            'target_snapshot' => ['objects' => []],
        ]);

        $this->actingAs($reader)->post(route('projects.store'), [
            'name' => 'Forbidden',
            'source_kind' => 'api',
        ])->assertForbidden();
        $this->actingAs($reader)->post(route('projects.plan', $project))->assertForbidden();
        $this->actingAs($reader)->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/Show')
                ->where('project.has_source_snapshot', true)
                ->missing('project.source_snapshot')
                ->missing('project.target_snapshot'));
    }

    public function test_plan_with_preserved_data_requires_an_explicit_second_acknowledgement(): void
    {
        [$project, , $user] = $this->plannedProject(1);
        $project->plans()->delete();
        $source = $project->source_snapshot;
        $source['preserved'] = ['nameservers' => [['source_id' => 'private-source-id']]];
        $project->update(['source_snapshot' => $source, 'status' => MigrationProjectStatus::Discovered]);
        (new BuildMigrationPlan($project->id))->handle(
            app(MigrationPlanner::class),
            app(MigrationAudit::class),
            app(MigrationOperationLock::class),
        );
        $plan = $project->plans()->latest('id')->firstOrFail();

        $this->actingAs($user)
            ->post(route('projects.plans.approve', [$project, $plan]), ['confirm' => true])
            ->assertSessionHasErrors('preservation_acknowledged');
        self::assertNull($plan->fresh()->approved_at);

        $this->actingAs($user)
            ->post(route('projects.plans.approve', [$project, $plan]), [
                'confirm' => true,
                'preservation_acknowledged' => true,
            ])
            ->assertRedirect();
        self::assertNotNull($plan->fresh()->approved_at);
        $event = DB::table('migration_events')
            ->where('plan_id', $plan->id)
            ->where('kind', 'plan.preservation_acknowledged')
            ->first();
        self::assertNotNull($event);
        self::assertStringNotContainsString('private-source-id', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_plan_without_preserved_data_needs_only_the_normal_approval_confirmation(): void
    {
        [$project, $plan, $user] = $this->plannedProject(1);
        $plan->update(['approved_at' => null, 'approved_by' => null]);

        $this->actingAs($user)
            ->post(route('projects.plans.approve', [$project, $plan]), ['confirm' => true])
            ->assertRedirect();
        self::assertNotNull($plan->fresh()->approved_at);
    }

    private function plannedProject(int $vrfCount): array
    {
        $user = $this->user(UserRole::Owner);
        $capabilities = ['version' => '4.5.3', 'api_version' => '4.5', 'plugins' => []];
        $targetInstance = (new EndpointPolicy)->instance('netbox', 'https://netbox.example.test', $capabilities);
        $sourceInstance = [
            'kind' => 'phpipam',
            'url' => 'https://phpipam.example.test',
            'version' => '1.8.1',
            'api_version' => '1.8',
            'fingerprint' => str_repeat('a', 64),
        ];
        $vrfs = [];
        for ($id = 1; $id <= $vrfCount; $id++) {
            $vrfs[] = [
                'source_type' => 'vrf',
                'source_id' => (string) $id,
                'source_hash' => hash('sha256', "vrf-{$id}"),
                'name' => "VRF {$id}",
                'rd' => "65000:{$id}",
                'description' => '',
                'legacy' => [],
            ];
        }
        $project = MigrationProject::query()->create([
            'name' => 'Lifecycle test',
            'source_kind' => 'api',
            'locale' => 'en',
            'status' => MigrationProjectStatus::Discovered,
            'created_by' => $user->id,
            'mapping' => MappingPolicy::defaults(),
            'source_instance' => $sourceInstance,
            'target_instance' => $targetInstance,
            'source_snapshot' => [
                'schema_version' => 1,
                'instance' => $sourceInstance,
                'objects' => [
                    'vrfs' => $vrfs,
                    'vlan_groups' => [],
                    'vlans' => [],
                    'prefixes' => [],
                    'ip_addresses' => [],
                ],
                'preserved' => [],
                'custom_fields' => [],
                'warnings' => [],
            ],
            'target_snapshot' => [
                'schema_version' => 1,
                'instance' => $targetInstance,
                'objects' => [
                    'vrfs' => [],
                    'vlan_groups' => [],
                    'vlans' => [],
                    'prefixes' => [],
                    'ip_addresses' => [],
                    'custom_fields' => [],
                ],
            ],
        ]);
        (new BuildMigrationPlan($project->id))->handle(
            app(MigrationPlanner::class),
            app(MigrationAudit::class),
            app(MigrationOperationLock::class),
        );
        $plan = $project->plans()->latest('id')->firstOrFail();
        $plan->approve($user);

        return [$project->fresh(), $plan->fresh(), $user];
    }

    private function plannedUpdateProject(): array
    {
        $user = $this->user(UserRole::Owner);
        $capabilities = ['version' => '4.5.3', 'api_version' => '4.5', 'plugins' => []];
        $targetInstance = (new EndpointPolicy)->instance('netbox', 'https://netbox.example.test', $capabilities);
        $sourceInstance = [
            'kind' => 'phpipam',
            'url' => 'https://phpipam.example.test',
            'version' => '1.8.1',
            'api_version' => '1.8',
            'fingerprint' => str_repeat('a', 64),
        ];
        $project = MigrationProject::query()->create([
            'name' => 'Update lifecycle test',
            'source_kind' => 'api',
            'locale' => 'en',
            'status' => MigrationProjectStatus::Discovered,
            'created_by' => $user->id,
            'mapping' => [
                ...MappingPolicy::defaults(),
                'updates' => ['vrf' => ['description']],
            ],
            'source_instance' => $sourceInstance,
            'target_instance' => $targetInstance,
            'source_snapshot' => [
                'instance' => $sourceInstance,
                'objects' => [
                    'vrfs' => [[
                        'source_type' => 'vrf',
                        'source_id' => '1',
                        'source_hash' => hash('sha256', 'vrf-1'),
                        'name' => 'Blue',
                        'rd' => '65000:1',
                        'description' => 'After',
                        'legacy' => [],
                    ]],
                ],
            ],
            'target_snapshot' => [
                'instance' => $targetInstance,
                'objects' => [
                    'vrfs' => [[
                        'id' => 100,
                        'name' => 'Blue',
                        'rd' => '65000:1',
                        'description' => 'Before',
                        'last_updated' => '2026-07-25T10:00:00Z',
                    ]],
                ],
            ],
        ]);
        (new BuildMigrationPlan($project->id))->handle(
            app(MigrationPlanner::class),
            app(MigrationAudit::class),
            app(MigrationOperationLock::class),
        );
        $plan = $project->plans()->latest('id')->firstOrFail();
        $plan->approve($user);

        return [$project->fresh(), $plan->fresh(), $user];
    }

    private function fakeNetBox(array &$objects, int &$posts, bool &$loseResponse = false): void
    {
        Http::fake(function (Request $request) use (&$objects, &$posts, &$loseResponse) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/status/') {
                return Http::response(['netbox-version' => '4.5.3', 'plugins' => []], 200, ['API-Version' => '4.5']);
            }
            if ($path === '/api/ipam/vrfs/' && $request->method() === 'GET') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $matches = array_values(array_filter(
                    $objects,
                    fn (array $object): bool => ! isset($query['rd']) || $object['rd'] === $query['rd'],
                ));

                return Http::response(['results' => $matches, 'next' => null]);
            }
            if ($path === '/api/ipam/vrfs/' && $request->method() === 'POST') {
                $posts++;
                $data = $request->data();
                $object = [
                    'id' => count($objects) + 100,
                    'name' => $data['name'],
                    'rd' => $data['rd'],
                    'description' => $data['description'] ?? '',
                ];
                $objects[] = $object;
                if ($loseResponse) {
                    $loseResponse = false;
                    throw new ConnectionException('Simulated connection loss.');
                }

                return Http::response($object, 201, ['X-Request-ID' => "request-{$posts}"]);
            }
            if (preg_match('#^/api/ipam/vrfs/(\d+)/$#', $path, $matches)) {
                $object = collect($objects)->firstWhere('id', (int) $matches[1]);

                return $object === null ? Http::response([], 404) : Http::response($object);
            }

            return Http::response(['results' => [], 'next' => null]);
        });
    }

    private function user(UserRole $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role->value).' User',
            'email' => "{$role->value}-".bin2hex(random_bytes(4)).'@example.test',
            'password' => 'LongerPassword1!',
            'role' => $role,
            'locale' => 'en',
            'is_active' => true,
        ]);
    }
}
