<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $project_id
 * @property int|null $requested_by
 * @property string $status
 * @property int $mapping_revision
 * @property string $source_fingerprint
 * @property string $target_fingerprint
 * @property string $mapping_fingerprint
 * @property array<string, mixed>|null $result
 * @property string|null $last_error
 * @property Carbon|null $completed_at
 * @property Carbon $expires_at
 */
class MappingPreview extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'project_id',
        'requested_by',
        'status',
        'mapping_revision',
        'source_fingerprint',
        'target_fingerprint',
        'mapping_fingerprint',
        'result',
        'last_error',
        'completed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MigrationProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(MigrationProject::class, 'project_id');
    }
}
