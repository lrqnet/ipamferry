<?php

namespace App\Http\Controllers;

use App\Domain\Migration\BundleBuilder;
use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\EndpointPolicy;
use App\Domain\Migration\MappingCatalog;
use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MigrationApplier;
use App\Domain\Migration\MigrationAudit;
use App\Domain\Migration\MigrationOperationLock;
use App\Domain\Migration\MigrationVerifier;
use App\Domain\Migration\NetBoxClient;
use App\Domain\Migration\PhpIpamClient;
use App\Domain\Migration\PlanIntegrity;
use App\Domain\Migration\SandboxConnection;
use App\Domain\Migration\SnapshotFingerprint;
use App\Domain\Migration\SourceNormalizer;
use App\Domain\Migration\SqlDumpParser;
use App\Enums\MigrationProjectStatus;
use App\Enums\SupportedLocale;
use App\Jobs\BuildMigrationPlan;
use App\Models\MigrationExecution;
use App\Models\MigrationPlan;
use App\Models\MigrationProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class MigrationProjectController extends Controller
{
    public function index(): Response
    {
        return inertia('Projects/Index', [
            'projects' => MigrationProject::query()
                ->select(['id', 'name', 'status', 'source_kind', 'locale', 'created_at'])
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request, MigrationAudit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'source_kind' => ['required', 'in:api,dump'],
            'locale' => ['nullable', 'in:'.implode(',', SupportedLocale::values())],
        ]);
        $project = MigrationProject::query()->create([
            ...$data,
            'locale' => $data['locale'] ?? app()->getLocale(),
            'created_by' => $request->user()->id,
            'status' => MigrationProjectStatus::Draft,
            'mapping' => MappingPolicy::v2Defaults(),
        ]);
        $audit->record($project, 'project.created', [
            'source_kind' => $project->source_kind,
            'locale' => $project->locale,
        ], $request->user()->id);

        return to_route('projects.show', $project);
    }

    public function show(MigrationProject $project, SandboxConnection $sandbox): Response
    {
        $latestPlan = $project->plans()->latest('id')->first();
        $latestExecution = $latestPlan?->executions()->latest('id')->first();
        $latestProjectExecution = $project->executions()->latest('id')->first();
        $definitionLocked = $latestProjectExecution !== null
            && $latestProjectExecution->status->value !== 'verified';
        $planIsCurrent = $latestPlan !== null
            && hash_equals((string) $latestPlan->source_fingerprint, SnapshotFingerprint::make($project->source_snapshot ?? []))
            && hash_equals((string) $latestPlan->target_fingerprint, SnapshotFingerprint::make($project->target_snapshot ?? []))
            && hash_equals(
                (string) $latestPlan->mapping_fingerprint,
                CanonicalJson::fingerprint((new MappingPolicy($project->mapping ?? []))->all()),
            )
            && ($latestPlan->schema_version < 2 || $latestPlan->locale === $project->locale);

        return inertia('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status->value,
                'source_kind' => $project->source_kind,
                'locale' => $project->locale,
                'has_source_snapshot' => $project->source_snapshot !== null,
                'has_target_snapshot' => $project->target_snapshot !== null,
                'discovery_manifest' => $project->discovery_manifest,
                'last_error' => $project->last_error,
                'definition_locked' => $definitionLocked,
                'target_is_sandbox' => ($project->target_instance['url'] ?? null) === config('ipamferry.sandbox_url'),
            ],
            'latestPlan' => $latestPlan === null ? null : [
                'id' => $latestPlan->id,
                'fingerprint' => $latestPlan->fingerprint,
                'action_count' => count($latestPlan->actions),
                'actions' => array_slice($latestPlan->actions, 0, 500),
                'conflict_count' => count($latestPlan->conflicts),
                'conflicts' => array_slice($latestPlan->conflicts, 0, 500),
                'warnings' => array_slice($latestPlan->warnings, 0, 500),
                'actions_truncated' => count($latestPlan->actions) > 500,
                'conflicts_truncated' => count($latestPlan->conflicts) > 500,
                'warnings_truncated' => count($latestPlan->warnings) > 500,
                'target_is_sandbox' => ($latestPlan->target_instance['url'] ?? null) === config('ipamferry.sandbox_url'),
                'is_current' => $planIsCurrent,
                'approved_at' => $latestPlan->approved_at?->toIso8601String(),
                'approved_by' => $latestPlan->approved_by,
                'applied_at' => $latestPlan->applied_at?->toIso8601String(),
                'verified_at' => $latestPlan->verified_at?->toIso8601String(),
            ],
            'latestExecution' => $latestExecution === null ? null : [
                'id' => $latestExecution->id,
                'status' => $latestExecution->status->value,
                'summary' => $latestExecution->summary,
                'last_error' => $latestExecution->last_error,
            ],
            'mappingJson' => json_encode($project->mapping ?? MappingPolicy::defaults(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'sandboxAvailable' => $sandbox->available(),
        ]);
    }

    public function updateArtifactLocale(
        Request $request,
        MigrationProject $project,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
    ): RedirectResponse {
        $data = $request->validate([
            'locale' => ['required', 'in:'.implode(',', SupportedLocale::values())],
        ]);

        try {
            $lock = $operations->acquire($project);
            $operations->assertDefinitionMutable($project);
            if ($project->locale === $data['locale']) {
                return back()->with('success', 'Artifact language is already up to date.');
            }
            $project->update([
                'locale' => $data['locale'],
                'status' => $project->source_snapshot === null
                    ? MigrationProjectStatus::Draft
                    : MigrationProjectStatus::Discovered,
            ]);
            $audit->record($project, 'project.artifact_locale.updated', [
                'locale' => $project->locale,
            ], $request->user()->id);
        } catch (Throwable $exception) {
            return back()->withErrors(['migration' => $exception->getMessage()]);
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }

        return back()->with('success', 'Artifact language updated. Generate a new plan to use it.');
    }

    public function discover(
        Request $request,
        MigrationProject $project,
        SourceNormalizer $normalizer,
        MappingCatalog $catalog,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
        SandboxConnection $sandbox,
    ): RedirectResponse {
        abort_unless($project->source_kind === 'api', 422, 'This project expects a SQL dump source.');
        $rules = [
            'phpipam_url' => $this->urlRules(),
            'phpipam_app_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'phpipam_token' => $this->tokenRules(),
            'use_sandbox' => ['sometimes', 'boolean'],
        ];
        if (! $request->boolean('use_sandbox')) {
            $rules['netbox_url'] = $this->urlRules();
            $rules['netbox_token'] = $this->tokenRules();
        }
        $data = $request->validate($rules);
        $targetConnection = $this->targetConnection($request, $data, $sandbox);

        try {
            $lock = $operations->acquire($project);
            $operations->assertDefinitionMutable($project);
        } catch (Throwable $exception) {
            if (isset($lock)) {
                $lock->release();
            }

            return back()->withErrors(['migration' => $exception->getMessage()]);
        }

        try {
            $project->update(['status' => MigrationProjectStatus::Discovering, 'last_error' => null]);
            $audit->record($project, 'discovery.started', ['source_kind' => 'api'], $request->user()->id);
            $sourceInventory = (new PhpIpamClient(
                $data['phpipam_url'],
                $data['phpipam_app_id'],
                $data['phpipam_token'],
            ))->inventory();
            $targetInventory = (new NetBoxClient($targetConnection['url'], $targetConnection['token']))->inventory();
            $this->storeDiscovery($project, $normalizer->normalize($sourceInventory), $targetInventory, $catalog);
            $audit->record(
                $project,
                'discovery.completed',
                $project->discovery_manifest ?? [],
                $request->user()->id,
            );

            return back()->with('success', 'Discovery completed. Tokens were not persisted.');
        } catch (Throwable $exception) {
            return $this->discoveryFailure($project, $exception, $audit, $request->user()->id);
        } finally {
            $lock->release();
        }
    }

    public function discoverDump(
        Request $request,
        MigrationProject $project,
        SqlDumpParser $parser,
        SourceNormalizer $normalizer,
        MappingCatalog $catalog,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
        SandboxConnection $sandbox,
    ): RedirectResponse {
        abort_unless($project->source_kind === 'dump', 422, 'This project expects an API source.');
        $rules = [
            'dump' => ['required', 'file', 'mimetypes:text/plain,application/sql,application/octet-stream', 'max:'.intdiv(config('ipamferry.dump_max_bytes'), 1024)],
            'use_sandbox' => ['sometimes', 'boolean'],
        ];
        if (! $request->boolean('use_sandbox')) {
            $rules['netbox_url'] = $this->urlRules();
            $rules['netbox_token'] = $this->tokenRules();
        }
        $data = $request->validate($rules);
        $targetConnection = $this->targetConnection($request, $data, $sandbox);
        try {
            $lock = $operations->acquire($project);
            $operations->assertDefinitionMutable($project);
        } catch (Throwable $exception) {
            if (isset($lock)) {
                $lock->release();
            }

            return back()->withErrors(['migration' => $exception->getMessage()]);
        }

        try {
            $project->update(['status' => MigrationProjectStatus::Discovering, 'last_error' => null]);
            $audit->record($project, 'discovery.started', ['source_kind' => 'dump'], $request->user()->id);
            $file = $request->file('dump');
            $stored = $file?->storeAs(
                'private/dumps',
                "project-{$project->id}-".now()->format('YmdHisv').'-'.bin2hex(random_bytes(4)).'.sql',
                'local',
            );
            if (! is_string($stored) || $stored === '') {
                throw new \RuntimeException('The uploaded SQL dump could not be stored safely.');
            }
            $parsed = $parser->parseFile(Storage::disk('local')->path($stored));
            $sourceInventory = [
                'schema_version' => 2,
                'instance' => [
                    'kind' => 'phpipam_dump',
                    'filename_hash' => hash_file('sha256', Storage::disk('local')->path($stored)),
                    'fingerprint' => hash_file('sha256', Storage::disk('local')->path($stored)),
                ],
                'objects' => $parser->toInventoryObjects($parsed),
                'custom_fields' => $parser->customFieldDefinitions($parsed),
                'warnings' => $parsed['_warnings'] ?? [],
            ];
            $targetInventory = (new NetBoxClient($targetConnection['url'], $targetConnection['token']))->inventory();
            $this->storeDiscovery($project, $normalizer->normalize($sourceInventory), $targetInventory, $catalog);
            $audit->record(
                $project,
                'discovery.completed',
                $project->discovery_manifest ?? [],
                $request->user()->id,
            );
            Storage::disk('local')->delete($stored);

            return back()->with('success', 'Dump parsed without executing SQL. Tokens were not persisted.');
        } catch (Throwable $exception) {
            return $this->discoveryFailure($project, $exception, $audit, $request->user()->id);
        } finally {
            $lock->release();
        }
    }

    public function updateMapping(
        Request $request,
        MigrationProject $project,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
    ): RedirectResponse {
        $data = $request->validate([
            'mapping_json' => ['required', 'string', 'max:1048576'],
            'locale' => ['required', 'in:'.implode(',', SupportedLocale::values())],
        ]);

        try {
            $mapping = json_decode($data['mapping_json'], true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['mapping_json' => 'Mapping must be valid JSON.']);
        }
        if (! is_array($mapping)) {
            throw ValidationException::withMessages(['mapping_json' => 'Mapping must be a JSON object.']);
        }
        $policy = new MappingPolicy($mapping);
        if ($policy->validate() !== []) {
            throw ValidationException::withMessages(['mapping_json' => $policy->validate()]);
        }

        try {
            $lock = $operations->acquire($project);
            $operations->assertDefinitionMutable($project);
            $project->update([
                'mapping' => $policy->all(),
                'locale' => $data['locale'],
                'status' => $project->source_snapshot === null ? MigrationProjectStatus::Draft : MigrationProjectStatus::Discovered,
            ]);
            $audit->record(
                $project,
                'mapping.updated',
                ['mapping_fingerprint' => CanonicalJson::fingerprint($policy->all()), 'locale' => $project->locale],
                $request->user()->id,
            );
        } catch (Throwable $exception) {
            return back()->withErrors(['migration' => $exception->getMessage()]);
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }

        return back()->with('success', 'Mapping saved. Generate a new plan to apply it.');
    }

    public function plan(
        Request $request,
        MigrationProject $project,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
    ): RedirectResponse {
        abort_if($project->source_snapshot === null || $project->target_snapshot === null, 422, 'Run discovery before planning.');
        abort_if(
            $project->status === MigrationProjectStatus::Verified,
            422,
            'Refresh the NetBox target before generating a sibling plan.',
        );
        try {
            $lock = $operations->acquire($project);
            $operations->assertDefinitionMutable($project);
            $project->update(['status' => MigrationProjectStatus::Planning, 'last_error' => null]);
        } catch (Throwable $exception) {
            return back()->withErrors(['migration' => $exception->getMessage()]);
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }
        BuildMigrationPlan::dispatch($project->id, $request->user()->id);
        $audit->record($project, 'plan.queued', [], $request->user()->id);

        return back()->with('success', 'Plan queued.');
    }

    public function approve(
        Request $request,
        MigrationProject $project,
        MigrationPlan $plan,
        PlanIntegrity $integrity,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
    ): RedirectResponse {
        $this->assertPlan($project, $plan);
        $request->validate(['confirm' => ['accepted']]);
        try {
            $lock = $operations->acquire($project);
            $operations->assertPlanOperationAllowed($project, $plan);
            $integrity->assert($plan);
            $approvedNow = $plan->approve($request->user());
            $project->update(['status' => MigrationProjectStatus::Approved]);
            if ($approvedNow) {
                $audit->record(
                    $project,
                    'plan.approved',
                    ['fingerprint' => $plan->fingerprint],
                    $request->user()->id,
                    $plan->id,
                );
            }
        } catch (Throwable $exception) {
            return back()->withErrors(['migration' => $exception->getMessage()]);
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }

        return back()->with('success', 'This exact migration plan was approved.');
    }

    public function apply(
        Request $request,
        MigrationProject $project,
        MigrationPlan $plan,
        MigrationApplier $applier,
        SandboxConnection $sandbox,
    ): RedirectResponse {
        $this->assertPlan($project, $plan);
        $rules = [
            'use_sandbox' => ['sometimes', 'boolean'],
            'confirm' => ['accepted'],
        ];
        if (! $request->boolean('use_sandbox')) {
            $rules['netbox_url'] = $this->urlRules();
            $rules['netbox_token'] = $this->tokenRules();
        }
        $data = $request->validate($rules);
        $targetConnection = $this->targetConnection($request, $data, $sandbox);

        try {
            $execution = $applier->apply(
                $project,
                $plan,
                $targetConnection['url'],
                $targetConnection['token'],
                $request->user()->id,
                max(1, (int) config('ipamferry.apply_batch_size')),
            );

            $message = $execution->status->value === 'applied'
                ? "Plan applied with execution {$execution->id}. The NetBox token was not persisted."
                : "Apply checkpoint saved: {$execution->summary['completed']} of {$execution->summary['total']} actions completed.";

            return back()->with('success', $message);
        } catch (Throwable $exception) {
            return back()->withErrors(['migration' => $exception->getMessage()]);
        }
    }

    public function verify(
        Request $request,
        MigrationProject $project,
        MigrationPlan $plan,
        MigrationExecution $execution,
        MigrationVerifier $verifier,
        SandboxConnection $sandbox,
    ): RedirectResponse {
        $this->assertPlan($project, $plan);
        abort_unless($execution->plan_id === $plan->id, 404);
        $rules = ['use_sandbox' => ['sometimes', 'boolean']];
        if (! $request->boolean('use_sandbox')) {
            $rules['netbox_url'] = $this->urlRules();
            $rules['netbox_token'] = $this->tokenRules();
        }
        $data = $request->validate($rules);
        $targetConnection = $this->targetConnection($request, $data, $sandbox);

        try {
            $verification = $verifier->verify(
                $project,
                $plan,
                $execution,
                $targetConnection['url'],
                $targetConnection['token'],
                $request->user()->id,
            );

            return $verification['passed']
                ? back()->with('success', "Verification passed for {$verification['checked']} objects.")
                : back()->withErrors(['migration' => 'Verification found differences. Download the bundle for details.']);
        } catch (Throwable $exception) {
            return back()->withErrors(['migration' => $exception->getMessage()]);
        }
    }

    public function bundle(
        Request $request,
        MigrationProject $project,
        MigrationPlan $plan,
        BundleBuilder $builder,
        MigrationAudit $audit,
    ): BinaryFileResponse {
        $this->assertPlan($project, $plan);
        $path = $builder->build($project, $plan);
        $audit->record($project, 'bundle.downloaded', [], $request->user()->id, $plan->id);

        return response()
            ->download($path, "ipamferry-project-{$project->id}-plan-{$plan->id}.zip")
            ->deleteFileAfterSend(true);
    }

    public function refreshTarget(
        Request $request,
        MigrationProject $project,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
        SandboxConnection $sandbox,
        MappingCatalog $catalog,
    ): RedirectResponse {
        abort_if($project->source_snapshot === null, 422, 'Run source discovery before changing the target.');
        $rules = ['use_sandbox' => ['sometimes', 'boolean']];
        if (! $request->boolean('use_sandbox')) {
            $rules['netbox_url'] = $this->urlRules();
            $rules['netbox_token'] = $this->tokenRules();
        }
        $data = $request->validate($rules);
        $targetConnection = $this->targetConnection($request, $data, $sandbox);

        try {
            $lock = $operations->acquire($project);
            $operations->assertDefinitionMutable($project);
            $target = (new NetBoxClient($targetConnection['url'], $targetConnection['token']))->inventory();
            $targetInstance = $target['instance'] ?? [];
            $manifest = $project->discovery_manifest ?? [];
            $project->update([
                'target_snapshot' => $target,
                'mapping_catalog' => $catalog->build($project->source_snapshot ?? [], $target),
                'target_instance' => $targetInstance,
                'discovery_manifest' => [
                    ...$manifest,
                    'target_fingerprint' => SnapshotFingerprint::make($target),
                    'target_counts' => $this->counts($target['objects'] ?? []),
                    'target_discovered_at' => now()->toIso8601String(),
                ],
                'status' => MigrationProjectStatus::Discovered,
                'last_error' => null,
            ]);
            $audit->record(
                $project,
                'target.discovery.completed',
                [
                    'target_fingerprint' => SnapshotFingerprint::make($target),
                    'target_counts' => $this->counts($target['objects'] ?? []),
                ],
                $request->user()->id,
            );

            return back()->with('success', 'NetBox target refreshed. Generate and approve a new target-specific plan.');
        } catch (Throwable $exception) {
            return back()->withErrors(['migration' => $exception->getMessage()]);
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }
    }

    private function storeDiscovery(MigrationProject $project, array $source, array $target, MappingCatalog $catalog): void
    {
        $sourceInstance = $source['instance'] ?? [];
        $targetInstance = $target['instance'] ?? [];
        $project->update([
            'source_snapshot' => $source,
            'target_snapshot' => $target,
            'mapping_catalog' => $catalog->build($source, $target),
            'source_instance' => $sourceInstance,
            'target_instance' => $targetInstance,
            'discovery_manifest' => [
                'schema_version' => 2,
                'source_fingerprint' => SnapshotFingerprint::make($source),
                'target_fingerprint' => SnapshotFingerprint::make($target),
                'source_counts' => $this->counts($source['objects'] ?? []),
                'target_counts' => $this->counts($target['objects'] ?? []),
                'discovered_at' => now()->toIso8601String(),
            ],
            'snapshot_schema_version' => max(
                (int) ($source['schema_version'] ?? 1),
                (int) ($target['schema_version'] ?? 1),
            ),
            'status' => MigrationProjectStatus::Discovered,
            'last_error' => null,
        ]);
    }

    private function discoveryFailure(
        MigrationProject $project,
        Throwable $exception,
        MigrationAudit $audit,
        int $actorId,
    ): RedirectResponse {
        $message = mb_substr($exception->getMessage(), 0, 2000);
        $project->update(['status' => MigrationProjectStatus::Failed, 'last_error' => $message]);
        $audit->record(
            $project,
            'discovery.failed',
            ['error_type' => $exception::class],
            $actorId,
            level: 'error',
        );

        return back()->withErrors(['migration' => $message]);
    }

    private function counts(array $objects): array
    {
        return array_map(fn (mixed $items): int => is_array($items) ? count($items) : 0, $objects);
    }

    private function urlRules(): array
    {
        return [
            'required',
            'string',
            'max:2048',
            function (string $attribute, mixed $value, \Closure $fail): void {
                try {
                    (new EndpointPolicy)->canonicalize((string) $value);
                } catch (Throwable $exception) {
                    $fail($exception->getMessage());
                }
            },
        ];
    }

    private function tokenRules(): array
    {
        return ['required', 'string', 'min:8', 'max:4096', 'regex:/^\S+$/'];
    }

    private function assertPlan(MigrationProject $project, MigrationPlan $plan): void
    {
        abort_unless($plan->project_id === $project->id, 404);
    }

    private function targetConnection(Request $request, array $data, SandboxConnection $sandbox): array
    {
        return $request->boolean('use_sandbox')
            ? $sandbox->credentials()
            : ['url' => $data['netbox_url'], 'token' => $data['netbox_token']];
    }
}
