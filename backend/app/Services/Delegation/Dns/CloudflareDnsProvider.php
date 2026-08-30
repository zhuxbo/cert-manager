<?php

declare(strict_types=1);

namespace App\Services\Delegation\Dns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class CloudflareDnsProvider implements DelegationDnsProvider
{
    private const int PER_PAGE = 100;

    private readonly string $domain;

    private readonly string $zoneId;

    private readonly PendingRequest $client;

    public function __construct(array $config)
    {
        $this->domain = $this->requiredString($config, 'domain');
        $this->zoneId = $this->requiredString($config, 'zoneId');
        $token = $this->requiredString($config, 'apiToken');

        $this->client = Http::baseUrl('https://api.cloudflare.com/client/v4')
            ->withToken($token)
            ->acceptJson()
            ->timeout(15);
    }

    public function upsertTxt(string $name, array $values, int $ttl = 600): bool
    {
        $fqdn = $this->fqdn($name);
        $existingValues = array_column($this->listTxt($fqdn), 'value');
        $missingValues = array_diff(array_values(array_unique($values)), $existingValues);

        foreach ($missingValues as $value) {
            $response = $this->request(fn () => $this->client->post($this->recordsPath(), [
                'type' => 'TXT',
                'name' => $fqdn,
                'content' => $value,
                'ttl' => $ttl,
                'proxied' => false,
            ]));
            $this->validateMutationResponse($response);
        }

        return true;
    }

    public function allTxt(): array
    {
        return $this->listTxt();
    }

    public function deleteTxt(string $name): void
    {
        $recordIds = array_column($this->listTxt($this->fqdn($name)), 'id');
        $this->deleteRecords($recordIds);
    }

    public function deleteRecords(array $recordIds): void
    {
        foreach (array_values(array_unique($recordIds)) as $recordId) {
            if (! is_string($recordId) && ! is_int($recordId)) {
                throw new InvalidArgumentException('Cloudflare DNS 记录 ID 无效');
            }

            $response = $this->request(
                fn () => $this->client->delete($this->recordsPath().'/'.rawurlencode((string) $recordId))
            );
            $this->validateMutationResponse($response);
        }
    }

    private function listTxt(?string $fqdn = null): array
    {
        $records = [];
        $page = 1;

        do {
            $query = ['type' => 'TXT'];
            if ($fqdn !== null) {
                $query['name'] = $fqdn;
            }
            $query['page'] = $page;
            $query['per_page'] = self::PER_PAGE;

            $response = $this->request(fn () => $this->client->get($this->recordsPath(), $query));
            $payload = $this->validateListResponse($response, $page);

            foreach ($payload['result'] as $record) {
                if ($record['type'] !== 'TXT') {
                    continue;
                }
                if ($fqdn !== null && $record['name'] !== $fqdn) {
                    continue;
                }

                $relativeName = $this->relativeName($record['name']);
                if ($relativeName === null) {
                    continue;
                }

                $records[] = [
                    'id' => $record['id'],
                    'name' => $relativeName,
                    'value' => $record['content'],
                ];
            }

            $totalPages = $payload['result_info']['total_pages'];
            $page++;
        } while ($page <= $totalPages);

        return $records;
    }

    private function request(callable $request): Response
    {
        try {
            $response = $request();
        } catch (Throwable) {
            throw new RuntimeException('Cloudflare DNS 请求失败');
        }

        if (! $response instanceof Response || ! $response->successful()) {
            throw new RuntimeException('Cloudflare DNS 请求失败');
        }

        return $response;
    }

    private function validateListResponse(Response $response, int $expectedPage): array
    {
        $payload = $response->json();
        if (! is_array($payload) || ($payload['success'] ?? null) !== true) {
            throw new RuntimeException('Cloudflare DNS 请求失败');
        }

        $result = $payload['result'] ?? null;
        $resultInfo = $payload['result_info'] ?? null;
        if (! is_array($result) || ! is_array($resultInfo)
            || ! isset($resultInfo['page'], $resultInfo['total_pages'])
            || ! is_int($resultInfo['page']) || ! is_int($resultInfo['total_pages'])
            || $resultInfo['page'] !== $expectedPage
            || $resultInfo['total_pages'] < $expectedPage) {
            throw new RuntimeException('Cloudflare DNS 响应格式无效');
        }

        foreach ($result as $record) {
            if (! is_array($record)
                || ! isset($record['id'], $record['name'], $record['type'], $record['content'])
                || ! is_string($record['id']) || ! is_string($record['name'])
                || ! is_string($record['type']) || ! is_string($record['content'])) {
                throw new RuntimeException('Cloudflare DNS 响应格式无效');
            }
        }

        return $payload;
    }

    private function validateMutationResponse(Response $response): void
    {
        $payload = $response->json();
        if (! is_array($payload) || ($payload['success'] ?? null) !== true) {
            throw new RuntimeException('Cloudflare DNS 请求失败');
        }

        if (! isset($payload['result']) || ! is_array($payload['result'])
            || ! isset($payload['result']['id']) || ! is_string($payload['result']['id'])) {
            throw new RuntimeException('Cloudflare DNS 响应格式无效');
        }
    }

    private function recordsPath(): string
    {
        return 'zones/'.rawurlencode($this->zoneId).'/dns_records';
    }

    private function fqdn(string $name): string
    {
        $name = strtolower(rtrim(trim($name), '.'));

        return $name === '@' || $name === '' ? $this->domain : $name.'.'.$this->domain;
    }

    private function relativeName(string $fqdn): ?string
    {
        if ($fqdn === $this->domain) {
            return '@';
        }

        $suffix = '.'.$this->domain;
        if (! str_ends_with($fqdn, $suffix)) {
            return null;
        }

        return substr($fqdn, 0, -strlen($suffix));
    }

    private function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Cloudflare DNS 配置不完整');
        }

        return $key === 'domain' ? strtolower(rtrim(trim($value), '.')) : trim($value);
    }
}
