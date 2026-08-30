<?php

namespace App\Services\Delegation;

use App\Models\Setting;
use App\Services\Order\Utils\DomainUtil;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DelegationConfigService
{
    private const int MAX_SETTING_KEY_LENGTH = 100;

    public function normalizeDomain(string $domain): string
    {
        $domain = rtrim(trim($domain), '.');
        if ($domain === '') {
            return '';
        }

        $domain = strtolower(DomainUtil::convertToAscii($domain));
        if (preg_match('/[^\x00-\x7F]/', $domain) === 1) {
            throw new InvalidArgumentException('委托代理域名 IDNA 转换失败');
        }
        if (strlen($domain) > 253) {
            throw new InvalidArgumentException('委托代理域名不能超过 253 个字符');
        }

        foreach (explode('.', $domain) as $label) {
            if (strlen($label) < 1 || strlen($label) > 63
                || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label) !== 1) {
                throw new InvalidArgumentException('委托代理域名格式无效');
            }
        }

        return $domain;
    }

    public function keyForDomain(string $domain): string
    {
        $key = Str::camel(str_replace('.', '_', $this->normalizeDomain($domain)));

        if (mb_strlen($key) > self::MAX_SETTING_KEY_LENGTH) {
            throw new InvalidArgumentException('委托代理域名生成的设置键不能超过 100 个字符');
        }

        return $key;
    }

    public function defaultDomain(): string
    {
        $domain = Setting::getValue('delegation', 'defaultDomain');

        return is_string($domain) ? $this->normalizeDomain($domain) : '';
    }

    /** @return array<string, mixed> */
    public function get(string $domain): array
    {
        $normalizedDomain = $this->normalizeDomain($domain);
        if ($normalizedDomain === '') {
            return [];
        }

        $config = Setting::getValue('delegation', $this->keyForDomain($normalizedDomain));
        if (! is_array($config) || ! isset($config['domain']) || ! is_string($config['domain'])) {
            return [];
        }

        try {
            if ($this->normalizeDomain($config['domain']) !== $normalizedDomain) {
                return [];
            }
        } catch (InvalidArgumentException) {
            return [];
        }

        if (! $this->hasRequiredProviderCredentials($config)) {
            return [];
        }
        $config['domain'] = $normalizedDomain;

        return $config;
    }

    /**
     * 验证 delegation 组中的域配置并返回规范 ASCII 域名。
     *
     * @throws InvalidArgumentException
     */
    public function domainForSetting(Setting $setting): string
    {
        if ($setting->type !== 'array') {
            throw new InvalidArgumentException('type 必须为 array');
        }

        $config = $setting->value;
        if (! is_array($config)) {
            throw new InvalidArgumentException('值必须为数组');
        }
        if (! isset($config['domain']) || ! is_string($config['domain'])) {
            throw new InvalidArgumentException('缺少有效 domain');
        }

        $domain = $this->normalizeDomain($config['domain']);
        if ($domain === '') {
            throw new InvalidArgumentException('缺少有效 domain');
        }
        if ($setting->key !== $this->keyForDomain($domain)) {
            throw new InvalidArgumentException('domain 与设置键不匹配');
        }
        if (! $this->hasRequiredProviderCredentials($config)) {
            throw new InvalidArgumentException('provider 或凭据无效');
        }

        return $domain;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->parseSettings()['configs'];
    }

    /** @return array<string, string> */
    public function invalidSettings(): array
    {
        return $this->parseSettings()['invalid'];
    }

    /**
     * @return array{
     *     configs: array<string, array<string, mixed>>,
     *     invalid: array<string, string>
     * }
     */
    private function parseSettings(): array
    {
        $settings = Setting::query()
            ->whereHas('group', fn ($query) => $query->where('name', 'delegation'))
            ->where('key', '!=', 'defaultDomain')
            ->orderBy('id')
            ->get();

        $configs = [];
        $invalid = [];
        foreach ($settings as $setting) {
            $key = $setting->key;
            if ($this->isBlankDomainDraft($setting)) {
                continue;
            }

            try {
                $domain = $this->domainForSetting($setting);
            } catch (InvalidArgumentException $e) {
                $invalid[$key] = str_contains($e->getMessage(), '设置键不能超过')
                    ? 'domain 派生设置键超过 100 个字符'
                    : $e->getMessage();

                continue;
            }

            if (isset($configs[$domain])) {
                $invalid[$key] = 'domain 配置重复';

                continue;
            }

            $config = $setting->value;
            if (! is_array($config)) {
                $invalid[$key] = '值必须为数组';

                continue;
            }
            $config['domain'] = $domain;
            $configs[$domain] = $config;
        }

        return ['configs' => $configs, 'invalid' => $invalid];
    }

    private function isBlankDomainDraft(Setting $setting): bool
    {
        $config = $setting->value;

        return $setting->type === 'array'
            && is_array($config)
            && array_key_exists('domain', $config)
            && is_string($config['domain'])
            && trim($config['domain']) === '';
    }

    /** @param array<string, mixed> $config */
    private function hasRequiredProviderCredentials(array $config): bool
    {
        $provider = $config['provider'] ?? null;
        if (! is_string($provider)) {
            return false;
        }

        return match (strtolower(trim($provider))) {
            'tencent' => $this->hasNonEmptyString($config, 'secretId')
                && $this->hasNonEmptyString($config, 'secretKey'),
            'cloudflare' => $this->hasNonEmptyString($config, 'zoneId')
                && $this->hasNonEmptyString($config, 'apiToken'),
            'aliyun' => $this->hasNonEmptyString($config, 'accessKeyId')
                && $this->hasNonEmptyString($config, 'accessKeySecret'),
            default => false,
        };
    }

    /** @param array<string, mixed> $config */
    private function hasNonEmptyString(array $config, string $key): bool
    {
        return isset($config[$key]) && is_string($config[$key]) && trim($config[$key]) !== '';
    }
}
