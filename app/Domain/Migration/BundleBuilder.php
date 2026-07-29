<?php

namespace App\Domain\Migration;

use App\Models\MigrationPlan;
use App\Models\MigrationProject;
use DomainException;
use Illuminate\Support\Facades\Lang;
use RuntimeException;
use ZipArchive;

class BundleBuilder
{
    public function __construct(
        private readonly PlanIntegrity $integrity,
        private readonly PrefixHierarchy $prefixHierarchy,
    ) {}

    public function build(MigrationProject $project, ?MigrationPlan $plan = null): string
    {
        $plan ??= $project->plans()->latest('id')->firstOrFail();
        if ($plan->project_id !== $project->id) {
            throw new DomainException('The selected plan does not belong to this project.');
        }
        $this->integrity->assert($plan, false);

        $path = storage_path("app/private/bundles/project-{$project->id}-plan-{$plan->id}-".bin2hex(random_bytes(8)).'.zip');
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Unable to create the private bundle directory.');
        }

        $locale = $plan->locale ?: 'en';
        $warnings = array_map(fn (string $warning) => $this->localizedWarning($warning, $locale), $plan->warnings);
        $generatedAt = now()->toIso8601String();
        $execution = $plan->executions()->with('actionResults')->latest('id')->first();
        $preservationSummary = $this->preservationSummary($plan->preservation);
        $prefixHierarchy = $this->prefixHierarchy->fromActions($plan->actions);
        $report = [
            'locale' => $locale,
            'title' => Lang::get('ipamferry.report.title', [], $locale),
            'generated_at' => $generatedAt,
            'summary' => Lang::get('ipamferry.report.summary', [
                'actions' => count($plan->actions),
                'conflicts' => count($plan->conflicts),
                'warnings' => count($warnings),
            ], $locale),
            'plan' => [
                'id' => $plan->id,
                'approved_at' => $plan->approved_at?->toIso8601String(),
                'applied_at' => $plan->applied_at?->toIso8601String(),
                'verified_at' => $plan->verified_at?->toIso8601String(),
            ],
            'execution' => $execution === null ? null : [
                'id' => $execution->id,
                'status' => $execution->status->value,
                'summary' => $execution->summary,
                'started_at' => $execution->started_at?->toIso8601String(),
                'completed_at' => $execution->completed_at?->toIso8601String(),
                'verified_at' => $execution->verified_at?->toIso8601String(),
            ],
            'warnings' => $warnings,
            'preservation' => [
                'title' => Lang::get('ipamferry.report.preservation', [], $locale),
                'categories' => $preservationSummary,
            ],
            'prefix_hierarchy' => [
                'title' => Lang::get('ipamferry.report.prefix_hierarchy', [], $locale),
                'roots' => count($prefixHierarchy),
                'prefixes' => $this->prefixCount($prefixHierarchy),
                'tree' => $prefixHierarchy,
            ],
        ];
        $files = [
            'mapping.json' => $this->json($plan->mapping_snapshot),
            'plan.json' => $this->json([
                'schema_version' => $plan->schema_version,
                'engine_version' => $plan->engine_version,
                'fingerprint' => $plan->fingerprint,
                'target_instance' => $plan->target_instance,
                'identity_links' => $plan->identity_links ?? [],
                'actions' => $plan->actions,
                'conflicts' => $plan->conflicts,
                'warnings' => $plan->warnings,
            ]),
            'report.json' => $this->json($report),
            'preservation-report.json' => $this->json([
                'locale' => $locale,
                'title' => Lang::get('ipamferry.report.preservation', [], $locale),
                'category_label' => Lang::get('ipamferry.report.category', [], $locale),
                'objects_label' => Lang::get('ipamferry.report.objects', [], $locale),
                'summary' => $preservationSummary,
                'data' => $plan->preservation,
            ]),
            'report.html' => $this->html($report, $locale),
            'coverage.json' => $this->json($this->coverage($plan)),
            'prefix-hierarchy.json' => $this->json([
                'schema_version' => 1,
                'title' => Lang::get('ipamferry.report.prefix_hierarchy', [], $locale),
                'roots' => $prefixHierarchy,
            ]),
            'proposed-references.json' => $this->json([
                'schema_version' => 1,
                'reference_rules' => $plan->mapping_snapshot['reference_rules'] ?? [],
                'relation_rules' => $plan->mapping_snapshot['relation_rules'] ?? [],
            ]),
            'preservation-decisions.json' => $this->json([
                'schema_version' => 1,
                'rules' => $plan->mapping_snapshot['preservation_rules'] ?? [],
                'preservation' => $plan->preservation,
            ]),
        ];
        if ($execution !== null) {
            $files['execution.json'] = $this->json([
                'id' => $execution->id,
                'status' => $execution->status->value,
                'summary' => $execution->summary,
                'actions' => $execution->actionResults->map(fn ($result): array => [
                    'action_key' => $result->action_key,
                    'operation' => $result->operation,
                    'status' => $result->status->value,
                    'target_type' => $result->target_type,
                    'target_id' => $result->target_id,
                    'request_id' => $result->request_id,
                    'attempts' => $result->attempts,
                    'error' => $result->error,
                ])->all(),
            ]);
        }
        $files['audit-events.json'] = $this->json([
            'schema_version' => 1,
            'events' => $project->events()
                ->orderBy('id')
                ->get(['id', 'actor_id', 'plan_id', 'execution_id', 'kind', 'level', 'context', 'created_at'])
                ->map(fn ($event): array => [
                    'id' => $event->id,
                    'actor_id' => $event->actor_id,
                    'plan_id' => $event->plan_id,
                    'execution_id' => $event->execution_id,
                    'kind' => $event->kind,
                    'level' => $event->level,
                    'context' => $event->context,
                    'created_at' => $event->created_at->toIso8601String(),
                ])
                ->all(),
        ]);

        $manifest = [
            'schema_version' => 2,
            'ipamferry_version' => config('ipamferry.version'),
            'mapping_schema_version' => $plan->mapping_snapshot['schema_version'] ?? 1,
            'plan_schema_version' => $plan->schema_version,
            'project_id' => $project->id,
            'plan_id' => $plan->id,
            'fingerprint' => $plan->fingerprint,
            'source_fingerprint' => $plan->source_fingerprint,
            'target_fingerprint' => $plan->target_fingerprint,
            'mapping_fingerprint' => $plan->mapping_fingerprint,
            'target_instance_fingerprint' => $plan->target_instance_fingerprint,
            'locale' => $locale,
            'generated_at' => $generatedAt,
            'files' => array_map(
                fn (string $contents): array => ['sha256' => hash('sha256', $contents), 'bytes' => strlen($contents)],
                $files,
            ),
        ];
        $files = ['manifest.json' => $this->json($manifest), ...$files];

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the migration bundle.');
        }
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function localizedWarning(string $warning, string $locale): string
    {
        if (str_starts_with($warning, '{')) {
            try {
                $issue = json_decode($warning, true, 64, JSON_THROW_ON_ERROR);
                $reason = is_array($issue) ? ($issue['reason'] ?? null) : null;
                if (is_string($reason) && Lang::has("ipamferry.report.issues.{$reason}", $locale)) {
                    return Lang::get("ipamferry.report.issues.{$reason}", [], $locale);
                }
            } catch (\JsonException) {
            }
        }
        if (preg_match('/^([a-z0-9_]+) require mapping review before export to NetBox\.$/', $warning, $matches)) {
            return Lang::get('ipamferry.report.warning', ['type' => $matches[1]], $locale);
        }

        return $warning;
    }

    private function html(array $report, string $locale): string
    {
        $warnings = implode('', array_map(fn (string $warning) => '<li>'.e($warning).'</li>', $report['warnings']));
        $preservation = implode('', array_map(
            fn (int $count, string $category): string => '<tr><td>'.e($category).'</td><td>'.e((string) $count).'</td></tr>',
            $report['preservation']['categories'],
            array_keys($report['preservation']['categories']),
        ));

        return '<!doctype html><html lang="'.e(str_replace('_', '-', $locale)).'"><head><meta charset="utf-8"><title>'.e($report['title']).'</title></head><body><h1>'.e($report['title']).'</h1><p>'.e($report['summary']).'</p><p><strong>'.e(Lang::get('ipamferry.report.generated_at', [], $locale)).':</strong> '.e($report['generated_at']).'</p><h2>'.e(Lang::get('ipamferry.report.warnings', [], $locale)).'</h2><ul>'.$warnings.'</ul><h2>'.e($report['preservation']['title']).'</h2><table><thead><tr><th>'.e(Lang::get('ipamferry.report.category', [], $locale)).'</th><th>'.e(Lang::get('ipamferry.report.objects', [], $locale)).'</th></tr></thead><tbody>'.$preservation.'</tbody></table><h2>'.e($report['prefix_hierarchy']['title']).'</h2>'.$this->prefixTree($report['prefix_hierarchy']['tree'] ?? []).'</body></html>';
    }

    private function preservationSummary(array $preservation): array
    {
        $summary = [];
        foreach ($preservation as $category => $value) {
            if (in_array($category, ['decisions', 'source_records', 'ignored'], true)) {
                continue;
            }
            if (! is_array($value)) {
                $summary[$category] = 0;

                continue;
            }
            $summary[$category] = array_is_list($value)
                ? count($value)
                : array_sum(array_map(fn (mixed $items): int => is_array($items) ? count($items) : 1, $value));
        }

        return $summary;
    }

    private function coverage(MigrationPlan $plan): array
    {
        $sourceTypes = [];
        $targetTypes = [];
        $operations = [];
        foreach ($plan->actions as $action) {
            $sourceType = (string) ($action['source_type'] ?? 'unknown');
            $targetType = (string) ($action['target_type'] ?? 'unknown');
            $operation = (string) ($action['operation'] ?? 'unknown');
            $sourceTypes[$sourceType] = ($sourceTypes[$sourceType] ?? 0) + 1;
            $targetTypes[$targetType] = ($targetTypes[$targetType] ?? 0) + 1;
            $operations[$operation] = ($operations[$operation] ?? 0) + 1;
        }
        ksort($sourceTypes, SORT_STRING);
        ksort($targetTypes, SORT_STRING);
        ksort($operations, SORT_STRING);

        return [
            'schema_version' => 1,
            'source_types' => $sourceTypes,
            'target_types' => $targetTypes,
            'operations' => $operations,
            'preservation' => $this->preservationSummary($plan->preservation),
        ];
    }

    private function prefixCount(array $nodes): int
    {
        return array_sum(array_map(fn (array $node): int => 1 + $this->prefixCount($node['children'] ?? []), $nodes));
    }

    private function prefixTree(array $nodes): string
    {
        if ($nodes === []) {
            return '<p>0</p>';
        }
        $items = implode('', array_map(function (array $node): string {
            $label = e($node['prefix']);
            $description = isset($node['description']) ? ' — '.e($node['description']) : '';

            return '<li><code>'.$label.'</code>'.$description.$this->prefixTree($node['children'] ?? []).'</li>';
        }, $nodes));

        return '<ul>'.$items.'</ul>';
    }
}
