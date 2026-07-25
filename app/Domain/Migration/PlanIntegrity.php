<?php

namespace App\Domain\Migration;

use App\Models\MigrationPlan;
use DomainException;

class PlanIntegrity
{
    public function assert(MigrationPlan $plan, bool $requireCurrentProject = true): void
    {
        $project = $plan->project;
        $mapping = (new MappingPolicy($project->mapping ?? []))->all();
        $sourceFingerprint = SnapshotFingerprint::make($project->source_snapshot ?? []);
        $targetFingerprint = SnapshotFingerprint::make($project->target_snapshot ?? []);
        $mappingFingerprint = CanonicalJson::fingerprint($mapping);
        $identityLinks = $plan->schema_version >= 2 ? ($plan->identity_links ?? []) : [];

        if ($requireCurrentProject && (
            ! hash_equals((string) $plan->source_fingerprint, $sourceFingerprint)
            || ! hash_equals((string) $plan->target_fingerprint, $targetFingerprint)
            || ! hash_equals((string) $plan->mapping_fingerprint, $mappingFingerprint)
            || ($plan->schema_version >= 2 && $plan->locale !== $project->locale)
        )) {
            throw new DomainException('The project changed after this plan was generated. Generate and approve a new plan.');
        }

        $result = [
            'actions' => $plan->actions,
            'conflicts' => $plan->conflicts,
            'warnings' => $plan->warnings,
            'preservation' => $plan->preservation,
        ];
        $fingerprintInput = [
            'schema_version' => $plan->schema_version,
            'engine_version' => $plan->engine_version,
            'source' => $plan->source_fingerprint,
            'target' => $plan->target_fingerprint,
            'mapping' => $plan->mapping_fingerprint,
            'target_instance' => $plan->target_instance_fingerprint,
        ];
        if ($plan->schema_version >= 2) {
            $fingerprintInput['locale'] = $plan->locale;
            $fingerprintInput['identity_links'] = $identityLinks;
        }
        $fingerprintInput['plan'] = $result;
        $fingerprint = CanonicalJson::fingerprint($fingerprintInput);

        if (! hash_equals((string) $plan->fingerprint, $fingerprint)) {
            throw new DomainException('The migration plan failed its integrity check.');
        }
    }
}
