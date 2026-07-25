<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $source_instance_fingerprint
 * @property string $source_type
 * @property string $source_id
 * @property string $target_instance_fingerprint
 * @property string $target_type
 * @property int $target_id
 * @property string $natural_key
 * @property array<string, mixed> $target_snapshot
 */
class MigrationObjectLink extends Model
{
    protected $fillable = [
        'project_id',
        'source_instance_fingerprint',
        'source_type',
        'source_id',
        'target_instance_fingerprint',
        'target_type',
        'target_id',
        'natural_key',
        'target_snapshot',
    ];

    protected function casts(): array
    {
        return ['target_snapshot' => 'array'];
    }

    /** @return BelongsTo<MigrationProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(MigrationProject::class, 'project_id');
    }
}
