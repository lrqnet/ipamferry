<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $actor_id
 * @property int|null $plan_id
 * @property int|null $execution_id
 * @property string $kind
 * @property string $level
 * @property array<string, mixed> $context
 * @property Carbon $created_at
 */
class MigrationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'actor_id',
        'plan_id',
        'execution_id',
        'kind',
        'level',
        'context',
    ];

    protected function casts(): array
    {
        return ['context' => 'array'];
    }

    /** @return BelongsTo<MigrationProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(MigrationProject::class, 'project_id');
    }
}
