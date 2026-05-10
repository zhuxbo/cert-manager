<?php

declare(strict_types=1);

namespace App\Services\Order\Utils;

use Exception;
use Illuminate\Support\Facades\Log;
use Pdp\Rules;

class DomainUtil
{
    private static ?Rules $rules = null;

    /**
     * 获取给定域名的根域名，不包含通配符前缀
     *
     * @param  string  $domain  域名
     * @return string 根域名，如果无法解析则返回原始域名（不含通配符）如果是 IP 地址则返回空字符串
     */
    public static function getRootDomain(string $domain): string
    {
        if (! self::$rules) {
            self::loadRules();
        }

        // 处理通配符域名，去掉 '*.' 前缀
        if (str_starts_with($domain, '*.')) {
            $domain = substr($domain, 2);
        }

        if (self::getType($domain) === 'ipv4' || self::getType($domain) === 'ipv6') {
            return '';
        }

        // 处理国际化域名
        $asciiDomain = self::convertToAscii($domain);

        try {
            $result = self::$rules->resolve($asciiDomain);
            $registrableDomain = $result->registrableDomain()->toString();

            if ($registrableDomain === '') {
                // 无法解析根域名，返回原始域名（不含通配符）
                return $domain;
            }

            // 将根域名转换回国际化域名
            return self::convertToUnicode($registrableDomain);
        } catch (Exception $e) {
            // 使用 ThinkPHP 的日志记录方法
            Log::error('DomainParser Error in getRootDomain: '.$e->getMessage());

            return $domain;
        }
    }

    /**
     * 判断一个字符串是否为合法的域名，包括子域名和通配符域名，支持国际化域名
     *
     * @param  string  $domain  域名字符串
     * @return bool 如果是合法的域名则返回 true，否则返回 false
     */
    public static function isValidDomain(string $domain): bool
    {
        // 处理通配符域名，去掉 '*.' 前缀
        if (str_starts_with($domain, '*.')) {
            $domain = substr($domain, 2);
        }

        // 将域名转换为 ASCII（Punycode）
        $asciiDomain = self::convertToAscii($domain);

        // 自定义域名验证规则
        // 1. 域名总长度不超过253个字符
        // 2. 每个标签（点之间的部分）长度在1-63之间
        // 3. 标签可以包含字母、数字、连字符和下划线
        // 4. 连字符不能出现在开头或结尾
        if (strlen($asciiDomain) > 253) {
            return false;
        }

        $labels = explode('.', $asciiDomain);

        foreach ($labels as $label) {
            $length = strlen($label);
            if ($length < 1 || $length > 63) {
                return false;
            }

            // 允许标签以字母、数字或下划线开头
            // 中间可以包含字母、数字、下划线和连字符
            // 必须以字母、数字或下划线结尾
            if (! preg_match('/^[a-zA-Z0-9_][a-zA-Z0-9_-]*[a-zA-Z0-9_]$|^[a-zA-Z0-9_]$/', $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断一个字符串是标准域名、通配符域名、IPv4、IPv6，还是其他
     *
     * @param  string  $input  要检测的字符串
     * @return string|null 返回 'standard'、'wildcard'、'ipv4'、'ipv6'，都不是则返回 null
     */
    public static function getType(string $input): ?string
    {
        // 检查是否为 IPv4 地址
        if (filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'ipv4';
        }

        // 检查是否为 IPv6 地址
        if (filter_var($input, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'ipv6';
        }

        // 检查是否为通配符域名
        if (str_starts_with($input, '*.')) {
            $domain = substr($input, 2);
            if (self::isValidDomain($domain)) {
                return 'wildcard';
            }
        }

        // 检查是否为标准域名
        if (self::isValidDomain($input)) {
            return 'standard';
        }

        // 都不是，返回 null
        return null;
    }

    /**
     * 将域名转换为 ASCII（Punycode），用于处理国际化域名
     *
     * @param  string  $domain  原始域名
     * @return string 转换后的 ASCII 域名，如果转换失败则返回原始域名
     */
    public static function convertToAscii(string $domain): string
    {
        if ($domain === '') {
            return '';
        }

        if (function_exists('idn_to_ascii')) {
            $asciiDomain = idn_to_ascii($domain);

            if ($asciiDomain !== false) {
                return $asciiDomain;
            } else {
                return $domain;
            }
        } else {
            return $domain;
        }
    }

    /**
     * 将 ASCII（Punycode）域名转换回国际化域名
     *
     * @param  string  $domain  ASCII 域名
     * @return string 转换后的国际化域名，如果转换失败则返回原始域名
     */
    public static function convertToUnicode(string $domain): string
    {
        if ($domain === '') {
            return '';
        }

        if (function_exists('idn_to_utf8')) {
            $unicodeDomain = idn_to_utf8($domain);

            if ($unicodeDomain !== false) {
                return $unicodeDomain;
            } else {
                return $domain;
            }
        } else {
            return $domain;
        }
    }

    /**
     * 将域名转换为 ASCII（Punycode），用于处理国际化域名, 多个域名用逗号分隔
     *
     * @param  string  $domains  原始域名，多个域名以逗号分隔
     * @return string 转换为ASCII编码后的域名字符串
     */
    public static function convertToAsciiDomains(string $domains): string
    {
        $domains = array_filter(explode(',', $domains));

        return implode(',', array_map(function ($domain) {
            return self::convertToAscii($domain);
        }, $domains));
    }

    /**
     * 将 ASCII（Punycode）域名转换为国际化域名，多个域名以逗号分隔
     *
     * @param  string  $domains  ASCII 域名，多个域名以逗号分隔
     * @return string 转换后的国际化域名，多个域名以逗号分隔，如果转换失败则返回原始域名
     */
    public static function convertToUnicodeDomains(string $domains): string
    {
        $domains = array_filter(explode(',', $domains));

        return implode(',', array_map(function ($domain) {
            return self::convertToUnicode($domain);
        }, $domains));
    }

    /**
     * 移除赠送的域名，返回最少的域名列表
     *
     * - 移除通配符匹配的一级子域名，对所有非通配符的域名处理，不去掉 www
     * - 不限制根域名，带 www 和不带 www 的域名如果同时存在，保留带 www 的，去除不带 www 的
     * - 通配符匹配只匹配一级子域名
     * - 移除通配符对应的基础域名（如 *.abc.com 移除 abc.com）
     *
     * @param  string  $domains  逗号分割的域名字符串
     * @return string 处理后的域名字符串
     */
    public static function removeGiftDomain(string $domains): string
    {
        $domainArray = array_map('trim', explode(',', $domains));

        $wildcardDomains = [];
        $nonWildcardDomains = [];

        // 分离通配符域名和非通配符域名
        foreach ($domainArray as $domain) {
            if (str_starts_with($domain, '*.')) {
                $wildcardDomains[] = $domain;
            } else {
                $nonWildcardDomains[] = $domain;
            }
        }

        // 先移除通配符匹配的一级子域名
        $filteredNonWildcardDomains = self::removeWildcardMatchedDomains($nonWildcardDomains, $wildcardDomains);

        // 移除通配符对应的基础域名
        $filteredNonWildcardDomains = self::removeWildcardBaseDomains($filteredNonWildcardDomains, $wildcardDomains);

        // 处理非通配符域名
        // 保留带 www 的版本 目的是避免去掉www后被通配符匹配而被移除
        $domainsMap = [];
        foreach ($filteredNonWildcardDomains as $domain) {
            // 检查域名是否以 'www.' 开头
            if (str_starts_with($domain, 'www.')) {
                $domainKey = substr($domain, 4);
                // 由于这是带 'www.' 的版本，我们优先保留它
                $domainsMap[$domainKey] = $domain;
            } else {
                $domainKey = $domain;
                // 如果 'www.' 版本不存在，则添加当前域名
                if (! isset($domainsMap[$domainKey])) {
                    $domainsMap[$domainKey] = $domain;
                }
            }
        }

        $processedDomains = array_values($domainsMap);

        // 合并通配符域名和处理后的非通配符域名
        $finalDomains = array_merge($processedDomains, $wildcardDomains);

        // 去重并返回逗号分割的字符串
        $finalDomains = array_unique($finalDomains);

        return implode(',', $finalDomains);
    }

    /**
     * 移除通配符对应的基础域名
     *
     * @param  array  $domains  非通配符的域名列表
     * @param  array  $wildcardDomains  通配符域名列表
     * @return array 处理后的域名列表
     */
    public static function removeWildcardBaseDomains(array $domains, array $wildcardDomains): array
    {
        $baseDomainsToRemove = [];

        foreach ($wildcardDomains as $wildcardDomain) {
            $baseDomain = substr($wildcardDomain, 2); // 去掉 '*.'
            $baseDomainsToRemove[] = $baseDomain;
            $baseDomainsToRemove[] = 'www.'.$baseDomain;
        }

        return array_filter($domains, function ($domain) use ($baseDomainsToRemove) {
            return ! in_array($domain, $baseDomainsToRemove);
        });
    }

    /**
     * 移除通配符匹配的一级子域名
     *
     * @param  array  $domains  非通配符的域名列表
     * @param  array  $wildcardDomains  通配符域名列表
     * @return array 处理后的域名列表
     */
    public static function removeWildcardMatchedDomains(array $domains, array $wildcardDomains): array
    {
        $result = [];
        foreach ($domains as $domain) {
            $matched = false;
            foreach ($wildcardDomains as $wildcardDomain) {
                $baseDomain = substr($wildcardDomain, 2); // 去掉 '*.'
                if (self::isWildcardMatched($domain, $baseDomain)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $result[] = $domain;
            }
        }

        return $result;
    }

    /**
     * 添加赠送的域名
     *
     * - 先移除通配符匹配的一级子域名
     * - 只对根域名处理，补齐缺少的根域名和 www 版本
     * - 对于通配符域名，补齐去掉 "*." 的域名
     * - 非根域名不处理
     *
     * @param  string  $domains  逗号分割的域名字符串
     * @return string 处理后的域名字符串
     */
    public static function addGiftDomain(string $domains): string
    {
        $domainArray = array_map('trim', explode(',', $domains));

        $allDomains = [];

        $wildcardDomains = [];

        // 添加赠送的域名
        foreach ($domainArray as $domain) {
            $allDomains[] = $domain;
            if (str_starts_with($domain, '*.')) {
                $allDomains[] = substr($domain, 2);

                $wildcardDomains[] = $domain;
            } else {
                $rootDomain = self::getRootDomain($domain);

                if ($domain === $rootDomain) {
                    $allDomains[] = 'www.'.$rootDomain;
                }

                if ($domain === 'www.'.$rootDomain) {
                    $allDomains[] = $rootDomain;
                }
            }
        }

        // 去重
        $allDomains = array_unique($allDomains);

        // 移除通配符匹配的一级子域名
        $allDomains = self::removeWildcardMatchedDomains($allDomains, $wildcardDomains);

        return implode(',', $allDomains);
    }

    /**
     * 判断一个域名是否被通配符域名匹配（只匹配一级子域名）
     *
     * @param  string  $domain  要检查的域名
     * @param  string  $baseDomain  通配符的基础域名（不含 '*.'）
     */
    public static function isWildcardMatched(string $domain, string $baseDomain): bool
    {
        if (str_starts_with($domain, '*.')) {
            return false;
        }

        if ($domain === $baseDomain) {
            return false;
        }

        if (! str_ends_with($domain, $baseDomain)) {
            return false;
        }

        $prefix = substr($domain, 0, -strlen($baseDomain));
        $prefix = rtrim($prefix, '.');

        if ($prefix === '') {
            return false;
        }

        $labels = explode('.', $prefix);

        // 通配符只匹配一级子域名
        return count($labels) === 1;
    }

    /**
     * 加载公共后缀列表规则，缓存文件并设置30天过期
     */
    private static function loadRules(): void
    {
        // 使用 Laravel 的 storage_path() 获取存储目录
        $cacheDir = storage_path('domain-rules/');

        // 确保缓存目录存在
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $dataFile = $cacheDir.'public_suffix_list.dat';
        $ttl = 30 * 24 * 60 * 60; // 30天的秒数

        // 检查文件是否存在且未过期
        if (file_exists($dataFile) && (time() - filemtime($dataFile)) < $ttl) {
            // 从本地文件加载规则
            self::$rules = Rules::fromPath($dataFile);
        } else {
            // 尝试在线获取最新的公共后缀列表
            $publicSuffixListUrl = 'https://publicsuffix.org/list/public_suffix_list.dat';
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5, // 超时时间（秒）
                ],
            ]);
            $data = @file_get_contents($publicSuffixListUrl, false, $context);

            if ($data !== false) {
                // 将数据保存到本地文件，加锁防止并发写入
                $fp = fopen($dataFile, 'w');
                if ($fp) {
                    if (flock($fp, LOCK_EX)) {
                        fwrite($fp, $data);
                        fflush($fp);
                        flock($fp, LOCK_UN);
                    }
                    fclose($fp);
                } else {
                    // 使用 ThinkPHP 的日志记录方法
                    Log::error('DomainParser Error in loadRules: Unable to write to data file');
                }

                // 从保存的文件加载规则
                self::$rules = Rules::fromPath($dataFile);
            } else {
                // 无法在线获取，尝试使用默认的常用域名后缀
                Log::error('DomainParser Error in loadRules: Unable to obtain public suffix list, using default suffixes');

                // 定义常用的域名后缀列表
                $defaultSuffixes = <<<'EOT'
// Common Domain Suffixes
ac
ad
ae
af
ag
ai
al
am
ao
aq
ar
as
at
au
aw
ax
az
ba
bb
bd
be
bf
bg
bh
bi
bj
bm
bn
bo
br
bs
bt
bv
bw
by
bz
ca
cc
cd
cf
cg
ch
ci
ck
cl
cm
cn
co
com
net
org
info
biz
jp
kr
tw
uk
us
EOT;

                // 使用默认的域名后缀列表创建规则
                self::$rules = Rules::fromString($defaultSuffixes);
            }
        }
    }
}
