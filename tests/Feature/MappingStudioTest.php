<?php

namespace Tests\Feature;

use App\Domain\Migration\MappingPolicy;
use App\Enums\MigrationProjectStatus;
use App\Enums\UserRole;
use App\Jobs\BuildMappingPreview;
use App\Models\MigrationProject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MappingStudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapping_studio_exposes_only_the_sanitized_catalog(): void
    {
        [$user, $project] = $this->project();
        $project->update([
            'source_snapshot' => [
                'objects' => ['devices' => [[
                    'source_id' => '1',
                    'name' => 'edge-01',
                    'legacy' => ['api_token' => 'must-not-leak'],
                ]]],
            ],
            'target_snapshot' => ['objects' => ['devices' => []], 'write_schema' => []],
        ]);

        $this->actingAs($user)
            ->get(route('projects.mapping.show', $project))
            ->assertOk()
            ->assertDontSee('must-not-leak')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projects/MappingStudio')
                ->where('project.can_edit', true)
                ->has('catalog.source.device.fields.name')
                ->missing('catalog.source.device.fields.legacy.api_token'));
    }

    public function test_mapping_save_uses_optimistic_revision_and_returns_json_pointer_errors(): void
    {
        [$user, $project] = $this->project();
        $mapping = MappingPolicy::v2Defaults();

        $this->actingAs($user)
            ->putJson(route('projects.mapping.update', $project), [
                'mapping' => $mapping,
                'locale' => 'pt_BR',
                'revision' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('revision', 2);

        $this->putJson(route('projects.mapping.update', $project), [
            'mapping' => $mapping,
            'locale' => 'pt_BR',
            'revision' => 1,
        ])->assertConflict()->assertJsonPath('code', 'mapping.revision_conflict');

        $invalid = $mapping;
        $invalid['field_rules'] = [['id' => 'invalid id', 'source_type' => 'prefix', 'action' => 'copy']];
        $this->putJson(route('projects.mapping.update', $project), [
            'mapping' => $invalid,
            'locale' => 'en',
            'revision' => 2,
        ])->assertUnprocessable()->assertJsonPath('code', 'mapping.validation_failed')
            ->assertJsonPath('errors.0.code', 'mapping.invalid_rule_id');
    }

    public function test_reader_can_inspect_but_cannot_edit_or_queue_preview(): void
    {
        [$user, $project] = $this->project(UserRole::Reader);

        $this->actingAs($user)
            ->get(route('projects.mapping.show', $project))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('project.can_edit', false));
        $this->putJson(route('projects.mapping.update', $project), [
            'mapping' => MappingPolicy::v2Defaults(),
            'locale' => 'en',
            'revision' => 1,
        ])->assertForbidden();
        $this->post(route('projects.mapping.preview', $project))->assertForbidden();
    }

    public function test_artifact_language_is_managed_outside_mapping_studio(): void
    {
        [$user, $project] = $this->project();
        $project->update([
            'source_snapshot' => ['objects' => []],
            'status' => MigrationProjectStatus::Planned,
        ]);

        $this->actingAs($user)
            ->put(route('projects.artifact-locale.update', $project), ['locale' => 'en'])
            ->assertRedirect();
        self::assertSame(MigrationProjectStatus::Planned, $project->refresh()->status);

        $this->actingAs($user)
            ->put(route('projects.artifact-locale.update', $project), ['locale' => 'pt_BR'])
            ->assertRedirect();

        $project->refresh();
        self::assertSame('pt_BR', $project->locale);
        self::assertSame(MigrationProjectStatus::Discovered, $project->status);

        $this->put(route('projects.artifact-locale.update', $project), ['locale' => 'invalid'])
            ->assertSessionHasErrors('locale');

        $user->update(['role' => UserRole::Reader]);
        $this->put(route('projects.artifact-locale.update', $project), ['locale' => 'es'])
            ->assertForbidden();
    }

    public function test_preview_is_queued_and_bound_to_the_current_revision(): void
    {
        Queue::fake();
        [$user, $project] = $this->project();
        $project->update([
            'source_snapshot' => ['objects' => []],
            'target_snapshot' => ['objects' => [], 'write_schema' => []],
            'status' => MigrationProjectStatus::Discovered,
        ]);

        $this->actingAs($user)
            ->post(route('projects.mapping.preview', $project))
            ->assertRedirect();

        $preview = $project->mappingPreviews()->firstOrFail();
        self::assertSame('queued', $preview->status);
        self::assertSame($project->refresh()->mapping_revision, $preview->mapping_revision);
        Queue::assertPushed(BuildMappingPreview::class, fn (BuildMappingPreview $job): bool => $job->previewId === $preview->id);
    }

    private function project(UserRole $role = UserRole::Owner): array
    {
        $user = User::query()->create([
            'name' => 'Mapping Operator',
            'email' => strtolower($role->value).'@example.test',
            'password' => 'Safe-test-password-123!',
            'role' => $role,
            'locale' => 'en',
            'is_active' => true,
        ]);
        $project = MigrationProject::query()->create([
            'name' => 'Mapping test',
            'source_kind' => 'dump',
            'locale' => 'en',
            'status' => MigrationProjectStatus::Draft,
            'created_by' => $user->id,
            'mapping' => MappingPolicy::v2Defaults(),
        ]);

        return [$user, $project];
    }
}
