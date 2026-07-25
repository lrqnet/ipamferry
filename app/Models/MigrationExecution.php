<?php

namespace App\Models;

use App\Enums\MigrationExecutionStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int $plan_id
 * @property int|null $created_by
 * @property MigrationExecutionStatus $status
 * @property string $target_instance_fingerprint
 * @property array<string, mixed> $summary
 * @property string|null $last_error
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $verified_at
 * @property-read Collection<int, MigrationActionResult> $actionResults
 */
class MigrationExecution extends Model
{
    protected $fillable = [
        'project_id',
        'plan_id',
        'created_by',
        'status',
        'target_instance_fingerprint',
        'summary',
        'last_error',
        'started_at',
        'completed_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MigrationExecutionStatus::class,
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MigrationProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(MigrationProject::class, 'project_id');
    }

    /** @return BelongsTo<MigrationPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MigrationPlan::class, 'plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<MigrationActionResult, $this> */
    public function actionResults(): HasMany
    {
        return $this->hasMany(MigrationActionResult::class, 'execution_id')->orderBy('action_index');
    }
}
