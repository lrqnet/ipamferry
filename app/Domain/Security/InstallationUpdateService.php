<?php

namespace App\Domain\Security;

use App\Enums\MigrationProjectStatus;
use App\Models\InstallationUpdate;
use App\Models\MigrationProject;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class InstallationUpdateService
{
    /** @return array<string, mixed> */
    public function publicStatus(): array
    {
        $update = $this->state();
        $this->syncUpdaterResult($update);
        $update->refresh();

        return [
            'installedVersion' => $update->installed_version,
            'status' => $update->status,
            'availableVersion' => $update->available_version,
            'releaseUrl' => $update->release_url,
            'lastCheckedAt' => $update->last_checked_at?->toIso8601String(),
            'requestedAt' => $update->requested_at?->toIso8601String(),
            'completedAt' => $update->completed_at?->toIso8601String(),
            'error' => $update->last_error,
            'enabled' => (bool) config('ipamferry.updates_enabled'),
        ];
    }

    public function checkIfDue(): void
    {
        $update = $this->state();
        if ($update->last_checked_at?->gt(now()->subDay())) {
            return;
        }
        $this->check();
    }

    public function check(): InstallationUpdate
    {
        if (! (bool) config('ipamferry.updates_enabled')) {
            throw new DomainException('Installation updates are disabled by the operator.');
        }
        $lock = Cache::lock('ipamferry:installation-update-check', 60);
        if (! $lock->get()) {
            throw new DomainException('An update check is already running.');
        }

        try {
            $update = $this->state();
            if (in_array($update->status, ['requested', 'updating'], true)) {
                throw new DomainException('An installation update is already running.');
            }
            $update->update(['status' => 'checking', 'last_error' => null]);
            $release = Http::acceptJson()->withUserAgent('IpamFerry/'.$update->installed_version)->timeout(10)->get((string) config('ipamferry.release_api_url'))->throw()->json();
            if (! is_array($release) || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                throw new RuntimeException('The release endpoint did not return a stable release.');
            }
            $version = $this->version((string) ($release['tag_name'] ?? ''));
            $assets = collect($release['assets'] ?? [])->keyBy('name');
            $compose = $assets->get('compose.yaml');
            $checksum = $assets->get('compose.sha256');
            if (! is_array($compose) || ! is_array($checksum) || ! is_string($compose['browser_download_url'] ?? null) || ! is_string($checksum['browser_download_url'] ?? null)) {
                throw new RuntimeException('The stable release does not include its compose artifacts.');
            }
            $installed = $this->releaseVersion($update->installed_version);
            $available = $installed !== null && version_compare($version, $installed, '>');
            $update->update([
                'status' => $available ? 'available' : 'idle', 'available_version' => $available ? $version : null,
                'release_url' => $available ? (string) ($release['html_url'] ?? null) : null, 'image_digest' => null,
                'last_checked_at' => now(), 'last_error' => null,
            ]);

            return $update->refresh();
        } catch (DomainException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $update = $this->state();
            $update->update(['status' => 'failed', 'last_checked_at' => now(), 'last_error' => 'Unable to check the official stable release. Try again later.']);
            report($exception);

            return $update;
        } finally {
            $lock->release();
        }
    }

    public function request(): InstallationUpdate
    {
        if (! (bool) config('ipamferry.updates_enabled')) {
            throw new DomainException('Installation updates are disabled by the operator.');
        }
        if (MigrationProject::query()->whereIn('status', [MigrationProjectStatus::Discovering, MigrationProjectStatus::Planning, MigrationProjectStatus::Applying, MigrationProjectStatus::Verifying])->exists()) {
            throw new DomainException('Wait for active migration operations before updating IpamFerry.');
        }
        $lock = Cache::lock('ipamferry:installation-update-request', 300);
        if (! $lock->get()) {
            throw new DomainException('An installation update is already running.');
        }
        try {
            $update = $this->state();
            if ($update->status !== 'available' || ! $update->available_version) {
                throw new DomainException('No newer stable release is available. Check again before updating.');
            }
            $release = Http::acceptJson()->withUserAgent('IpamFerry/'.$update->installed_version)->timeout(10)->get((string) config('ipamferry.release_api_url'))->throw()->json();
            if (! is_array($release) || $this->version((string) ($release['tag_name'] ?? '')) !== $update->available_version || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                throw new RuntimeException('The available release changed. Check again before updating.');
            }
            $assets = collect($release['assets'] ?? [])->keyBy('name');
            $composeUrl = $assets->get('compose.yaml')['browser_download_url'] ?? null;
            $checksumUrl = $assets->get('compose.sha256')['browser_download_url'] ?? null;
            if (! is_string($composeUrl) || ! is_string($checksumUrl)) {
                throw new RuntimeException('The release compose artifacts are missing.');
            }
            $compose = Http::timeout(20)->get($composeUrl)->throw()->body();
            $checksum = Http::timeout(10)->get($checksumUrl)->throw()->body();
            if (! preg_match('/\b([a-f0-9]{64})\b/i', $checksum, $match) || ! hash_equals(strtolower($match[1]), hash('sha256', $compose))) {
                throw new RuntimeException('The release compose checksum could not be verified.');
            }
            if (! preg_match('/docker\.io\/lrqnet\/ipamferry@(sha256:[a-f0-9]{64})/i', $compose, $digest)) {
                throw new RuntimeException('The release compose does not pin the IpamFerry image by digest.');
            }
            Storage::disk('local')->put('private/updates/compose.yaml', $compose);
            Storage::disk('local')->put('private/updates/request.json', json_encode(['version' => $update->available_version, 'sha256' => hash('sha256', $compose)], JSON_THROW_ON_ERROR));
            Storage::disk('local')->delete('private/updates/result.json');
            $update->update(['status' => 'requested', 'image_digest' => strtolower($digest[1]), 'requested_at' => now(), 'completed_at' => null, 'last_error' => null]);

            return $update->refresh();
        } finally {
            $lock->release();
        }
    }

    private function state(): InstallationUpdate
    {
        return InstallationUpdate::query()->firstOrCreate(['id' => 1], ['installed_version' => (string) config('ipamferry.version')]);
    }

    private function syncUpdaterResult(InstallationUpdate $update): void
    {
        $path = 'private/updates/result.json';
        if (! Storage::disk('local')->exists($path)) {
            return;
        }
        $result = json_decode(Storage::disk('local')->get($path), true);
        if (! is_array($result) || ! in_array($result['status'] ?? null, ['completed', 'failed'], true)) {
            return;
        }
        if ($result['status'] === 'completed') {
            $update->update(['status' => 'completed', 'installed_version' => (string) ($result['version'] ?? $update->available_version), 'available_version' => null, 'completed_at' => now(), 'last_error' => null]);
        } else {
            $update->update(['status' => 'failed', 'last_error' => 'The updater stopped before the new application became healthy. Review the updater service logs.']);
        }
        Storage::disk('local')->delete($path);
    }

    private function version(string $value): string
    {
        $version = ltrim(trim($value), 'v');
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new RuntimeException('The release version is invalid.');
        }

        return $version;
    }

    private function releaseVersion(string $value): ?string
    {
        try {
            return $this->version($value);
        } catch (RuntimeException) {
            return null;
        }
    }
}
