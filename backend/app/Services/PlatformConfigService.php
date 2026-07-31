<?php

namespace App\Services;

use App\Models\Setting;

class PlatformConfigService
{
    /**
     * @return array{Title: string, AllBrands: list<array{label: string, value: string}>, Brands: list<array{label: string, value: string}>, DnsTools: list<string>, Beian: string, CopyStart: string, Favicon: string, Logo: string, LogoExpanded: string, Qrcode: string, LoginImage: string}
     */
    public function get(string $channel): array
    {
        $site = Setting::getByGroupName('site');
        $brands = Setting::getByGroupName('brand');
        $channel = $channel === 'admin' ? 'admin' : 'user';
        $allBrands = $this->brandOptions($brands['all'] ?? null);

        return [
            'Title' => $this->stringValue($site['name'] ?? null, 'SSL'),
            'AllBrands' => $allBrands,
            'Brands' => $this->activeBrandOptions($brands[$channel] ?? null, $allBrands),
            'DnsTools' => $this->stringList($site['dnsTools'] ?? null, []),
            'Beian' => $this->stringValue($site['beian'] ?? null, ''),
            'CopyStart' => $this->stringValue($site['copyStart'] ?? null, '2017'),
            'Favicon' => $this->stringValue($site['favicon'] ?? null, ''),
            'Logo' => $this->stringValue($site['logo'] ?? null, '/logo.svg'),
            'LogoExpanded' => $this->stringValue($site['logoExpanded'] ?? null, ''),
            'Qrcode' => $this->stringValue($site['qrcode'] ?? null, '/qrcode.png'),
            // 空值表示未配置，用户端据此回落默认 login.svg / 纯色面板
            'LoginImage' => $this->stringValue($site['loginImage'] ?? null, ''),
        ];
    }

    private function stringValue(mixed $value, string $default): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    /** @param list<string> $default
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (! is_array($value)) {
            return $default;
        }

        $values = array_values(array_unique(array_filter(
            array_map(static fn (mixed $item): string => is_string($item) ? trim($item) : '', array_values($value)),
            static fn (string $item): bool => $item !== '',
        )));

        return $values;
    }

    /** @return list<array{label: string, value: string}> */
    private function brandOptions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        // PHP 保留 JSON 键序而 JS 对象数字键强制升序：整数键恰为 0..n-1 排列时按列表处理，
        // 与前端 normalizeBrandOptions 语义对齐（等价由共享夹具锁定）
        if (! array_is_list($value)) {
            $keys = array_keys($value);
            $sorted = $keys;
            sort($sorted);
            if ($sorted === range(0, count($value) - 1)) {
                ksort($value);
                $value = array_values($value);
            }
        }

        $result = [];
        $seen = [];
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            if (! $isList) {
                $label = is_string($item) ? trim($item) : '';
                $brandValue = is_string($key) ? mb_strtolower(trim($key)) : '';
            } elseif (is_string($item)) {
                $label = trim($item);
                $brandValue = mb_strtolower($label);
            } elseif (is_array($item)) {
                $label = isset($item['label']) && is_string($item['label']) ? trim($item['label']) : '';
                $brandValue = isset($item['value']) && is_string($item['value'])
                    ? mb_strtolower(trim($item['value']))
                    : '';
            } else {
                continue;
            }

            if ($label === '' || $brandValue === '' || isset($seen[$brandValue])) {
                continue;
            }

            $seen[$brandValue] = true;
            $result[] = ['label' => $label, 'value' => $brandValue];
        }

        return $result;
    }

    /**
     * @param  list<array{label: string, value: string}>  $allBrands
     * @return list<array{label: string, value: string}>
     */
    private function activeBrandOptions(mixed $value, array $allBrands): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $allByValue = [];
        foreach ($allBrands as $option) {
            $allByValue[$option['value']] = $option;
        }

        $result = [];
        $seen = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $brandValue = mb_strtolower(trim($item));
            if ($brandValue === '' || isset($seen[$brandValue]) || ! isset($allByValue[$brandValue])) {
                continue;
            }

            $seen[$brandValue] = true;
            $result[] = $allByValue[$brandValue];
        }

        return $result;
    }
}
