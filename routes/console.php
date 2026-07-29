<?php

use App\Models\MappingPreview;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ipamferry:prune-dumps')->daily();
Schedule::call(fn () => MappingPreview::query()->where('expires_at', '<=', now())->delete())
    ->name('ipamferry:prune-mapping-previews')
    ->hourly();
