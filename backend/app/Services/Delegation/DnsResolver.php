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
     * 注意：本方法把「查询失败」与「权威无记录」都塌缩为 `[]`，仅用于 F2-1 本地兜底
     * 这类「命中即用、未命中即放弃」场景。需要区分「不可达」与「权威无记录」三态的
     * 调用方（委托健康巡检的熔断/冻结层）必须改用 {@see cnameRecords()}。
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

    /**
     * 三态查询主机名的 CNAME 目标列表（本地解析，保留「查询失败」与「权威无记录」的区分）。
     *
     * `dns_get_record` 语义：解析器不可达/查询失败返回 `false`，NXDOMAIN 或无该类型记录返回
     * 空数组 `[]`（成功但无记录）。委托健康巡检据此三态判读——`null`=不可达（冻结计数、熔断
     * 计入）、`[]`=权威无记录（确认无效）、非空=记录列表；**绝不可复用把二者塌缩的
     * {@see cname()}**，否则不可达永不发生、冻结层与熔断整体虚设。
     *
     * @return string[]|null null=查询失败/解析器不可达；[]=权威无记录；非空=CNAME 目标列表
     */
    public function cnameRecords(string $host): ?array
    {
        $records = @dns_get_record($host, DNS_CNAME);
        if ($records === false) {
            return null;
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
