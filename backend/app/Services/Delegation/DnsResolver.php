<?php

declare(strict_types=1);

namespace App\Services\Delegation;

/**
 * 本地 DNS 解析薄封装（F2-1 可测注入缝）。
 *
 * 仅包一层 @dns_get_record，供 dnsTools 全部节点不可达时的本地兜底直查（钉死本地解析，
 * 绝不回打远端 dnsTools）。测试经 app()->instance(DnsResolver::class, $stub) 注入桩，
 * 避免依赖本机真实 DNS（反模式 15）。
 */
class DnsResolver
{
    /**
     * 查询主机名的 TXT 记录值列表（本地解析，失败/无记录返回空数组）。
     *
     * @return string[]
     */
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        if (empty($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            if (isset($record['txt'])) {
                $values[] = $record['txt'];
            }
        }

        return $values;
    }

    /**
     * 查询主机名的 CNAME 目标列表（本地解析，失败/无记录返回空数组）。
     *
     * @return string[]
     */
    public function cname(string $host): array
    {
        $records = @dns_get_record($host, DNS_CNAME);
        if (empty($records)) {
            return [];
        }

        $targets = [];
        foreach ($records as $record) {
            if (isset($record['target'])) {
                $targets[] = $record['target'];
            }
        }

        return $targets;
    }
}
