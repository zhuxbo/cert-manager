<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

use Closure;
use GuzzleHttp\ClientInterface;
use Throwable;

class YandexcloudClient
{
    private readonly Closure $sleeper;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly ClientInterface $operationsHttp,
        ?callable $sleeper = null,
        private readonly int $maxPollAttempts = 60,
    ) {
        $this->sleeper = Closure::fromCallable($sleeper ?? static fn (int $milliseconds) => usleep($milliseconds * 1000));
    }

    /** @return array{certificates:list<array<string,mixed>>,nextPageToken:string} */
    public function listCertificates(string $folderId, string $pageToken = ''): array
    {
        $query = [
            'folderId' => $folderId,
            'pageSize' => 100,
            'view' => 'BASIC',
        ];
        if ($pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $result = $this->request($this->http, 'GET', 'certificate-manager/v1/certificates', ['query' => $query]);

        return [
            'certificates' => is_array($result['certificates'] ?? null) ? array_values($result['certificates']) : [],
            'nextPageToken' => (string) ($result['nextPageToken'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public function getCertificate(string $certificateId): array
    {
        return $this->request(
            $this->http,
            'GET',
            'certificate-manager/v1/certificates/'.rawurlencode($certificateId),
            ['query' => ['view' => 'BASIC']],
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createCertificate(array $body): array
    {
        return $this->waitOperation($this->request(
            $this->http,
            'POST',
            'certificate-manager/v1/certificates',
            ['json' => $body],
        ));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function updateCertificate(string $certificateId, array $body): array
    {
        return $this->waitOperation($this->request(
            $this->http,
            'PATCH',
            'certificate-manager/v1/certificates/'.rawurlencode($certificateId),
            ['json' => $body],
        ));
    }

    /** @param array<string,mixed> $operation @return array<string,mixed> */
    private function waitOperation(array $operation): array
    {
        for ($attempt = 0; $attempt <= $this->maxPollAttempts; $attempt++) {
            if (($operation['done'] ?? false) === true) {
                if (is_array($operation['error'] ?? null)) {
                    if (! array_key_exists('code', $operation['error'])) {
                        throw new YandexcloudApiException('OperationError');
                    }

                    throw YandexcloudApiException::fromStatus($operation['error']['code']);
                }

                return is_array($operation['response'] ?? null) ? $operation['response'] : [];
            }

            if ($attempt === $this->maxPollAttempts) {
                break;
            }

            $operationId = trim((string) ($operation['id'] ?? ''));
            if ($operationId === '') {
                throw new YandexcloudApiException('InvalidResponse');
            }
            ($this->sleeper)(1000);
            $operation = $this->request(
                $this->operationsHttp,
                'GET',
                'operations/'.rawurlencode($operationId),
            );
        }

        throw new YandexcloudApiException('OperationTimeout');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function request(ClientInterface $client, string $method, string $uri, array $options = []): array
    {
        $options['http_errors'] = false;
        try {
            $response = $client->request($method, $uri, $options);
        } catch (Throwable) {
            throw new YandexcloudApiException('HttpError');
        }

        $status = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $decoded = json_decode($rawBody);
        if (! is_object($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new YandexcloudApiException('InvalidResponse');
        }
        $body = json_decode($rawBody, true);
        if (! is_array($body)) {
            throw new YandexcloudApiException('InvalidResponse');
        }
        if ($status < 200 || $status >= 300) {
            throw YandexcloudApiException::fromStatus($body['code'] ?? $status);
        }
        if (($decoded->done ?? false) === true) {
            $hasError = property_exists($decoded, 'error');
            $hasResponse = property_exists($decoded, 'response');
            if ($hasError === $hasResponse
                || ($hasError && ! is_object($decoded->error))
                || ($hasResponse && ! is_object($decoded->response))) {
                throw new YandexcloudApiException('InvalidResponse');
            }
        }

        return $body;
    }
}
