<?php

namespace App\Domain\Migration;

use App\Enums\MigrationActionStatus;
use App\Enums\MigrationExecutionStatus;
use App\Enums\MigrationProjectStatus;
use App\Models\MigrationExecution;
use App\Models\MigrationPlan;
use App\Models\MigrationProject;
use DomainException;
use Throwable;

class MigrationVerifier
{
    public function __construct(
        private readonly PlanIntegrity $integrity,
        private readonly MigrationAudit $audit,
        private readonly MigrationOperationLock $operations,
    ) {}

    public function verify(
        MigrationProject $project,
        MigrationPlan $plan,
        MigrationExecution $execution,
        string $netBoxUrl,
        string $netBoxToken,
        ?int $userId = null,
    ): array {
        if ($execution->project_id !== $project->id || $execution->plan_id !== $plan->id) {
            throw new DomainException('The selected execution does not belong to this project and plan.');
        }
        if (! in_array($execution->status, [
            MigrationExecutionStatus::Applied,
            MigrationExecutionStatus::VerificationFailed,
            MigrationExecutionStatus::Verified,
        ], true)) {
            throw new DomainException('Only an applied migration execution can be verified.');
        }
        if ($execution->status === MigrationExecutionStatus::Verified) {
            return $execution->summary['verification'] ?? ['passed' => true, 'checked' => 0, 'errors' => []];
        }

        $this->integrity->assert($plan, false);
        $lock = $this->operations->acquire($project);
        $verificationStarted = false;

        try {
            $this->operations->assertPlanOperationAllowed($project, $plan);
            $client = new NetBoxClient($netBoxUrl, $netBoxToken);
            $targetInstance = $client->instance();
            if (! hash_equals($execution->target_instance_fingerprint, $targetInstance['fingerprint'])) {
                throw new DomainException('Verification must use the same NetBox instance used for apply.');
            }
            $execution->update(['status' => MigrationExecutionStatus::Verifying, 'last_error' => null]);
            $verificationStarted = true;
            $project->update(['status' => MigrationProjectStatus::Verifying, 'last_error' => null]);
            $errors = [];
            $checked = 0;
            $actions = collect($plan->actions)->keyBy('action_key');

            foreach ($execution->actionResults as $result) {
                if ($result->status === MigrationActionStatus::Skipped) {
                    continue;
                }
                if (! $result->status->isComplete() || $result->target_id === null) {
                    $errors[] = ['action_key' => $result->action_key, 'reason' => 'incomplete_action'];

                    continue;
                }

                $action = $actions[$result->action_key] ?? null;
                if ($action === null) {
                    $errors[] = ['action_key' => $result->action_key, 'reason' => 'action_missing_from_plan'];

                    continue;
                }

                try {
                    $detail = $client->detail($action['target_type'], (int) $result->target_id);
                    $actual = $detail['data'];
                } catch (ExternalApiException $exception) {
                    if ($exception->httpStatus !== 404) {
                        throw $exception;
                    }
                    $errors[] = [
                        'action_key' => $result->action_key,
                        'reason' => 'target_missing',
                    ];

                    continue;
                }

                $naturalKey = $this->resolveReferences($action['natural_key'], $execution);
                if (($action['matched_by'] ?? 'natural_key') !== 'object_link'
                    && ! $client->matchesNaturalKey($action['target_type'], $actual, $naturalKey)
                ) {
                    $errors[] = [
                        'action_key' => $result->action_key,
                        'reason' => 'target_identity_changed',
                    ];

                    continue;
                }

                if (in_array($result->status, [MigrationActionStatus::Created, MigrationActionStatus::Updated], true)) {
                    $expected = $this->resolveReferences($action['payload'], $execution);
                    $differences = $this->differences($expected, $actual);
                    if ($differences !== []) {
                        $errors[] = [
                            'action_key' => $result->action_key,
                            'reason' => 'payload_mismatch',
                            'fields' => array_keys($differences),
                        ];
                    }
                }
                $checked++;
            }

            $verification = ['passed' => $errors === [], 'checked' => $checked, 'errors' => $errors];
            $summary = [...($execution->summary ?? []), 'verification' => $verification];
            if ($errors !== []) {
                $execution->update([
                    'status' => MigrationExecutionStatus::VerificationFailed,
                    'summary' => $summary,
                    'last_error' => 'Verification detected differences in the NetBox target.',
                ]);
                $verificationStarted = false;
                $project->update([
                    'status' => MigrationProjectStatus::Failed,
                    'last_error' => 'Verification detected differences in the NetBox target.',
                ]);
                $this->audit->record(
                    $project,
                    'verification.failed',
                    ['checked' => $checked, 'differences' => count($errors)],
                    $userId,
                    $plan->id,
                    $execution->id,
                    'warning',
                );

                return $verification;
            }

            $execution->update([
                'status' => MigrationExecutionStatus::Verified,
                'summary' => $summary,
                'verified_at' => now(),
            ]);
            $verificationStarted = false;
            $plan->forceFill(['verified_at' => now()])->save();
            $project->update(['status' => MigrationProjectStatus::Verified]);
            $this->audit->record(
                $project,
                'verification.completed',
                ['checked' => $checked],
                $userId,
                $plan->id,
                $execution->id,
            );

            return $verification;
        } catch (Throwable $exception) {
            if ($verificationStarted) {
                $message = mb_substr($exception->getMessage(), 0, 2000);
                $execution->update([
                    'status' => MigrationExecutionStatus::VerificationFailed,
                    'last_error' => $message,
                ]);
                $project->update([
                    'status' => MigrationProjectStatus::Failed,
                    'last_error' => $message,
                ]);
                $this->audit->record(
                    $project,
                    'verification.failed',
                    ['error_type' => $exception::class],
                    $userId,
                    $plan->id,
                    $execution->id,
                    'error',
                );
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function resolveReferences(mixed $value, MigrationExecution $execution): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (isset($value['$ref'])) {
            $result = $execution->actionResults->firstWhere('action_key', $value['$ref']);
            if ($result === null || $result->target_id === null) {
                throw new DomainException("Dependency {$value['$ref']} is unresolved.");
            }

            return (int) $result->target_id;
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = $this->resolveReferences($item, $execution);
        }

        return $resolved;
    }

    private function differences(array $expected, array $actual): array
    {
        $differences = [];
        foreach ($expected as $field => $value) {
            $actualValue = $actual[$field] ?? null;
            if (is_array($actualValue) && array_key_exists('value', $actualValue)) {
                $actualValue = $actualValue['value'];
            } elseif (is_array($actualValue) && array_key_exists('id', $actualValue)) {
                $actualValue = $actualValue['id'];
            }

            if ($field === 'custom_fields' && is_array($value)) {
                foreach ($value as $customField => $customValue) {
                    $current = $actual['custom_fields'][$customField] ?? null;
                    if (is_array($current) && array_key_exists('value', $current)) {
                        $current = $current['value'];
                    }
                    if ($current !== $customValue) {
                        $differences["custom_fields.{$customField}"] = ['expected' => $customValue, 'actual' => $current];
                    }
                }
            } elseif ($actualValue !== $value) {
                $differences[$field] = ['expected' => $value, 'actual' => $actualValue];
            }
        }

        return $differences;
    }
}
