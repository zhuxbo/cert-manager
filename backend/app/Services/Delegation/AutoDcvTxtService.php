<?php

declare(strict_types=1);

namespace App\Services\Delegation;

use App\Models\Order;
use App\Services\Order\Utils\DomainUtil;
use Illuminate\Support\Facades\Log;

/**
 * 自动 DCV TXT 写入服务
 * 负责根据委托配置自动写入 TXT 记录并触发验证
 */
class AutoDcvTxtService
{
    protected CnameDelegationService $delegationService;

    protected DelegationDnsService $dnsService;

    public function __construct()
    {
        $this->delegationService = new CnameDelegationService;
        $this->dnsService = new DelegationDnsService;
    }

    /**
     * 处理订单的自动 TXT 写入（处理 validation 数组）
     *
     * @param  Order  $order  订单实例
     * @return bool 是否成功处理
     */
    public function handleOrder(Order $order): bool
    {
        $cert = $order->latestCert;

        if ($cert->dcv['method'] !== 'txt' || ! ($cert->dcv['is_delegate'] ?? false)) {
            return false;
        }

        // 获取 validation 数组
        $validation = $cert->validation;

        if (empty($validation)) {
            Log::info("订单 #$order->id validation为空或不是数组", [
                'order_id' => $order->id,
                'cert_id' => $cert->id,
            ]);

            return false;
        }

        // 检查是否所有TXT记录都已处理
        if ($this->allTxtRecordsProcessed($validation)) {
            return true;
        }

        // 收集需要写入的TXT记录（按delegation分组）
        [$txtRecordsByDelegation, $updatedValidation, $hasChanges] = $this->collectTxtRecords($order);

        // 批量写入TXT记录（按delegation分组）
        if (! empty($txtRecordsByDelegation)) {
            foreach ($txtRecordsByDelegation as $data) {
                $delegation = $data['delegation'];
                $tokens = $data['tokens'];

                $isSuccess = $this->dnsService->setTxtByLabel(
                    $delegation->proxy_zone,
                    $delegation->label,
                    $tokens
                );

                if (! $isSuccess) {
                    Log::error("订单 #$order->id 批量写入TXT失败", [
                        'order_id' => $order->id,
                        'delegation_id' => $delegation->id,
                    ]);

                    return false;
                }
            }
        }

        // 保存更新后的validation
        if ($hasChanges) {
            $cert->validation = $updatedValidation;
            $cert->save();

            return true;
        }

        return false;
    }

    /**
     * 判断是否需要处理委托
     *
     *
     * @return bool 是否需要处理委托
     */
    public function shouldProcessDelegation(Order $order): bool
    {
        [, , $hasChanges] = $this->collectTxtRecords($order);

        // 有变更就需要处理
        return $hasChanges;
    }

    /**
     * 收集需要写入的TXT记录（按delegation分组）
     *
     * @param  Order  $order  订单实例
     * @return array{0: array, 1: array, 2: bool} [txtRecordsByDelegation, updatedValidation, hasChanges]
     */
    protected function collectTxtRecords(Order $order): array
    {
        $cert = $order->latestCert;
        $validation = $cert->validation;

        // 按delegation分组的TXT记录集合，key为delegation_id，value包含delegation对象、tokens数组和validationIndexes数组
        $txtRecordsByDelegation = [];
        // 更新后的validation数组，包含已标记auto_txt_written的记录
        $updatedValidation = [];
        // 是否有记录被处理的标记，用于判断是否需要保存cert
        $hasChanges = false;

        foreach ($validation as $index => $item) {
            // 跳过已处理的
            if (isset($item['auto_txt_written']) && $item['auto_txt_written'] === true) {
                $updatedValidation[$index] = $item;

                continue;
            }

            $domain = $item['domain'] ?? '';
            $value = $item['value'] ?? '';
            $host = $item['host'] ?? '';

            if (empty($domain) || empty($value)) {
                Log::warning("订单 #$order->id validation[$index] 配置不完整", [
                    'order_id' => $order->id,
                    'index' => $index,
                    'host' => $host,
                    'domain' => $domain,
                    'value' => $value,
                ]);

                $updatedValidation[$index] = $item;

                continue;
            }

            // 优先使用 validation 内的 host，缺失时回退到 dcv.dns.host
            if (empty($host)) {
                $dcvHost = $cert->dcv['dns']['host'] ?? '';
                if (empty($dcvHost)) {
                    Log::warning("订单 #$order->id validation[$index] 缺少 host 且 dcv.dns.host 为空", [
                        'order_id' => $order->id,
                        'index' => $index,
                        'domain' => $domain,
                    ]);

                    $updatedValidation[$index] = $item;

                    continue;
                }

                $host = $dcvHost.'.'.ltrim($domain, '*.');
            } elseif (! str_contains($host, '.')) {
                // 仅前缀时补全域名
                $host = $host.'.'.ltrim($domain, '*.');
            }

            // 设置 host 和 token
            $token = $value;

            // 拆分 prefix 和 zone
            [$prefix, $zone] = $this->splitPrefixAndZone($host);

            if (! $prefix || ! $zone) {
                Log::warning("订单 #$order->id validation[$index] 无法解析host", [
                    'order_id' => $order->id,
                    'index' => $index,
                    'host' => $host,
                ]);

                $updatedValidation[$index] = $item;

                continue;
            }

            // 匹配委托记录（CA 驱动，与 ActionTrait::generateValidation 同口径）：
            // 回落型 CA（sectigo/certum）委托记录建在根域，证书域名为子域时需回落根域匹配；
            // 直接传 splitPrefixAndZone 得到的 zone（可能是子域），findDelegation 内部按 ca 回落。
            // ca 取值源对齐 generateValidation 的创建期冻结快照 dcv['ca']（委托本就按 dcv['ca']
            // 派生的 prefix 建）：若订单创建后 product.ca 改指别家 CA，用实时 product->ca 会以新
            // prefix 查不到旧委托 → 静默 miss、TXT 不写。回落 product->ca 兜 legacy 订单缺 dcv['ca']。
            $ca = strtolower($cert->dcv['ca'] ?? $order->product->ca ?? '');
            $delegation = $this->delegationService->findDelegation(
                $order->user_id,
                $zone,
                $ca
            );

            if (! $delegation) {
                // 未命中委托配置（源分歧或真实配置缺口两种成因）：记 warning surface 静默 miss
                Log::warning("订单 #$order->id validation[$index] 未命中委托配置，TXT 不写", [
                    'order_id' => $order->id,
                    'zone' => $zone,
                    'domain' => $domain,
                    'ca' => $ca,
                ]);

                $updatedValidation[$index] = $item;

                continue;
            }

            // 按delegation分组收集token
            $delegationKey = $delegation->id;
            if (! isset($txtRecordsByDelegation[$delegationKey])) {
                $txtRecordsByDelegation[$delegationKey] = [
                    'delegation' => $delegation,
                    'tokens' => [],
                    'validationIndexes' => [],
                ];
            }

            $txtRecordsByDelegation[$delegationKey]['tokens'][] = $token;
            $txtRecordsByDelegation[$delegationKey]['validationIndexes'][] = $index;

            // 标记已处理
            $item['auto_txt_written'] = true;
            $item['auto_txt_written_at'] = now()->toDateTimeString();
            $item['delegation_id'] = $delegation->id;
            $updatedValidation[$index] = $item;
            $hasChanges = true;
        }

        return [$txtRecordsByDelegation, $updatedValidation, $hasChanges];
    }

    /**
     * 检查是否所有TXT记录都已处理
     *
     * @param  array  $validation  验证数组
     * @return bool 是否所有记录都已处理
     */
    public function allTxtRecordsProcessed(array $validation): bool
    {
        foreach ($validation as $item) {
            if (! isset($item['auto_txt_written']) || $item['auto_txt_written'] !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * 拆分 host 为 prefix 和 zone
     *
     * 例如: _dnsauth.example.com => ['_dnsauth', 'example.com']
     *
     * @param  string  $host  完整主机名
     * @return array{0: string|null, 1: string|null} [prefix, zone]
     */
    protected function splitPrefixAndZone(string $host): array
    {
        $host = strtolower(DomainUtil::convertToUnicode($host));

        $parts = explode('.', $host);

        if (count($parts) < 3) {
            // 至少需要 3 部分：prefix.domain.tld
            return [null, null];
        }

        // 第一部分作为 prefix
        $prefix = array_shift($parts);

        // 剩余部分作为 zone
        $zone = implode('.', $parts);

        // 验证 prefix 是否为支持的类型（从 config 派生白名单，全 ca_map 驱动）
        if (! in_array($prefix, CnameDelegationService::supportedPrefixes(), true)) {
            return [null, null];
        }

        return [$prefix, $zone];
    }
}
