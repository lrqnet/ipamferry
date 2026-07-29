<?php

namespace App\Domain\Migration;

final class PrefixHierarchy
{
    public function fromActions(array $actions): array
    {
        $nodes = [];
        foreach ($actions as $action) {
            if (! is_array($action) || ($action['target_type'] ?? null) !== 'prefix') {
                continue;
            }
            $prefix = $action['payload']['prefix'] ?? $action['natural_key']['prefix'] ?? null;
            if (! is_string($prefix) || ! $this->parsed($prefix)) {
                continue;
            }
            $key = CanonicalJson::fingerprint([$prefix, $action['natural_key']['vrf'] ?? null]);
            $nodes[$key] = [
                'prefix' => $prefix,
                'vrf' => $action['natural_key']['vrf'] ?? null,
                'description' => $this->description($action['payload'] ?? []),
                'children' => [],
            ];
        }

        $parents = [];
        foreach ($nodes as $key => $node) {
            $parent = $this->parent($key, $nodes);
            if ($parent !== null) {
                $parents[$key] = $parent;
            }
        }
        foreach ($parents as $prefix => $parent) {
            $nodes[$parent]['children'][] = $prefix;
        }

        $roots = [];
        foreach ($nodes as $prefix => $node) {
            if (! isset($parents[$prefix])) {
                $roots[] = $prefix;
            }
        }

        return $this->materialize($roots, $nodes);
    }

    private function materialize(array $prefixes, array $nodes): array
    {
        usort($prefixes, fn (string $left, string $right): int => $this->compare($nodes[$left], $nodes[$right]));

        return array_map(function (string $prefix) use ($nodes): array {
            $node = $nodes[$prefix];
            $node['children'] = $this->materialize($node['children'], $nodes);
            unset($node['vrf']);

            return $node;
        }, $prefixes);
    }

    private function parent(string $key, array $nodes): ?string
    {
        $prefix = $nodes[$key]['prefix'];
        $vrf = CanonicalJson::encode($nodes[$key]['vrf'] ?? null);
        $parents = array_keys(array_filter($nodes, fn (array $candidate, string $candidateKey): bool => $candidateKey !== $key
            && CanonicalJson::encode($candidate['vrf'] ?? null) === $vrf
            && $this->contains($candidate['prefix'], $prefix), ARRAY_FILTER_USE_BOTH));
        usort($parents, fn (string $left, string $right): int => $this->mask($nodes[$right]['prefix']) <=> $this->mask($nodes[$left]['prefix']));

        return $parents[0] ?? null;
    }

    private function contains(string $parent, string $child): bool
    {
        [$parentAddress, $parentMask] = $this->parsed($parent);
        [$childAddress, $childMask] = $this->parsed($child);
        if (strlen($parentAddress) !== strlen($childAddress) || $parentMask >= $childMask) {
            return false;
        }
        $wholeBytes = intdiv($parentMask, 8);
        $remainingBits = $parentMask % 8;
        if (substr($parentAddress, 0, $wholeBytes) !== substr($childAddress, 0, $wholeBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($parentAddress[$wholeBytes]) & $mask) === (ord($childAddress[$wholeBytes]) & $mask);
    }

    private function compare(array $left, array $right): int
    {
        [$leftAddress, $leftMask] = $this->parsed($left['prefix']);
        [$rightAddress, $rightMask] = $this->parsed($right['prefix']);

        return strcmp($leftAddress, $rightAddress)
            ?: $leftMask <=> $rightMask
            ?: CanonicalJson::encode($left['vrf'] ?? null) <=> CanonicalJson::encode($right['vrf'] ?? null);
    }

    private function mask(string $prefix): int
    {
        return $this->parsed($prefix)[1];
    }

    private function parsed(string $prefix): ?array
    {
        [$address, $mask] = array_pad(explode('/', $prefix, 2), 2, null);
        if (! is_string($address) || ! is_string($mask) || ! ctype_digit($mask)) {
            return null;
        }
        $packed = inet_pton($address);
        $maximum = $packed === false ? 0 : strlen($packed) * 8;
        if ($packed === false || (int) $mask > $maximum) {
            return null;
        }

        return [$packed, (int) $mask];
    }

    private function description(mixed $payload): ?string
    {
        $description = is_array($payload) ? ($payload['description'] ?? null) : null;

        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }
}
