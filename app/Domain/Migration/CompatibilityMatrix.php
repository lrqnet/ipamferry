<?php

namespace App\Domain\Migration;

final class CompatibilityMatrix
{
    public function phpIpam(string $version): bool
    {
        return $this->between($version, '1.5.0', '1.8.999');
    }

    public function netBox(string $version): bool
    {
        return $this->between($version, '4.4.0', '4.6.999');
    }

    private function between(string $version, string $minimum, string $maximum): bool
    {
        $normalized = preg_replace('/[^0-9.].*$/', '', ltrim($version, 'v'));
        if (! is_string($normalized) || preg_match('/^\d+\.\d+(?:\.\d+)?$/', $normalized) !== 1) {
            return false;
        }

        return version_compare($normalized, $minimum, '>=')
            && version_compare($normalized, $maximum, '<=');
    }
}
