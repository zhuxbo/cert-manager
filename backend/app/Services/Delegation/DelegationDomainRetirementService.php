<?php

declare(strict_types=1);

namespace App\Services\Delegation;

use App\Models\CnameDelegation;
use App\Models\Setting;
use DomainException;
use InvalidArgumentException;

final class DelegationDomainRetirementService
{
    public function __construct(
        private readonly DelegationConfigService $configs,
    ) {}

    public function preflight(Setting $setting): void
    {
        if ($this->isDefaultDomainSetting($setting)) {
            throw new DomainException('默认委托域设置不能删除');
        }

        $domain = $this->domainFromSetting($setting);
        if ($domain === null) {
            return;
        }

        if ($domain === $this->configs->defaultDomain()) {
            throw new DomainException('当前默认委托域不能删除');
        }

        $count = CnameDelegation::query()
            ->where('proxy_domain', $domain)
            ->count();

        if ($count > 0) {
            throw new DomainException("仍有 {$count} 条委托记录使用该委托域");
        }
    }

    /** @param array<string, mixed> $attributes */
    public function assertUpdatePreservesIdentity(Setting $setting, array $attributes): void
    {
        if (! $setting->group()->where('name', 'delegation')->exists()) {
            return;
        }

        $groupId = array_key_exists('group_id', $attributes)
            ? (int) $attributes['group_id']
            : (int) $setting->group_id;
        $key = array_key_exists('key', $attributes)
            ? (string) $attributes['key']
            : $setting->key;
        $type = array_key_exists('type', $attributes)
            ? (string) $attributes['type']
            : $setting->type;

        if ($groupId !== (int) $setting->group_id || $type !== $setting->type) {
            throw new DomainException('委托设置标识不能直接修改');
        }

        if ($setting->key === 'defaultDomain') {
            if ($key !== $setting->key) {
                throw new DomainException('委托设置标识不能直接修改');
            }

            return;
        }

        if ($this->isBlankDomainDraft($setting)) {
            return;
        }

        if ($key !== $setting->key) {
            throw new DomainException('委托设置标识不能直接修改');
        }

        $currentDomain = $this->domainIdentity($setting->key, $setting->type, $setting->value);
        $nextValue = array_key_exists('value', $attributes) ? $attributes['value'] : $setting->value;
        $nextDomain = $this->domainIdentity($key, $type, $nextValue);

        if ($nextDomain !== $currentDomain) {
            throw new DomainException('委托域不能直接改名，请新增新域后删除旧域');
        }
    }

    public function retire(Setting $setting): void
    {
        $this->retireMany([$setting]);
    }

    /** @param iterable<Setting> $settings */
    public function retireMany(iterable $settings): void
    {
        $settings = collect($settings)
            ->unique(fn (Setting $setting): int => (int) $setting->getKey())
            ->values();

        foreach ($settings as $setting) {
            $this->preflight($setting);
        }

        foreach ($settings as $setting) {
            $setting->delete();
        }
    }

    private function isDefaultDomainSetting(Setting $setting): bool
    {
        return $setting->key === 'defaultDomain'
            && $setting->group()->where('name', 'delegation')->exists();
    }

    private function domainFromSetting(Setting $setting): ?string
    {
        if (! $setting->group()->where('name', 'delegation')->exists()) {
            return null;
        }
        if ($this->isBlankDomainDraft($setting)) {
            return null;
        }

        return $this->domainIdentity($setting->key, $setting->type, $setting->value);
    }

    private function isBlankDomainDraft(Setting $setting): bool
    {
        $value = $setting->value;

        return $setting->type === 'array'
            && is_array($value)
            && array_key_exists('domain', $value)
            && is_string($value['domain'])
            && trim($value['domain']) === '';
    }

    private function domainIdentity(string $key, string $type, mixed $value): string
    {
        if ($type !== 'array' || ! is_array($value) || ! isset($value['domain']) || ! is_string($value['domain'])) {
            throw new DomainException('委托域配置无效，已拒绝更新');
        }

        try {
            $domain = $this->configs->normalizeDomain($value['domain']);
            if ($domain === '' || $this->configs->keyForDomain($domain) !== $key) {
                throw new InvalidArgumentException('domain 与设置键不匹配');
            }
        } catch (InvalidArgumentException $e) {
            throw new DomainException('委托域配置无效，已拒绝更新', previous: $e);
        }

        return $domain;
    }
}
