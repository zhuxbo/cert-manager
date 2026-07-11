<?php

declare(strict_types=1);

namespace App\Services\Order\Utils;

use App\Models\Order;
use App\Services\Delegation\DnsResolver;
use App\Traits\ApiResponseStatic;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VerifyUtil
{
    use ApiResponseStatic;

    /**
     * 获取验证工具URLs
     */
    private static function getDnsToolsUrls(): array
    {
        $urls = get_system_setting('site', 'dnsTools');

        // 确保返回数组格式
        return is_array($urls) ? $urls : [];
    }

    /**
     * 验证域名DNS记录，支持故障转移
     */
    private static function verifyDomains(string $ca, string $domains): array
    {
        $client = new Client([
            'timeout' => 3.0, // 设置超时时间为3秒
            'verify' => false, // 关闭SSL证书验证
        ]);

        foreach (self::getDnsToolsUrls() as $url) {
            try {
                $response = $client->post($url.'/api/domain/issue-verify', [
                    'json' => [
                        'brand' => $ca,
                        'domains' => $domains,
                    ],
                ]);

                return json_decode($response->getBody()->getContents(), true);
            } catch (GuzzleException) {
                continue; // 尝试下一个API
            }
        }

        // 如果所有API都失败，返回成功信息，忽略验证
        return ['code' => 1, 'data' => null];
    }

    /**
     * 验证订单域名是否能签发
     */
    public static function issueVerify(array $order_ids): void
    {
        // 查询符合条件的订单
        $orders = Order::with(['latestCert', 'product'])
            ->whereHas('latestCert', fn ($query) => $query->where('status', 'unpaid'))
            ->whereIn('id', $order_ids)
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        $resultErrors = [];
        $lastErrorMsg = '域名签发验证失败，请联系管理员';

        // 遍历订单进行验证
        foreach ($orders as $order) {
            // 如果产品类型不是 SSL，则跳过验证
            if ($order->product->product_type !== 'ssl') {
                continue;
            }

            // 检查必要字段是否存在
            if (empty($order->product->ca) || empty($order->latestCert->alternative_names)) {
                continue;
            }

            $result = self::verifyDomains($order->product->ca, $order->latestCert->alternative_names);

            // 如果验证返回了错误信息
            if ($result['code'] == 0) {
                $lastErrorMsg = $result['msg'] ?? $lastErrorMsg;
                $errors = [];
                foreach ($result['errors'] as $error) {
                    if ($error['valid'] === false) {
                        $errors[$error['display_domain']]['说明'] = $error['message'];
                        $errors[$error['display_domain']]['错误'] = $error['errors'];
                    }
                }
                $resultErrors[] = $errors;
            }
        }

        empty($resultErrors) || self::error($lastErrorMsg, $resultErrors);
    }

    /**
     * 验证域名验证记录，支持故障转移（F2-1）。
     *
     * 迁移到 Laravel Http facade（原裸 Guzzle 无法被 Http::fake 拦截、兜底不可测）。
     * 迁移非行为等价，按逐差异对齐：Guzzle 默认对 4xx/5xx 抛异常 → 故障转移，Laravel Http 默认不抛，
     * 故循环内显式 `$response->failed()` continue，保「错误状态码也转移」；连接级异常改 catch ConnectionException。
     *
     * dnsTools 全部节点不可达时经 DnsResolver 本地兜底：
     *  - 本地命中期望值 → code=1（走既有 revalidate 自愈），并带 dns_tools_down（infra 挂，供 admin 告警计数）。
     *  - 不可判定（含 file/http 项或本地未命中）→ code=0 + dns_tools_down=true（触发连续 N 建 sync 安全网）。
     * dnsTools 有节点应答（成功或 DCV 失败）时不打 dns_tools_down 标记（DNS 确实未就绪，维持现状）。
     */
    public static function verifyValidation(array $validation): array
    {
        $urls = self::getDnsToolsUrls();

        // 检查是否有可用的 DNS Tools URLs
        if (empty($urls)) {
            Log::error('DNS Tools URLs 未配置');

            return self::localFallbackResult($validation, 'DNS Tools URLs 未配置，无法进行域名验证');
        }

        $lastError = '';
        foreach ($urls as $url) {
            try {
                $response = Http::withoutVerifying() // 关闭 SSL 证书验证（对齐原 verify:false）
                    ->timeout(3) // 3 秒超时（对齐原 timeout:3.0）
                    ->asJson()
                    ->post($url.'/api/dcv/verify', $validation);

                // 4xx/5xx 视为节点降级 → 故障转移到下一节点（对齐原 Guzzle throw-on-error 语义，
                // 否则会误采信 5xx 节点的响应体）
                if ($response->failed()) {
                    $lastError = 'HTTP '.$response->status();
                    Log::error('DNS Tools API 返回错误状态码', ['url' => $url, 'status' => $response->status()]);

                    continue;
                }

                $result = $response->json();

                if ($result === null) {
                    Log::error('DNS Tools API 返回无效 JSON', ['url' => $url]);
                    $lastError = 'API 返回无效数据';

                    continue;
                }

                // dnsTools 节点有应答（成功或 DCV 失败）→ 不打 infra-down 标记
                return [
                    'code' => $result['code'] ?? 0,
                    'msg' => $result['msg'] ?? '',
                    'errors' => $result['errors'] ?? [],
                ];
            } catch (ConnectionException $e) {
                // 仅连接级异常做节点故障转移；其余罕见 Guzzle 异常（如重定向环）逸出本方法，
                // 交 ValidateCommand 外层 catch(Throwable) 兜底：该单本轮跳过、next_check_at 不前移、下轮重试
                $lastError = $e->getMessage();
                Log::error('DNS Tools API 请求失败', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                continue; // 尝试下一个API
            }
        }

        // 全部节点不可达 → 本地 DNS 兜底 + infra-down 信号
        return self::localFallbackResult($validation, 'DNS Tools API 请求失败: '.$lastError);
    }

    /**
     * dnsTools 全挂时的本地兜底结果（F2-1）。均带 dns_tools_down=true 供 admin 告警计数。
     *
     * @param  string  $downMsg  不可判定时的错误文案
     */
    private static function localFallbackResult(array $validation, string $downMsg): array
    {
        $local = self::verifyValidationLocal($validation);

        if ($local === true) {
            // 本地 DNS 确认有效 → 走既有 revalidate 自愈（CA 权威复核，本地 false-pass 仅多一次 revalidate、不误签）
            return ['code' => 1, 'msg' => '本地 DNS 兜底验证通过', 'errors' => [], 'dns_tools_down' => true];
        }

        // 不可判定（含 file/http 项或本地未命中）→ code=0 + infra-down 标记
        return ['code' => 0, 'msg' => $downMsg, 'dns_tools_down' => true];
    }

    /**
     * 本地 DNS 兜底判定（F2-1）：仅对 DNS 类项（txt/cname）经 DnsResolver 直查本地核对期望值。
     *
     * 钉死本地 dns_get_record（DnsResolver），绝不复用 queryTxtRecords（后者会先重打全部 dnsTools 各 3s，
     * 在停摆场景成倍放大延迟）。全部 DNS 项命中 → true；任一无法确认或含非 DNS 项（file/http/https/email）→ null
     * （不可判定，不 false-negative：本地可能滞后，交 CA 权威复核）。
     *
     * @return bool|null true=全部命中；null=不可判定
     */
    private static function verifyValidationLocal(array $validation): ?bool
    {
        $resolver = app(DnsResolver::class);
        $sawDnsItem = false;

        foreach ($validation as $item) {
            $method = strtolower($item['method'] ?? '');

            // 含 file/http/https/email/admin 等非 DNS 项 → 本地不可判定
            if (! in_array($method, ['txt', 'cname'], true)) {
                return null;
            }

            $expected = (string) ($item['value'] ?? '');
            $host = (string) ($item['host'] ?? '');
            if ($expected === '' || $host === '') {
                return null; // 缺判据 → 不可判定
            }

            // 裸前缀（无点）host 用 domain 补全成 FQDN（镜像 AutoDcvTxtService::collectTxtRecords）：
            // 主力 CA 的 host 常为裸前缀（_<md5>/_certum/_pki-validation），dns_get_record 查单标签名
            // 恒空 → 本地兜底对这些订单结构性失效。已是 FQDN（含点）的 host 保持不变。
            if (! str_contains($host, '.')) {
                $domain = ltrim((string) ($item['domain'] ?? ''), '*.');
                if ($domain === '') {
                    return null; // 无 domain 可补 → 不可判定（不对无意义单标签查询、不 false-negative）
                }
                $host = $host.'.'.$domain;
            }

            $sawDnsItem = true;

            if ($method === 'txt') {
                $hit = false;
                foreach ($resolver->txt($host) as $txtValue) {
                    if (trim((string) $txtValue) === trim($expected)) {
                        $hit = true;
                        break;
                    }
                }
                if (! $hit) {
                    return null; // 未命中 → 不可判定（不 false-negative）
                }
            } else { // cname
                $target = strtolower(rtrim($expected, '.'));
                $hit = false;
                foreach ($resolver->cname($host) as $cnameTarget) {
                    if (strtolower(rtrim((string) $cnameTarget, '.')) === $target) {
                        $hit = true;
                        break;
                    }
                }
                if (! $hit) {
                    return null;
                }
            }
        }

        return $sawDnsItem ? true : null;
    }

    /**
     * 验证 CNAME 委托记录
     *
     * 宽松策略：所有 dnsTools 节点 + 本地检测全部尝试，任一匹配即判定有效。
     * 目的是为自动续签放宽验证条件，尽可能发起续签，避免因 DNS 传播延迟
     * 或单节点缓存过期导致误判失败。
     *
     * @param  string  $host  主机名（如 _dnsauth.example.com）
     * @param  string  $expectedTarget  期望的CNAME目标
     * @return bool 是否验证通过
     */
    public static function verifyCnameDelegation(string $host, string $expectedTarget): bool
    {
        $urls = self::getDnsToolsUrls();

        // 检查是否有可用的 DNS Tools URLs
        if (empty($urls)) {
            Log::error('DNS Tools URLs 未配置');

            // 回退到本地 dns_get_record
            return self::checkCnameRecordLocal($host, $expectedTarget);
        }

        $client = new Client([
            'timeout' => 3.0, // 设置超时时间为3秒
            'verify' => false, // 关闭SSL证书验证
        ]);

        foreach ($urls as $url) {
            try {
                // 发送json数据到 /api/dns/query
                $response = $client->post($url.'/api/dns/query', [
                    'json' => [
                        'domain' => $host,
                        'type' => 'CNAME',
                    ],
                ]);

                $result = json_decode($response->getBody()->getContents(), true);

                if ($result === null) {
                    Log::error('DNS Tools API 返回无效 JSON', ['url' => $url]);

                    continue;
                }

                // 检查响应是否成功
                if (($result['code'] ?? 0) !== 1) {
                    // API 返回失败，尝试下一个
                    continue;
                }

                // 解析 records 数组
                $records = $result['data']['records'] ?? [];

                if (empty($records)) {
                    // 没有找到记录，尝试下一个 API
                    continue;
                }

                // 规范化期望的目标：去除尾部的点，转小写
                $expectedTarget = strtolower(rtrim($expectedTarget, '.'));

                // 检查是否有匹配的 CNAME 记录
                foreach ($records as $record) {
                    if (($record['type'] ?? '') === 'CNAME' && isset($record['value'])) {
                        $value = strtolower(rtrim($record['value'], '.'));
                        if ($value === $expectedTarget) {
                            return true;
                        }
                    }
                }

                // 记录不匹配，继续下一个节点（可能是 DNS 传播延迟或缓存过期）
                Log::info('CNAME委托验证：当前节点不匹配，尝试下一个', [
                    'host' => $host,
                    'expected' => $expectedTarget,
                    'url' => $url,
                    'records' => $records,
                ]);

                continue;
            } catch (GuzzleException $e) {
                Log::error('DNS Tools CNAME验证API 请求失败', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                continue; // 尝试下一个API
            }
        }

        // 所有 dnsTools 均未匹配（API 异常/无记录/不匹配），本地验证兜底
        // 即使远程节点返回了不匹配的记录，仍给本地一次机会，最大化验证通过率
        return self::checkCnameRecordLocal($host, $expectedTarget);
    }

    /**
     * 查询 TXT 记录，支持故障转移
     *
     * @param  string  $host  主机名（如 _certum.example.com）
     * @param  bool  $direct  仅返回直接属于该主机名的 TXT 记录，排除通过 CNAME 链解析到的记录
     * @return array TXT 记录数组
     */
    public static function queryTxtRecords(string $host, bool $direct = false): array
    {
        $urls = self::getDnsToolsUrls();
        $normalizedHost = strtolower(rtrim($host, '.'));

        if (! empty($urls)) {
            $client = new Client([
                'timeout' => 3.0,
                'verify' => false,
            ]);

            foreach ($urls as $url) {
                try {
                    $response = $client->post($url.'/api/dns/query', [
                        'json' => [
                            'domain' => $host,
                            'type' => 'TXT',
                        ],
                    ]);

                    $result = json_decode($response->getBody()->getContents(), true);

                    if ($result === null || ($result['code'] ?? 0) !== 1) {
                        continue;
                    }

                    $records = $result['data']['records'] ?? [];
                    $txtValues = [];
                    foreach ($records as $record) {
                        if (($record['type'] ?? '') !== 'TXT' || ! isset($record['value'])) {
                            continue;
                        }

                        // direct 模式：通过 name 字段精确匹配，排除 CNAME 链解析到的 TXT 记录
                        if ($direct && isset($record['name'])) {
                            $recordName = strtolower(rtrim($record['name'], '.'));
                            if ($recordName !== $normalizedHost) {
                                continue;
                            }
                        }

                        $txtValues[] = $record['value'];
                    }

                    return $txtValues;
                } catch (GuzzleException) {
                    continue;
                }
            }
        }

        // 回退到本地 dns_get_record
        // direct 模式：先查 CNAME，存在则说明 TXT 来自 CNAME 目标（dns_get_record 无法区分 owner name）
        if ($direct) {
            $cnameRecords = @dns_get_record($host, DNS_CNAME);
            if (! empty($cnameRecords)) {
                return [];
            }
        }

        $records = @dns_get_record($host, DNS_TXT);
        if (empty($records)) {
            return [];
        }

        $txtValues = [];
        foreach ($records as $record) {
            if (isset($record['txt'])) {
                $txtValues[] = $record['txt'];
            }
        }

        return $txtValues;
    }

    /**
     * 本地验证 CNAME 记录（使用 dns_get_record）
     *
     * @param  string  $host  主机名
     * @param  string  $expectedTarget  期望的CNAME目标
     * @return bool 是否匹配
     */
    private static function checkCnameRecordLocal(string $host, string $expectedTarget): bool
    {
        // 使用 dns_get_record 查询 CNAME 记录
        $records = @dns_get_record($host, DNS_CNAME);

        if (empty($records)) {
            return false;
        }

        // 规范化：去除尾部的点，转小写
        $expectedTarget = strtolower(rtrim($expectedTarget, '.'));

        foreach ($records as $record) {
            if (isset($record['target'])) {
                $target = strtolower(rtrim($record['target'], '.'));
                if ($target === $expectedTarget) {
                    return true;
                }
            }
        }

        return false;
    }
}
