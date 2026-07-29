<?php

namespace App\Http\Controllers;

use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\MappingCatalog;
use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MappingSuggestions;
use App\Domain\Migration\MigrationAudit;
use App\Domain\Migration\MigrationOperationLock;
use App\Domain\Migration\SnapshotFingerprint;
use App\Enums\MigrationProjectStatus;
use App\Enums\SupportedLocale;
use App\Jobs\BuildMappingPreview;
use App\Models\MappingPreview;
use App\Models\MigrationProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use JsonException;
use Throwable;

class MappingStudioController extends Controller
{
    public function show(
        Request $request,
        MigrationProject $project,
        MappingCatalog $catalogBuilder,
        MappingSuggestions $suggestions,
    ): Response {
        $policy = new MappingPolicy($project->mapping ?? MappingPolicy::defaults());
        $catalog = $project->mapping_catalog ?? [];
        if (! $catalogBuilder->current($catalog, $project->source_snapshot ?? [], $project->target_snapshot ?? [])) {
            $catalog = $catalogBuilder->build($project->source_snapshot ?? [], $project->target_snapshot ?? []);
        }
        $latestPreview = $project->mappingPreviews()
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        return inertia('Projects/MappingStudio', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'locale' => $project->locale,
                'status' => $project->status->value,
                'mapping_revision' => $project->mapping_revision,
                'definition_locked' => $this->definitionLocked($project),
                'can_edit' => $request->user()->role->canOperate(),
            ],
            'mapping' => $policy->all(),
            'mappingJson' => json_encode($policy->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'upgradeAvailable' => $policy->schemaVersion() === 1,
            'upgradedMapping' => $policy->schemaVersion() === 1 ? $policy->upgraded() : null,
            'catalog' => $catalog,
            'suggestions' => $suggestions->make($catalog, $policy->schemaVersion() === 1 ? $policy->upgraded() : $policy->all()),
            'latestPreview' => $this->previewData($latestPreview),
        ]);
    }

    public function update(
        Request $request,
        MigrationProject $project,
        MigrationAudit $audit,
        MigrationOperationLock $operations,
    ): JsonResponse|RedirectResponse {
        $data = $request->validate([
            'mapping' => ['nullable', 'array'],
            'mapping_json' => ['nullable', 'string', 'max:1048576', 'required_without:mapping'],
            'locale' => ['required', 'in:'.implode(',', SupportedLocale::values())],
            'revision' => ['nullable', 'integer', 'min:1', 'required_with:mapping'],
        ]);
        $mapping = $data['mapping'] ?? $this->decodeMapping((string) ($data['mapping_json'] ?? ''));
        $policy = new MappingPolicy($mapping);
        $issues = $policy->validationIssues();
        if ($issues !== []) {
            return response()->json([
                'message' => 'The mapping policy is invalid.',
                'code' => 'mapping.validation_failed',
                'errors' => $issues,
            ], 422);
        }

        try {
            $lock = $operations->acquire($project);
            $operations->assertDefinitionMutable($project);
            $expectedRevision = $data['revision'] ?? $project->mapping_revision;
            $updated = DB::transaction(function () use ($project, $policy, $data, $expectedRevision): bool {
                return MigrationProject::query()
                    ->whereKey($project->id)
                    ->where('mapping_revision', $expectedRevision)
                    ->update([
                        'mapping' => json_encode($policy->all(), JSON_THROW_ON_ERROR),
                        'locale' => $data['locale'],
                        'mapping_revision' => DB::raw('mapping_revision + 1'),
                        'status' => $project->source_snapshot === null
                            ? MigrationProjectStatus::Draft->value
                            : MigrationProjectStatus::Discovered->value,
                        'updated_at' => now(),
                    ]) === 1;
            });
            if (! $updated) {
                return response()->json([
                    'message' => 'The mapping was changed by another operator.',
                    'code' => 'mapping.revision_conflict',
                    'current_revision' => MigrationProject::query()->findOrFail($project->id)->mapping_revision,
                ], 409);
            }
            $project->refresh();
            $audit->record($project, 'mapping.updated', [
                'schema_version' => $policy->schemaVersion(),
                'mapping_revision' => $project->mapping_revision,
                'mapping_fingerprint' => CanonicalJson::fingerprint($policy->all()),
                'locale' => $project->locale,
            ], $request->user()->id);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'mapping.update_blocked',
            ], 423);
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }

        return $request->expectsJson()
            ? response()->json([
                'mapping' => $project->mapping,
                'revision' => $project->mapping_revision,
                'locale' => $project->locale,
            ])
            : back()->with('success', 'Mapping saved. Generate a new plan to apply it.');
    }

    public function preview(
        Request $request,
        MigrationProject $project,
        MigrationOperationLock $operations,
    ): RedirectResponse {
        abort_if($project->source_snapshot === null || $project->target_snapshot === null, 422, 'Run discovery before previewing.');
        try {
            $lock = $operations->acquire($project);
            $operations->assertDefinitionMutable($project);
            $mapping = (new MappingPolicy($project->mapping ?? []))->all();
            $preview = MappingPreview::query()->create([
                'project_id' => $project->id,
                'requested_by' => $request->user()->id,
                'status' => 'queued',
                'mapping_revision' => $project->mapping_revision,
                'source_fingerprint' => SnapshotFingerprint::make($project->source_snapshot ?? []),
                'target_fingerprint' => SnapshotFingerprint::make($project->target_snapshot ?? []),
                'mapping_fingerprint' => CanonicalJson::fingerprint($mapping),
                'expires_at' => now()->addMinutes((int) config('ipamferry.mapping_preview_minutes')),
            ]);
            BuildMappingPreview::dispatch($preview->id);
        } catch (Throwable $exception) {
            return back()->withErrors(['migration' => $exception->getMessage()]);
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }

        return back()->with('success', 'Mapping preview queued.');
    }

    public function previewStatus(MigrationProject $project, MappingPreview $preview): JsonResponse
    {
        abort_unless($preview->project_id === $project->id, 404);
        if ($preview->expires_at->isPast()) {
            abort(410, 'This mapping preview expired.');
        }

        return response()->json($this->previewData($preview));
    }

    private function decodeMapping(string $mapping): array
    {
        try {
            $decoded = json_decode($mapping, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['mapping_json' => 'Mapping must be valid JSON.']);
        }
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['mapping_json' => 'Mapping must be a JSON object.']);
        }

        return $decoded;
    }

    private function previewData(?MappingPreview $preview): ?array
    {
        return $preview === null ? null : [
            'id' => $preview->id,
            'status' => $preview->status,
            'mapping_revision' => $preview->mapping_revision,
            'result' => $preview->result,
            'last_error' => $preview->last_error,
            'expires_at' => $preview->expires_at->toIso8601String(),
        ];
    }

    private function definitionLocked(MigrationProject $project): bool
    {
        $execution = $project->executions()->latest('id')->first();

        return $execution !== null && $execution->status->value !== 'verified';
    }
}
