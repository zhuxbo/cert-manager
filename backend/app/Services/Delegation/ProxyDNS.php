<?php

declare(strict_types=1);

namespace App\Services\Delegation;

use App\Services\Delegation\Sdk\TencentCloud\Common\Credential;
use App\Services\Delegation\Sdk\TencentCloud\Common\Profile\ClientProfile;
use App\Services\Delegation\Sdk\TencentCloud\Common\Profile\HttpProfile;
use App\Services\Delegation\Sdk\TencentCloud\Dnspod\V20210323\DnspodClient;
use App\Services\Delegation\Sdk\TencentCloud\Dnspod\V20210323\Models\CreateTXTRecordRequest;
use App\Services\Delegation\Sdk\TencentCloud\Dnspod\V20210323\Models\DeleteRecordBatchRequest;
use App\Services\Delegation\Sdk\TencentCloud\Dnspod\V20210323\Models\DeleteRecordRequest;
use App\Services\Delegation\Sdk\TencentCloud\Dnspod\V20210323\Models\DescribeRecordListRequest;
use App\Traits\ApiResponse;
use Exception;
use Log;

class ProxyDNS
{
    use ApiResponse;

    protected ?DnspodClient $client = null;

    protected ?string $domain = null;

    protected bool $initialized = false;

    /**
     * 初始化客户端配置
     */
    protected function initializeClient(): void
    {
        if ($this->initialized) {
            return;
        }

        $delegation = get_system_setting('site', 'delegation');

        if ($delegation === null) {
            $this->error('委托解析配置未设置');
        }

        $secretId = $delegation['secretId'] ?? null;
        $secretKey = $delegation['secretKey'] ?? null;
        $region = $delegation['region'] ?? 'ap-guangzhou';

        if (empty($secretId) || empty($secretKey)) {
            $this->error('委托解析配置不完整');
        }

        $cred = new Credential($secretId, $secretKey);
        // $this->client 是单例，这里设置的 reqTimeout 覆盖 DnspodClient 的所有调用方：
        // ① 委托写 TXT（下单 FPM 同步路径）② DelegationCleanupCommand（console 定时命令）的
        // getAllTxtRecords 分页查询。DNSPod SDK 默认 reqTimeout=60s 偏大（对齐工商查询 10s 量级）；
        // 收紧到 15s：① 场景防腾讯云慢/挂时单条占 FPM worker 最长 60s（仍在 DB 锁外，不引发 1205）；
        // ② 场景每页独立 15s（对正常腾讯云响应足够），console 场景腾讯云异常慢时可能提前失败导致
        // 本轮分页遍历中断（漏删，不误删——下轮 cleanup 补删）。
        $httpProfile = new HttpProfile;
        $httpProfile->setReqTimeout(15);
        $clientProfile = new ClientProfile;
        $clientProfile->setHttpProfile($httpProfile);
        $this->client = new DnspodClient($cred, $region, $clientProfile);

        // 获取代理域名
        $this->domain = $delegation['proxyZone'] ?? null;

        if (empty($this->domain)) {
            $this->error('代理域名未设置');
        }

        $this->initialized = true;
    }

    /**
     * 创建或更新 TXT 记录
     * 注意：每个值会创建一条独立的 TXT 记录，支持同名多值
     * 采用增量添加策略：只添加不存在的新值，不删除已有记录
     *
     * @param  string  $zone  域名
     * @param  string  $name  记录名
     * @param  array  $values  TXT 值数组
     * @param  int  $ttl  TTL
     */
    public function upsertTXT(string $zone, string $name, array $values, int $ttl = 600): bool
    {
        $this->initializeClient();

        if (empty($values)) {
            return false;
        }

        // 逐条创建记录，记录已存在时会被自动忽略
        foreach ($values as $value) {
            $isSuccess = $this->createTXTRecord($zone, $name, $value, $ttl);
            if (! $isSuccess) {
                return false;
            }
        }

        return true;
    }

    /**
     * 删除 TXT 记录
     */
    public function deleteTXT(string $zone, string $name): void
    {
        $this->initializeClient();

        $recordId = $this->findRecord($zone, $name, 'TXT');

        if (! $recordId) {
            return;
        }

        $req = new DeleteRecordRequest;
        $req->Domain = $zone;
        $req->RecordId = (int) $recordId;

        $this->client->DeleteRecord($req);
    }

    /**
     * 查找记录 ID
     */
    protected function findRecord(string $zone, string $name, string $type): ?string
    {
        $this->initializeClient();

        try {
            $req = new DescribeRecordListRequest;
            $req->Domain = $zone;
            $req->Subdomain = $name;
            $req->RecordType = $type;

            $resp = $this->client->DescribeRecordList($req);

            if (isset($resp->RecordList[0])) {
                return (string) $resp->RecordList[0]->RecordId;
            }

            return null;
        } catch (Exception) {
            // 查找失败返回 null，不抛出异常
            return null;
        }
    }

    /**
     * 创建单条记录
     * 如果记录已存在，会忽略错误并视为成功
     *
     * @param  string  $zone  域名
     * @param  string  $name  记录名
     * @param  string  $value  记录值
     * @param  int  $ttl  TTL
     */
    protected function createTXTRecord(string $zone, string $name, string $value, int $ttl): bool
    {
        $this->initializeClient();

        try {
            $req = new CreateTXTRecordRequest;
            $req->Domain = $zone;
            $req->SubDomain = $name;
            $req->RecordLine = '默认';
            $req->Value = $value;
            $req->TTL = $ttl;

            $this->client->CreateTXTRecord($req);

            return true;
        } catch (Exception $e) {
            // 如果是"记录已存在"的错误，忽略并视为成功
            if (str_contains($e->getMessage(), '记录已经存在') ||
                str_contains($e->getMessage(), 'already exists') ||
                str_contains($e->getMessage(), 'duplicate') ||
                str_contains($e->getMessage(), '无需再次添加')) {
                // 记录已存在，视为成功，不抛出异常
                return true;
            }

            // 其他错误记录日志
            Log::error('创建TXT记录失败', [
                'domain' => $zone,
                'name' => $name,
                'value' => $value,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * 批量删除记录（支持最多 2000 条）
     *
     * @param  array  $recordIds  记录 ID 数组
     */
    public function batchDeleteRecords(array $recordIds): void
    {
        $this->initializeClient();

        if (empty($recordIds)) {
            return;
        }

        // 腾讯云批量删除接口限制每次最多 2000 条
        $chunks = array_chunk($recordIds, 2000);

        foreach ($chunks as $chunk) {
            $req = new DeleteRecordBatchRequest;
            $req->RecordIdList = array_map('intval', $chunk);

            $this->client->DeleteRecordBatch($req);
        }
    }

    /**
     * 获取域名下所有 TXT 记录
     *
     * @param  string  $zone  域名
     * @return array 返回格式: [['id' => recordId, 'name' => subdomain, 'value' => value], ...]
     */
    public function getAllTxtRecords(string $zone): array
    {
        $this->initializeClient();

        try {
            $records = [];
            $offset = 0;
            $limit = 3000; // 腾讯云 API 单次最多返回 3000 条

            do {
                $req = new DescribeRecordListRequest;
                $req->Domain = $zone;
                $req->RecordType = 'TXT';
                $req->Offset = $offset;
                $req->Limit = $limit;

                $resp = $this->client->DescribeRecordList($req);

                foreach ($resp->RecordList as $record) {
                    $records[] = [
                        'id' => (string) $record->RecordId,
                        'name' => $record->Name,
                        'value' => $record->Value,
                    ];
                }

                $totalCount = $resp->RecordCountInfo->TotalCount;
                $offset += $limit;

                // 如果已经获取了所有记录，退出循环
                if ($offset >= $totalCount) {
                    break;
                }
            } while (true);

            return $records;
        } catch (Exception) {
            // 查询失败返回空数组
            return [];
        }
    }
}
