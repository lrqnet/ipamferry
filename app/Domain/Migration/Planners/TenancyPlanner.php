<?php

namespace App\Domain\Migration\Planners;

use App\Domain\Migration\CanonicalJson;
use App\Domain\Migration\MappingPolicy;
use Illuminate\Support\Str;

final class TenancyPlanner
{
    public function intents(array $objects, ?MappingPolicy $policy = null): array
    {
        $intents = [];
        foreach ($objects['customers'] ?? [] as $customer) {
            $name = (string) ($customer['name'] ?? '');
            $slug = Str::slug($name) ?: 'phpipam-customer-'.$customer['source_id'];
            $intents[] = PlannerIntent::object('tenant', $customer, ['slug' => $slug], [
                'name' => $name,
                'slug' => $slug,
                'description' => $customer['description'] ?? '',
                'comments' => $customer['address'] ?? '',
            ]);
        }
        foreach (['sections', 'tags'] as $collection) {
            foreach ($objects[$collection] ?? [] as $tag) {
                $tagSource = [
                    ...$tag,
                    'source_id' => $tag['source_type'].':'.$tag['source_id'],
                ];
                $name = (string) ($tag['name'] ?? '');
                $slug = Str::slug($name) ?: 'phpipam-'.$tag['source_type'].'-'.$tag['source_id'];
                $intents[] = PlannerIntent::object('tag', $tagSource, ['slug' => $slug], [
                    'name' => $name,
                    'slug' => $slug,
                    'color' => preg_match('/^[0-9a-fA-F]{6}$/', (string) ($tag['color'] ?? '')) === 1
                        ? strtolower((string) $tag['color'])
                        : '9e9e9e',
                    'description' => $tag['description'] ?? '',
                ]);
            }
        }
        $contactSettings = $policy?->relationSettings('customer_contacts');
        $role = is_array($contactSettings['contact_role'] ?? null) ? $contactSettings['contact_role'] : null;
        if ($contactSettings !== null && $role !== null) {
            $roleId = (string) ($role['id'] ?? 'customer');
            $roleSlug = Str::slug((string) ($role['slug'] ?? $role['name'] ?? 'customer')) ?: 'customer';
            $roleSource = [
                'source_type' => 'mapping_contact_role',
                'source_id' => $roleId,
                'source_hash' => CanonicalJson::fingerprint($role),
                'legacy' => [],
            ];
            $intents[] = PlannerIntent::object('contact_role', $roleSource, ['slug' => $roleSlug], [
                'name' => (string) ($role['name'] ?? 'Customer'),
                'slug' => $roleSlug,
                'description' => (string) ($role['description'] ?? ''),
            ], ($role['approved'] ?? false) === true);
            foreach ($objects['customers'] ?? [] as $customer) {
                if (($customer['contact_name'] ?? '') === '' && ($customer['contact_email'] ?? '') === '') {
                    continue;
                }
                $contactSource = [
                    ...$customer,
                    'source_type' => 'mapping_contact',
                    'source_id' => (string) $customer['source_id'],
                ];
                $intents[] = PlannerIntent::object('contact', $contactSource, [
                    'name' => $customer['contact_name'] ?: $customer['name'],
                    'email' => $customer['contact_email'] ?? '',
                ], [
                    'name' => $customer['contact_name'] ?: $customer['name'],
                    'email' => $customer['contact_email'] ?? '',
                    'phone' => $customer['contact_phone'] ?? '',
                    'address' => $customer['address'] ?? '',
                ]);
                $assignmentSource = [
                    'source_type' => 'mapping_contact_assignment',
                    'source_id' => (string) $customer['source_id'],
                    'source_hash' => CanonicalJson::fingerprint([$customer, $role]),
                    'legacy' => [],
                ];
                $tenant = PlannerIntent::reference('tenant', $customer['source_id']);
                $contact = PlannerIntent::reference('contact', $customer['source_id']);
                $roleRef = PlannerIntent::reference('contact_role', $roleId);
                $intents[] = PlannerIntent::object('contact_assignment', $assignmentSource, [
                    'object_type' => 'tenancy.tenant',
                    'object_id' => $tenant,
                    'contact_id' => $contact,
                    'role_id' => $roleRef,
                ], [
                    'object_type' => 'tenancy.tenant',
                    'object_id' => $tenant,
                    'contact' => $contact,
                    'role' => $roleRef,
                    'priority' => 'primary',
                ]);
            }
        }

        return $intents;
    }
}
