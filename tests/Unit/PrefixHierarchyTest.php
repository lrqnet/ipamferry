<?php

namespace Tests\Unit;

use App\Domain\Migration\PrefixHierarchy;
use Tests\TestCase;

class PrefixHierarchyTest extends TestCase
{
    public function test_it_builds_a_deterministic_ipv4_and_ipv6_tree_per_vrf(): void
    {
        $tree = (new PrefixHierarchy)->fromActions([
            $this->action('10.0.0.0/24', 'blue', 'IPv4 parent'),
            $this->action('10.0.0.128/25', 'blue', 'IPv4 child'),
            $this->action('10.0.0.0/24', 'green', 'Other VRF'),
            $this->action('2001:db8::/32', 'blue'),
            $this->action('2001:db8:1::/48', 'blue', 'IPv6 child'),
        ]);

        self::assertSame(['10.0.0.0/24', '10.0.0.0/24', '2001:db8::/32'], array_column($tree, 'prefix'));
        self::assertSame('10.0.0.128/25', $tree[0]['children'][0]['prefix']);
        self::assertSame([], $tree[1]['children']);
        self::assertSame('2001:db8:1::/48', $tree[2]['children'][0]['prefix']);
        self::assertArrayNotHasKey('vrf', $tree[0]);
        self::assertArrayNotHasKey('source_id', $tree[0]);
    }

    public function test_it_ignores_invalid_and_non_prefix_actions(): void
    {
        $tree = (new PrefixHierarchy)->fromActions([
            ['target_type' => 'ip_address', 'payload' => ['address' => '10.0.0.1/24']],
            ['target_type' => 'prefix', 'payload' => ['prefix' => 'not-a-prefix']],
        ]);

        self::assertSame([], $tree);
    }

    private function action(string $prefix, string $vrf, ?string $description = null): array
    {
        return [
            'target_type' => 'prefix',
            'source_id' => 'source-'.$prefix.'-'.$vrf,
            'natural_key' => ['prefix' => $prefix, 'vrf' => ['name' => $vrf]],
            'payload' => array_filter(['prefix' => $prefix, 'description' => $description]),
        ];
    }
}
