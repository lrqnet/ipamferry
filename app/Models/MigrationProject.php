<?php

namespace App\Models;

use App\Enums\MigrationProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $source_kind
 * @property string $locale
 * @property MigrationProjectStatus $status
 * @property int $created_by
 * @property array<string, mixed>|null $mapping
 * @property array<string, mixed>|null $source_snapshot
 * @property array<string, mixed>|null $target_snapshot
 * @property array<string, mixed>|null $source_instance
 * @property array<string, mixed>|null $target_instance
 * @property array<string, mixed>|null $discovery_manifest
 * @property int $snapshot_schema_version
 * @property int $mapping_revision
 * @property array<string, mixed>|null $mapping_catalog
 * @property string|null $last_error
 */
class MigrationProject extends Model
{
    protected $fillable = [
        'name',
        'source_kind',
        'locale',
        'status',
        'created_by',
        'mapping',
        'source_snapshot',
        'target_snapshot',
        'source_instance',
        'target_instance',
        'discovery_manifest',
        'snapshot_schema_version',
        'mapping_revision',
        'mapping_catalog',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => MigrationProjectStatus::class,
            'mapping' => 'array',
            'source_snapshot' => 'array',
            'target_snapshot' => 'array',
            'source_instance' => 'array',
            'target_instance' => 'array',
            'discovery_manifest' => 'array',
            'mapping_catalog' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<MigrationPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(MigrationPlan::class, 'project_id');
    }

    /** @return HasMany<MigrationExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(MigrationExecution::class, 'project_id');
    }

    /** @return HasMany<MigrationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(MigrationEvent::class, 'project_id');
    }

    /** @return HasMany<MappingPreview, $this> */
    public function mappingPreviews(): HasMany
    {
        return $this->hasMany(MappingPreview::class, 'project_id');
    }
}
