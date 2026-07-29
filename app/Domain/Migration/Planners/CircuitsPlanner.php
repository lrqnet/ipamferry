<?php

namespace App\Domain\Migration\Planners;

use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\MappingPolicy;
use Illuminate\Support\Str;

final class CircuitsPlanner
{
    public function intents(array $objects, MappingPolicy $policy): array
    {
        $intents = [];
        foreach ($objects['providers'] ?? [] as $provider) {
            $name = (string) ($provider['name'] ?? '');
            $slug = Str::slug($name) ?: 'phpipam-provider-'.$provider['source_id'];
            $intents[] = PlannerIntent::object('provider', $provider, ['slug' => $slug], [
                'name' => $name,
                'slug' => $slug,
                'description' => $provider['description'] ?? '',
            ]);
        }
        foreach ($objects['circuit_types'] ?? [] as $type) {
            $name = (string) ($type['name'] ?? '');
            $slug = Str::slug($name) ?: 'phpipam-circuit-type-'.$type['source_id'];
            $intents[] = PlannerIntent::object('circuit_type', $type, ['slug' => $slug], [
                'name' => $name,
                'slug' => $slug,
                'description' => $type['description'] ?? '',
            ]);
        }
        foreach ($objects['circuits'] ?? [] as $circuit) {
            $provider = PlannerIntent::reference('provider', $circuit['provider_source_id'] ?? null);
            $type = PlannerIntent::reference('circuit_type', $circuit['type_source_id'] ?? null);
            if ($provider === null || $type === null) {
                $intents[] = PlannerIntent::issue('circuit_prerequisites_required', 'circuit', $circuit['source_id'] ?? null);

                continue;
            }
            $intents[] = PlannerIntent::object('circuit', $circuit, [
                'provider_id' => $provider,
                'cid' => $circuit['cid'] ?? '',
            ], [
                'provider' => $provider,
                'type' => $type,
                'cid' => $circuit['cid'] ?? '',
                'status' => 'active',
                'description' => $circuit['description'] ?? '',
            ]);
        }
        $terminationSettings = $policy->relationSettings('circuit_terminations');
        $locationSettings = $policy->relationSettings('location_classification') ?? [];
        $classifications = is_array($locationSettings['locations'] ?? null) ? $locationSettings['locations'] : [];
        $confirmedCircuits = array_map('strval', $terminationSettings['circuit_ids'] ?? []);
        if ($terminationSettings !== null) {
            foreach ($objects['circuits'] ?? [] as $circuit) {
                $circuitId = (string) ($circuit['source_id'] ?? '');
                if (! in_array($circuitId, $confirmedCircuits, true)) {
                    continue;
                }
                foreach (['A' => 'location_a_source_id', 'Z' => 'location_z_source_id'] as $side => $field) {
                    $locationId = isset($circuit[$field]) ? (string) $circuit[$field] : '';
                    $classification = is_array($classifications[$locationId] ?? null) ? $classifications[$locationId] : null;
                    if ($locationId === '' || $classification === null) {
                        $intents[] = PlannerIntent::issue('circuit_termination_location_required', 'circuit', $circuitId, ['side' => $side]);

                        continue;
                    }
                    $kind = $classification['kind'] ?? null;
                    if (! in_array($kind, ['site', 'location'], true)) {
                        $intents[] = PlannerIntent::issue('circuit_termination_location_required', 'circuit', $circuitId, ['side' => $side]);

                        continue;
                    }
                    $circuitRef = PlannerIntent::reference('circuit', $circuitId);
                    $terminationRef = PlannerIntent::reference($kind, $locationId);
                    $sourceData = [$circuitId, $side, $locationId, $kind];
                    $source = [
                        'source_type' => 'mapping_circuit_termination',
                        'source_id' => "{$circuitId}:{$side}",
                        'source_hash' => CanonicalJson::fingerprint($sourceData),
                        'legacy' => [],
                    ];
                    $intents[] = PlannerIntent::object('circuit_termination', $source, [
                        'circuit_id' => $circuitRef,
                        'term_side' => $side,
                    ], [
                        'circuit' => $circuitRef,
                        'term_side' => $side,
                        'termination_type' => "dcim.{$kind}",
                        'termination_id' => $terminationRef,
                    ]);
                }
            }
        }

        $asnSettings = $policy->relationSettings('asn_defaults');
        if (($objects['asns'] ?? []) !== [] && $asnSettings === null) {
            foreach ($objects['asns'] as $asn) {
                $intents[] = PlannerIntent::issue('asn_rir_required', 'asn', $asn['source_id'] ?? null);
            }

            return $intents;
        }
        if ($asnSettings !== null) {
            $rir = is_array($asnSettings['rir'] ?? null) ? $asnSettings['rir'] : null;
            if ($rir !== null) {
                $slug = Str::slug((string) ($rir['slug'] ?? $rir['name'] ?? '')) ?: 'phpipam-rir';
                $source = [
                    'source_type' => 'mapping_rir',
                    'source_id' => (string) ($rir['id'] ?? $slug),
                    'source_hash' => CanonicalJson::fingerprint($rir),
                    'legacy' => [],
                ];
                $intents[] = PlannerIntent::object('rir', $source, ['slug' => $slug], [
                    'name' => (string) ($rir['name'] ?? $slug),
                    'slug' => $slug,
                    'is_private' => (bool) ($rir['is_private'] ?? true),
                    'description' => (string) ($rir['description'] ?? ''),
                ], ($rir['approved'] ?? false) === true);
                foreach ($objects['asns'] ?? [] as $asn) {
                    $rirRef = PlannerIntent::reference('rir', (string) ($rir['id'] ?? $slug));
                    $intents[] = PlannerIntent::object('asn', $asn, ['asn' => $asn['asn'] ?? null], [
                        'asn' => $asn['asn'] ?? null,
                        'rir' => $rirRef,
                        'description' => $asn['description'] ?? '',
                    ]);
                }
            }
        }

        return $intents;
    }
}
