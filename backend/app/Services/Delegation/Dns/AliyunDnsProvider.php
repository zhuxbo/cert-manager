<?php

declare(strict_types=1);

namespace App\Services\Delegation\Dns;

use App\Services\Delegation\Sdk\Aliyun\AliyunRpcSigner;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AliyunDnsProvider implements DelegationDnsProvider
{
    private const int PAGE_SIZE = 500;

    private readonly string $domain;

    private readonly string $accessKeyId;

    private readonly string $accessKeySecret;

    private readonly PendingRequest $client;

    public function __construct(array $config)
    {
        $this->domain = strtolower(rtrim($this->requiredString($config, 'domain'), '.'));
        $this->accessKeyId = $this->requiredString($config, 'accessKeyId');
        $this->accessKeySecret = $this->requiredString($config, 'accessKeySecret');
        $this->client = Http::baseUrl('https://alidns.aliyuncs.com')
            ->acceptJson()
            ->timeout(15);
    }

    public function upsertTxt(string $name, array $values, int $ttl = 600): bool
    {
        if ($values === []) {
            return false;
        }

        $existingRecords = $this->listTxt($name);
        foreach (array_values(array_unique($values)) as $value) {
            $matchingRecords = array_values(array_filter(
                $existingRecords,
                fn (array $record): bool => $record['value'] === $value,
            ));
            $defaultRecords = array_values(array_filter(
                $matchingRecords,
                fn (array $record): bool => $record['line'] === 'default',
            ));
            if (in_array('Enable', array_column($defaultRecords, 'status'), true)) {
                continue;
            }

            $disabledDefaultRecords = array_filter(
                $defaultRecords,
                fn (array $record): bool => $record['status'] === 'Disable',
            );
            $this->deleteRecords(array_column($disabledDefaultRecords, 'id'));
            $payload = $this->request('AddDomainRecord', [
                'DomainName' => $this->domain,
                'RR' => $name,
                'Type' => 'TXT',
                'Value' => $value,
                'TTL' => $ttl,
            ]);
            $this->validateMutationResponse($payload);
        }

        return true;
    }

    public function allTxt(): array
    {
        return array_map(
            fn (array $record): array => [
                'id' => $record['id'],
                'name' => $record['name'],
                'value' => $record['value'],
                'changed_at' => $record['changed_at'],
            ],
            $this->listTxt(),
        );
    }

    public function deleteTxt(string $name): void
    {
        $this->deleteRecords(array_column($this->listTxt($name), 'id'));
    }

    public function deleteRecords(array $recordIds): void
    {
        foreach (array_values(array_unique($recordIds, SORT_REGULAR)) as $recordId) {
            if ((! is_int($recordId) && ! is_string($recordId))
                || preg_match('/^[1-9][0-9]*$/D', (string) $recordId) !== 1) {
                throw new InvalidArgumentException('Aliyun DNS 记录 ID 无效');
            }

            $normalizedId = (string) $recordId;
            $payload = $this->request('DeleteDomainRecord', [
                'RecordId' => $normalizedId,
            ]);
            $this->validateMutationResponse($payload, $normalizedId);
        }
    }

    private function listTxt(?string $name = null): array
    {
        $records = [];
        $page = 1;

        do {
            $parameters = [
                'DomainName' => $this->domain,
                'Type' => 'TXT',
                'PageNumber' => $page,
                'PageSize' => self::PAGE_SIZE,
            ];
            if ($name !== null) {
                $parameters['RRKeyWord'] = $name;
                $parameters['SearchMode'] = 'COMBINATION';
            }

            $payload = $this->request('DescribeDomainRecords', $parameters);
            $pageRecords = $this->validateListResponse($payload, $page);

            foreach ($pageRecords as $record) {
                if ($record['Type'] !== 'TXT'
                    || ($name !== null && $record['RR'] !== $name)) {
                    continue;
                }

                $records[] = [
                    'id' => $record['RecordId'],
                    'name' => $record['RR'],
                    'value' => $record['Value'],
                    'status' => $record['Status'],
                    'line' => $record['Line'],
                    'changed_at' => $this->timestamp($record['CreateTimestamp'] ?? null, $record['UpdateTimestamp'] ?? null),
                ];
            }

            $totalCount = $payload['TotalCount'];
            $page++;
        } while (($page - 1) * self::PAGE_SIZE < $totalCount);

        return $records;
    }

    private function validateMutationResponse(array $payload, ?string $expectedRecordId = null): void
    {
        if (! isset($payload['RequestId'], $payload['RecordId'])
            || ! is_string($payload['RequestId']) || trim($payload['RequestId']) === ''
            || ! is_string($payload['RecordId']) || trim($payload['RecordId']) === ''
            || ($expectedRecordId !== null && $payload['RecordId'] !== $expectedRecordId)) {
            throw new RuntimeException('Aliyun DNS 响应格式无效');
        }
    }

    /** @param array<string, scalar> $parameters */
    private function request(string $action, array $parameters): array
    {
        $parameters = array_merge([
            'Format' => 'JSON',
            'Version' => '2015-01-09',
            'AccessKeyId' => $this->accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => (string) Str::uuid(),
            'SignatureVersion' => '1.0',
            'Timestamp' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'Action' => $action,
        ], $parameters);
        $parameters['Signature'] = AliyunRpcSigner::sign($parameters, $this->accessKeySecret);

        try {
            $response = $this->client->get('/', $parameters);
        } catch (Throwable) {
            throw new RuntimeException('Aliyun DNS 请求失败');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Aliyun DNS 请求失败');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Aliyun DNS 响应格式无效');
        }
        if (isset($payload['Code'])) {
            throw new RuntimeException('Aliyun DNS 请求失败');
        }

        return $payload;
    }

    /** @return list<array{RecordId: string, RR: string, Type: string, Value: string, Status: string, Line: string, CreateTimestamp?: mixed, UpdateTimestamp?: mixed}> */
    private function validateListResponse(array $payload, int $expectedPage): array
    {
        $records = $payload['DomainRecords']['Record'] ?? null;
        if (! isset($payload['TotalCount'], $payload['PageNumber'], $payload['PageSize'], $payload['RequestId'])
            || ! is_int($payload['TotalCount']) || $payload['TotalCount'] < 0
            || ! is_int($payload['PageNumber']) || $payload['PageNumber'] !== $expectedPage
            || ! is_int($payload['PageSize']) || $payload['PageSize'] !== self::PAGE_SIZE
            || ! is_string($payload['RequestId']) || trim($payload['RequestId']) === ''
            || ! is_array($records)) {
            throw new RuntimeException('Aliyun DNS 响应格式无效');
        }

        foreach ($records as $record) {
            if (! is_array($record)
                || ! isset($record['RecordId'], $record['RR'], $record['Type'], $record['Value'], $record['Status'], $record['Line'])
                || ! is_string($record['RecordId']) || ! is_string($record['RR'])
                || ! is_string($record['Type']) || ! is_string($record['Value'])
                || ! is_string($record['Status'])
                || ! in_array($record['Status'], ['Enable', 'Disable'], true)
                || ! is_string($record['Line']) || trim($record['Line']) === '') {
                throw new RuntimeException('Aliyun DNS 响应格式无效');
            }
        }

        return $records;
    }

    private function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Aliyun DNS 配置不完整');
        }

        return trim($value);
    }

    private function timestamp(mixed $createdAt, mixed $updatedAt): ?int
    {
        $timestamps = array_filter(
            [$createdAt, $updatedAt],
            fn (mixed $value): bool => is_int($value) && $value > 0,
        );

        return $timestamps === [] ? null : intdiv(max($timestamps), 1000);
    }
}
