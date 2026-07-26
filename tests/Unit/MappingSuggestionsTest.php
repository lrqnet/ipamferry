<?php

namespace Tests\Unit;

use App\Domain\Migration\MappingPolicy;
use App\Domain\Migration\MappingSuggestions;
use PHPUnit\Framework\TestCase;

class MappingSuggestionsTest extends TestCase
{
    public function test_suggestions_are_deterministic_and_use_type_name_slug_and_natural_key_signals(): void
    {
        $catalog = [
            'source' => [
                'customer' => [
                    'fields' => [
                        'name' => ['type' => 'text'],
                        'description' => ['type' => 'text'],
                    ],
                ],
            ],
            'target' => [
                'tenants' => [
                    'fields' => [
                        'name' => ['type' => 'text'],
                        'slug' => ['type' => 'text'],
                        'description' => ['type' => 'text'],
                    ],
                ],
            ],
            'natural_keys' => ['tenant' => ['slug']],
        ];
        $builder = new MappingSuggestions;
        $first = $builder->make($catalog, MappingPolicy::v2Defaults());
        $second = $builder->make($catalog, MappingPolicy::v2Defaults());
        $object = collect($first)->firstWhere('kind', 'object');
        $slug = collect($first)->first(
            fn (array $suggestion): bool => $suggestion['kind'] === 'field'
                && ($suggestion['rule']['target'] ?? null) === 'slug',
        );

        self::assertSame($first, $second);
        self::assertSame(['type', 'name', 'natural_key'], $object['signals']);
        self::assertSame('matching_type_and_natural_key', $object['reason']);
        self::assertSame('normalize', $slug['rule']['action']);
        self::assertSame('slug', $slug['rule']['mode']);
        self::assertMatchesRegularExpression('/^suggestion-[a-f0-9]{20}$/', $slug['id']);
    }
}
