<?php

namespace App\Domain\Migration;

final class NetBoxPayloadComparator
{
    public function differences(array $expected, array $actual, bool $includeValues = false): array
    {
        $differences = [];
        foreach ($expected as $field => $value) {
            if ($field === 'custom_fields' && is_array($value)) {
                foreach ($value as $customField => $customValue) {
                    $current = $this->normalizeActual(
                        $actual['custom_fields'][$customField] ?? null,
                        $customValue,
                    );
                    if ($current !== $customValue) {
                        $key = "custom_fields.{$customField}";
                        $differences[$key] = $includeValues
                            ? ['expected' => $customValue, 'actual' => $current]
                            : true;
                    }
                }

                continue;
            }

            $actualValue = $this->normalizeActual($this->actualValue((string) $field, $actual), $value);
            if ($actualValue !== $value) {
                $differences[$field] = $includeValues
                    ? ['expected' => $value, 'actual' => $actualValue]
                    : true;
            }
        }

        return $differences;
    }

    private function actualValue(string $field, array $actual): mixed
    {
        if (array_key_exists($field, $actual)) {
            return $actual[$field];
        }
        if (str_ends_with($field, '_id')) {
            $objectField = substr($field, 0, -3);
            if (array_key_exists($objectField, $actual)) {
                return $actual[$objectField];
            }
        }

        return null;
    }

    private function normalizeActual(mixed $actual, mixed $expected): mixed
    {
        if (is_int($expected) && is_float($actual) && is_finite($actual) && $actual === (float) $expected) {
            return $expected;
        }
        if (is_float($expected) && is_int($actual) && (float) $actual === $expected) {
            return $expected;
        }
        if (is_array($actual) && ! is_array($expected)) {
            if (array_key_exists('value', $actual)) {
                return $actual['value'];
            }
            if (array_key_exists('id', $actual)) {
                return $actual['id'];
            }
        }
        if (is_array($actual) && is_array($expected) && array_is_list($actual) && array_is_list($expected)) {
            return array_map(
                fn (mixed $item, int $index): mixed => $this->normalizeActual(
                    $item,
                    $expected[$index] ?? ($expected[0] ?? null),
                ),
                $actual,
                array_keys($actual),
            );
        }

        return $actual;
    }
}
