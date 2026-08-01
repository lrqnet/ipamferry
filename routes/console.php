<?php

use App\Domain\Security\InstallationUpdateService;
use App\Models\MappingPreview;
use Illuminate\Support\Facades\Schedule;

Schedule::command('ipamferry:prune-dumps')->daily();
Schedule::call(fn () => MappingPreview::query()->where('expires_at', '<=', now())->delete())
    ->name('ipamferry:prune-mapping-previews')
    ->hourly();
Schedule::call(fn () => app(InstallationUpdateService::class)->checkIfDue())
    ->name('ipamferry:check-installation-update')
    ->daily();
