<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $schema_version
 * @property string $engine_version
 * @property string $locale
 * @property string $fingerprint
 * @property string|null $source_fingerprint
 * @property string|null $target_fingerprint
 * @property string|null $mapping_fingerprint
 * @property string|null $target_instance_fingerprint
 * @property array<string, mixed>|null $target_instance
 * @property array<string, mixed> $mapping_snapshot
 * @property list<array<string, mixed>> $identity_links
 * @property array<string, mixed> $preservation
 * @property list<array<string, mixed>> $actions
 * @property list<array<string, mixed>> $conflicts
 * @property list<string> $warnings
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $applied_at
 * @property Carbon|null $verified_at
 * @property-read MigrationProject $project
 */
class MigrationPlan extends Model
{
    protected $fillable = [
        'project_id',
        'schema_version',
        'engine_version',
        'locale',
        'fingerprint',
        'source_fingerprint',
        'target_fingerprint',
        'mapping_fingerprint',
        'target_instance_fingerprint',
        'target_instance',
        'mapping_snapshot',
        'identity_links',
        'preservation',
        'actions',
        'conflicts',
        'warnings',
        'approved_at',
        'approved_by',
        'applied_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'target_instance' => 'array',
            'mapping_snapshot' => 'array',
            'identity_links' => 'array',
            'preservation' => 'array',
            'actions' => 'array',
            'conflicts' => 'array',
            'warnings' => 'array',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MigrationProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(MigrationProject::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return HasMany<MigrationExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(MigrationExecution::class, 'plan_id');
    }

    public function approve(User $user): bool
    {
        if ($this->approved_at !== null) {
            if ($this->approved_by !== $user->id) {
                throw new \DomainException('This migration plan was already approved by another user.');
            }

            return false;
        }

        if ($this->conflicts !== []) {
            throw new \DomainException('A plan with unresolved conflicts cannot be approved.');
        }

        $this->forceFill(['approved_at' => now(), 'approved_by' => $user->id])->save();

        return true;
    }
}
