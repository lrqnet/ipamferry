<?php

namespace App\Domain\Migration\Planners;

use App\Domain\Migration\MappingPolicy;

final class RelationsPlanner
{
    public function relations(array $objects, MappingPolicy $policy): array
    {
        $relations = [];
        $primary = $policy->relationSettings('primary_ip');
        if ($primary !== null) {
            $addressesByRawIp = [];
            foreach ($objects['ip_addresses'] ?? [] as $address) {
                $host = explode('/', (string) ($address['address'] ?? ''))[0];
                if ($host !== '') {
                    $addressesByRawIp[$host][] = $address;
                }
            }
            foreach ($objects['devices'] ?? [] as $device) {
                $raw = (string) ($device['primary_ip_source'] ?? '');
                $matches = $addressesByRawIp[$raw] ?? [];
                $assigned = array_values(array_filter(
                    $matches,
                    fn (array $address): bool => ($address['device_source_id'] ?? null) === ($device['source_id'] ?? null)
                        && ($address['interface_source_id'] ?? null) !== null,
                ));
                if (count($assigned) === 1) {
                    $relations[] = [
                        'relation' => 'primary_ip',
                        'source' => $device,
                        'subject_type' => 'device',
                        'subject_source_id' => $device['source_id'],
                        'payload' => [
                            str_contains((string) $assigned[0]['address'], ':') ? 'primary_ip6' : 'primary_ip4' => PlannerIntent::reference('ip_address', $assigned[0]['source_id']),
                        ],
                    ];
                } elseif ($raw !== '') {
                    $relations[] = PlannerIntent::issue('primary_ip_ambiguous', 'device', $device['source_id'] ?? null);
                }
            }
        }

        $nat = $policy->relationSettings('nat_1to1');
        foreach ($objects['nat_relations'] ?? [] as $relation) {
            if (($relation['has_ports'] ?? false) === true) {
                $relations[] = PlannerIntent::issue('pat_preserved', 'nat', $relation['source_id'] ?? null);

                continue;
            }
            $confirmed = $nat !== null
                && ($nat['confirmed'] ?? false) === true
                && in_array((string) ($relation['source_id'] ?? ''), array_map('strval', $nat['relation_ids'] ?? []), true);
            if (! $confirmed) {
                $relations[] = PlannerIntent::issue('nat_confirmation_required', 'nat', $relation['source_id'] ?? null);

                continue;
            }
            if (($relation['inside_ip_source_id'] ?? null) === null || ($relation['outside_ip_source_id'] ?? null) === null) {
                $relations[] = PlannerIntent::issue('nat_ip_pair_required', 'nat', $relation['source_id'] ?? null);

                continue;
            }
            $relations[] = [
                'relation' => 'nat_1to1',
                'source' => $relation,
                'subject_type' => 'ip_address',
                'subject_source_id' => $relation['outside_ip_source_id'],
                'payload' => [
                    'nat_inside' => PlannerIntent::reference('ip_address', $relation['inside_ip_source_id']),
                ],
            ];
        }

        return $relations;
    }
}
