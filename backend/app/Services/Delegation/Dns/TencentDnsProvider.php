<?php

declare(strict_types=1);

namespace App\Services\Delegation\Dns;

use App\Services\Delegation\Sdk\TencentCloud\TencentCloudTc3Signer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

class TencentDnsProvider implements DelegationDnsProvider
{
    private const string DUPLICATE_RECORD_CODE = 'InvalidParameter.DomainRecordExist';

    private const string ENDPOINT = 'https://dnspod.tencentcloudapi.com';

    private const string HOST = 'dnspod.tencentcloudapi.com';

    private const int PAGE_LIMIT = 3000;

    private const string SERVICE = 'dnspod';

    private const string VERSION = '2021-03-23';

    private readonly PendingRequest $client;

    private readonly string $domain;

    private readonly string $secretId;

    private readonly string $secretKey;

    public function __construct(array $config)
    {
        $this->domain = strtolower(rtrim($this->requiredString($config, 'domain'), '.'));
        $this->secretId = $this->requiredString($config, 'secretId');
        $this->secretKey = $this->requiredString($config, 'secretKey');
        $this->client = Http::baseUrl(self::ENDPOINT)
            ->acceptJson()
            ->timeout(15);
    }

    public function upsertTxt(string $name, array $values, int $ttl = 600): bool
    {
        if ($values === []) {
            return false;
        }

        foreach (array_values(array_unique($values)) as $value) {
            $payload = $this->request('CreateTXTRecord', [
                'Domain' => $this->domain,
                'SubDomain' => $name,
                'RecordLine' => '默认',
                'Value' => $value,
                'TTL' => $ttl,
            ]);

            if ($this->errorCode($payload) === self::DUPLICATE_RECORD_CODE) {
                continue;
            }
            if ($this->errorCode($payload) !== null) {
                throw new RuntimeException('Tencent DNS 请求失败');
            }

            if (! $this->isPositiveRecordId($payload['RecordId'] ?? null)) {
                throw new RuntimeException('Tencent DNS 响应格式无效');
            }
        }

        return true;
    }

    public function allTxt(): array
    {
        return $this->listTxt();
    }

    public function deleteTxt(string $name): void
    {
        $this->deleteRecords(array_column($this->listTxt($name), 'id'));
    }

    public function deleteRecords(array $recordIds): void
    {
        foreach (array_values(array_unique($recordIds, SORT_REGULAR)) as $recordId) {
            if (! $this->isPositiveRecordId($recordId)) {
                throw new InvalidArgumentException('Tencent DNS 记录 ID 无效');
            }

            $payload = $this->request('DeleteRecord', [
                'Domain' => $this->domain,
                'RecordId' => (int) $recordId,
            ]);
            if ($this->errorCode($payload) !== null) {
                throw new RuntimeException('Tencent DNS 请求失败');
            }
        }
    }

    /** @return list<array{id: string, name: string, value: string, changed_at: int|null}> */
    private function listTxt(?string $name = null): array
    {
        $records = [];
        $offset = 0;

        do {
            $parameters = [
                'Domain' => $this->domain,
                'RecordType' => 'TXT',
                'ErrorOnEmpty' => 'no',
                'Offset' => $offset,
                'Limit' => self::PAGE_LIMIT,
            ];
            if ($name !== null) {
                $parameters['SubDomain'] = $name;
            }

            $payload = $this->request('DescribeRecordList', $parameters);
            $errorCode = $this->errorCode($payload);
            if ($errorCode === 'ResourceNotFound.NoDataOfRecord') {
                return $records;
            }
            if ($errorCode !== null) {
                throw new RuntimeException('Tencent DNS 请求失败');
            }

            $recordCountInfo = $payload['RecordCountInfo'] ?? null;
            $recordList = $payload['RecordList'] ?? null;
            $totalCount = is_array($recordCountInfo) ? ($recordCountInfo['TotalCount'] ?? null) : null;
            if (! is_int($totalCount) || $totalCount < 0 || ! is_array($recordList)) {
                throw new RuntimeException('Tencent DNS 响应格式无效');
            }

            foreach ($recordList as $record) {
                if (! is_array($record)
                    || ! $this->isPositiveRecordId($record['RecordId'] ?? null)
                    || ! is_string($record['Name'] ?? null)
                    || ! is_string($record['Value'] ?? null)
                    || ! is_string($record['Type'] ?? null)) {
                    throw new RuntimeException('Tencent DNS 响应格式无效');
                }
                if ($record['Type'] !== 'TXT'
                    || ($name !== null && $record['Name'] !== $name)) {
                    continue;
                }

                $records[] = [
                    'id' => (string) $record['RecordId'],
                    'name' => $record['Name'],
                    'value' => $record['Value'],
                    'changed_at' => $this->timestamp($record['UpdatedOn'] ?? null),
                ];
            }

            $offset += self::PAGE_LIMIT;
        } while ($offset < $totalCount);

        return $records;
    }

    /** @param array<string, scalar> $parameters */
    private function request(string $action, array $parameters): array
    {
        try {
            $body = json_encode(
                $parameters,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw new RuntimeException('Tencent DNS 请求失败');
        }

        $timestamp = now('UTC')->timestamp;
        $authorization = TencentCloudTc3Signer::authorization(
            secretId: $this->secretId,
            secretKey: $this->secretKey,
            host: self::HOST,
            service: self::SERVICE,
            timestamp: $timestamp,
            payload: $body,
        );

        try {
            $response = (clone $this->client)
                ->withHeaders([
                    'Authorization' => $authorization,
                    'Content-Type' => TencentCloudTc3Signer::CONTENT_TYPE,
                    'Host' => self::HOST,
                    'X-TC-Action' => $action,
                    'X-TC-Timestamp' => (string) $timestamp,
                    'X-TC-Version' => self::VERSION,
                ])
                ->withBody($body, TencentCloudTc3Signer::CONTENT_TYPE)
                ->post('/');
        } catch (Throwable) {
            throw new RuntimeException('Tencent DNS 请求失败');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Tencent DNS 请求失败');
        }

        $json = $response->json();
        $payload = is_array($json) ? ($json['Response'] ?? null) : null;
        if (! is_array($payload)
            || ! is_string($payload['RequestId'] ?? null)
            || trim($payload['RequestId']) === '') {
            throw new RuntimeException('Tencent DNS 响应格式无效');
        }

        if (array_key_exists('Error', $payload)) {
            $error = $payload['Error'];
            if (! is_array($error)
                || ! is_string($error['Code'] ?? null) || trim($error['Code']) === ''
                || ! is_string($error['Message'] ?? null) || trim($error['Message']) === '') {
                throw new RuntimeException('Tencent DNS 响应格式无效');
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function errorCode(array $payload): ?string
    {
        $error = $payload['Error'] ?? null;
        $code = is_array($error) ? ($error['Code'] ?? null) : null;

        return is_string($code) ? $code : null;
    }

    private function isPositiveRecordId(mixed $recordId): bool
    {
        return (is_int($recordId) || is_string($recordId))
            && preg_match('/^[1-9][0-9]*$/D', (string) $recordId) === 1;
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('app.timezone'))->timestamp;
        } catch (Throwable) {
            return null;
        }
    }

    private function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Tencent DNS 配置不完整');
        }

        return trim($value);
    }
}
