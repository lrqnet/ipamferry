<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $installed_version
 * @property string $status
 * @property string|null $available_version
 * @property string|null $release_url
 * @property string|null $image_digest
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $requested_at
 * @property Carbon|null $completed_at
 * @property string|null $last_error
 */
class InstallationUpdate extends Model
{
    protected $fillable = ['installed_version', 'status', 'available_version', 'release_url', 'image_digest', 'last_checked_at', 'requested_at', 'completed_at', 'last_error'];

    protected function casts(): array
    {
        return ['last_checked_at' => 'datetime', 'requested_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
