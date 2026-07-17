<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Models\Product;

final class ProductCostNormalizer
{
    private const array SAN_FIELDS = [
        'standard' => 'alternative_standard_price',
        'wildcard' => 'alternative_wildcard_price',
    ];

    /**
     * @return array{cost: array<string, array<string, string>>, warnings: list<array{field: string, period: int|null, message: string}>}
     */
    public function normalize(Product $product, array $cost): array
    {
        $warnings = [];
        $normalized = [];
        $periods = array_map('strval', $product->periods ?? []);
        $alternativeTypes = $product->alternative_name_types ?? [];
        $requiredFields = ['price'];

        foreach (self::SAN_FIELDS as $type => $field) {
            if (in_array($type, $alternativeTypes, true)) {
                $requiredFields[] = $field;
            }
        }

        foreach (array_merge(['price'], array_values(self::SAN_FIELDS)) as $field) {
            if (! array_key_exists($field, $cost)) {
                continue;
            }

            if (! is_array($cost[$field])) {
                if (! in_array($field, $requiredFields, true)) {
                    $warnings[] = [
                        'field' => $field,
                        'period' => null,
                        'message' => '成本字段必须是数组',
                    ];
                }

                continue;
            }

            foreach ($cost[$field] as $period => $value) {
                $period = (string) $period;
                if (! in_array($field, $requiredFields, true) || ! in_array($period, $periods, true)) {
                    $warnings[] = $this->warning($field, $period, '存在当前产品不适用的成本项');
                }
            }
        }

        foreach ($periods as $period) {
            $this->copyRequired(
                $cost,
                $normalized,
                $warnings,
                'price',
                $period,
                allowZero: count($requiredFields) > 1,
            );

            foreach (array_slice($requiredFields, 1) as $field) {
                $this->copyRequired($cost, $normalized, $warnings, $field, $period, allowZero: false);
            }
        }

        return ['cost' => $normalized, 'warnings' => $warnings];
    }

    /**
     * @return array{cost: array<string, array<string, string>>, warnings: list<array{field: string, period: int|null, message: string}>}
     */
    public function validateStored(Product $product): array
    {
        $raw = $product->getRawOriginal('cost');
        $cost = is_string($raw) ? json_decode($raw, true) : $raw;

        return $this->normalize($product, is_array($cost) ? $cost : []);
    }

    /**
     * @param  array<string, mixed>  $cost
     * @param  array<string, array<string, string>>  $normalized
     * @param  list<array{field: string, period: int|null, message: string}>  $warnings
     */
    private function copyRequired(
        array $cost,
        array &$normalized,
        array &$warnings,
        string $field,
        string $period,
        bool $allowZero,
    ): void {
        if (! isset($cost[$field]) || ! is_array($cost[$field]) || ! array_key_exists($period, $cost[$field])) {
            $warnings[] = $this->warning($field, $period, '缺少必需的成本');

            return;
        }

        $value = $this->normalizeAmount($cost[$field][$period]);
        if ($value === null) {
            $warnings[] = $this->warning($field, $period, '成本必须是非负普通十进制数');

            return;
        }

        $normalized[$field][$period] = $value;

        if (! $allowZero && $value === '0') {
            $warnings[] = $this->warning($field, $period, '成本必须大于零');

            return;
        }
    }

    private function normalizeAmount(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 ? (string) $value : null;
        }

        if (is_float($value)) {
            if (! is_finite($value) || $value < 0) {
                return null;
            }

            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
            if (! is_string($value)) {
                return null;
            }
        }

        if (! is_string($value) || preg_match('/^\d+(?:\.\d+)?$/D', $value) !== 1) {
            return null;
        }

        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $integer : "$integer.$fraction";
    }

    /**
     * @return array{field: string, period: int|null, message: string}
     */
    private function warning(string $field, string $period, string $message): array
    {
        return [
            'field' => $field,
            'period' => ctype_digit($period) ? (int) $period : null,
            'message' => $message,
        ];
    }
}
