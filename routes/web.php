<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallationUpdateController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MappingStudioController;
use App\Http\Controllers\MigrationProjectController;
use App\Http\Controllers\SetupController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => inertia('Welcome', ['installed' => User::query()->exists()]))->name('home');
Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:5,1')->name('setup.store');
Route::put('/locale', LocaleController::class)->name('locale.update');

Route::middleware('auth')->group(function (): void {
    Route::get('/installation-update', [InstallationUpdateController::class, 'status'])->name('installation-update.status');
    Route::post('/installation-update/check', [InstallationUpdateController::class, 'check'])->middleware(['role:owner', 'throttle:installation-update'])->name('installation-update.check');
    Route::post('/installation-update', [InstallationUpdateController::class, 'request'])->middleware(['role:owner', 'throttle:installation-update'])->name('installation-update.request');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/projects', [MigrationProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/{project}', [MigrationProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/mapping', [MappingStudioController::class, 'show'])->name('projects.mapping.show');
    Route::post('/projects', [MigrationProjectController::class, 'store'])->middleware('role:owner,administrator,operator')->name('projects.store');
    Route::post('/projects/{project}/discover', [MigrationProjectController::class, 'discover'])->middleware(['role:owner,administrator,operator', 'throttle:migration-write'])->name('projects.discover');
    Route::post('/projects/{project}/discover-dump', [MigrationProjectController::class, 'discoverDump'])->middleware(['role:owner,administrator,operator', 'throttle:migration-write'])->name('projects.discover-dump');
    Route::put('/projects/{project}/artifact-locale', [MigrationProjectController::class, 'updateArtifactLocale'])->middleware(['role:owner,administrator,operator', 'throttle:migration-write'])->name('projects.artifact-locale.update');
    Route::put('/projects/{project}/mapping', [MappingStudioController::class, 'update'])->middleware(['role:owner,administrator,operator', 'throttle:migration-write'])->name('projects.mapping.update');
    Route::post('/projects/{project}/mapping/preview', [MappingStudioController::class, 'preview'])->middleware(['role:owner,administrator,operator', 'throttle:migration-write'])->name('projects.mapping.preview');
    Route::get('/projects/{project}/mapping/previews/{preview}', [MappingStudioController::class, 'previewStatus'])->name('projects.mapping.previews.show');
    Route::post('/projects/{project}/target', [MigrationProjectController::class, 'refreshTarget'])->middleware(['role:owner,administrator,operator', 'throttle:migration-write'])->name('projects.target.refresh');
    Route::post('/projects/{project}/plan', [MigrationProjectController::class, 'plan'])->middleware(['role:owner,administrator,operator', 'throttle:migration-write'])->name('projects.plan');
    Route::post('/projects/{project}/plans/{plan}/approve', [MigrationProjectController::class, 'approve'])->middleware(['role:owner,administrator', 'throttle:migration-write'])->name('projects.plans.approve');
    Route::post('/projects/{project}/plans/{plan}/apply', [MigrationProjectController::class, 'apply'])->middleware(['role:owner,administrator,operator', 'throttle:migration-apply'])->name('projects.plans.apply');
    Route::post('/projects/{project}/plans/{plan}/executions/{execution}/verify', [MigrationProjectController::class, 'verify'])->middleware(['role:owner,administrator,operator', 'throttle:migration-apply'])->name('projects.executions.verify');
    Route::get('/projects/{project}/plans/{plan}/bundle', [MigrationProjectController::class, 'bundle'])->name('projects.plans.bundle');
});
