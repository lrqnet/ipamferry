<?php

namespace App\Models;

use App\Enums\MigrationActionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $execution_id
 * @property int $action_index
 * @property string $action_key
 * @property string $operation
 * @property MigrationActionStatus $status
 * @property string $target_type
 * @property int|null $target_id
 * @property string|null $request_id
 * @property string $payload_hash
 * @property int $attempts
 * @property string|null $error
 * @property array<string, mixed> $result
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class MigrationActionResult extends Model
{
    protected $fillable = [
        'execution_id',
        'action_index',
        'action_key',
        'operation',
        'status',
        'target_type',
        'target_id',
        'request_id',
        'payload_hash',
        'attempts',
        'error',
        'result',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MigrationActionStatus::class,
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MigrationExecution, $this> */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(MigrationExecution::class, 'execution_id');
    }
}
