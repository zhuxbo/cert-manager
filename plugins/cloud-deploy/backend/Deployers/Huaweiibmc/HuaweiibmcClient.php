<?php

namespace Plugins\CloudDeploy\Deployers\Huaweiibmc;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class HuaweiibmcClient
{
    private string $token = '';

    private string $sessionLocation = '';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $baseUri,
    ) {}

    public function createSession(string $username, string $password): void
    {
        if ($this->token !== '') {
            throw new HuaweiibmcApiException('HuaweiibmcSessionExists', 'Redfish session 已存在');
        }

        $response = $this->http->request('POST', 'redfish/v1/SessionService/Sessions', [
            'json' => ['UserName' => $username, 'Password' => $password],
            'http_errors' => false,
        ]);
        $this->ensureSuccess($response);
        $this->decodeResponseObject($response);

        $token = $response->getHeaderLine('X-Auth-Token');
        if ($token === '') {
            throw new HuaweiibmcApiException('HuaweiibmcInvalidSession', 'Redfish session 未返回 token');
        }

        $location = $response->getHeaderLine('Location');
        $normalizedLocation = $this->sessionLocationPath($location);
        $this->token = $token;
        $this->sessionLocation = $normalizedLocation;
    }

    public function deleteSession(): void
    {
        if ($this->token === '') {
            return;
        }

        if ($this->sessionLocation !== '') {
            $this->request('DELETE', $this->sessionLocation);
        }

        $this->token = '';
        $this->sessionLocation = '';
    }

    /** @return list<array<string,mixed>> */
    public function listManagers(): array
    {
        $response = $this->request('GET', '/redfish/v1/Managers');
        $members = $response['Members'] ?? [];

        return is_array($members) ? array_values(array_filter($members, 'is_array')) : [];
    }

    /** @param array{Certificate:string,Password:string} $body */
    public function importCustomCertificate(string $managerLocation, array $body): void
    {
        $path = rtrim($this->sameOriginPath($managerLocation), '/')
            .'/SecurityService/HttpsCert/Actions/HttpsCert.ImportCustomCertificate';
        $this->request('POST', $path, $body, ['iBMC.1.0.CertImportOK']);
    }

    public function resetManager(string $managerLocation, string $resetType): void
    {
        $path = rtrim($this->sameOriginPath($managerLocation), '/').'/Actions/Manager.Reset';
        $this->request('POST', $path, ['ResetType' => $resetType], ['Base.1.0.Success']);
    }

    /**
     * @param  array<string,mixed>|null  $body
     * @param  list<string>  $specialSuccessMessageIds
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null, array $specialSuccessMessageIds = []): array
    {
        $options = [
            'http_errors' => false,
            'headers' => $this->token === '' ? [] : ['X-Auth-Token' => $this->token],
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $response = $this->http->request($method, ltrim($path, '/'), $options);
        $json = $this->decodeResponseObject($response, false);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $json ??= [];
            if ($this->hasSpecialSuccess($json, $specialSuccessMessageIds)) {
                return $json;
            }
            $this->ensureSuccess($response);
        }

        if ($json === null) {
            throw new HuaweiibmcApiException('HuaweiibmcInvalidResponse', 'Redfish 接口返回无效响应');
        }

        if (isset($json['error']) && ! $this->hasSpecialSuccess($json, $specialSuccessMessageIds)) {
            throw new HuaweiibmcApiException('HuaweiibmcApiError', 'Redfish 接口返回错误');
        }

        return $json;
    }

    /** @return array<string,mixed>|null null 表示非空响应不是合法 JSON 对象 */
    private function decodeResponseObject(ResponseInterface $response, bool $failInvalid = true): ?array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (! is_array($decoded) || ! str_starts_with(ltrim($raw), '{')) {
            if ($failInvalid) {
                throw new HuaweiibmcApiException('HuaweiibmcInvalidResponse', 'Redfish 接口返回无效响应');
            }

            return null;
        }

        return $decoded;
    }

    private function ensureSuccess(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new HuaweiibmcApiException('HuaweiibmcRequestFailed', 'Redfish 接口请求失败');
        }
    }

    /** @param array<string,mixed> $json @param list<string> $accepted */
    private function hasSpecialSuccess(array $json, array $accepted): bool
    {
        if ($accepted === []) {
            return false;
        }

        $error = $json['error'] ?? [];
        $extended = is_array($error) ? ($error['@Message.ExtendedInfo'] ?? []) : [];
        if (! is_array($extended)) {
            return false;
        }

        foreach ($extended as $item) {
            if (is_array($item) && in_array((string) ($item['MessageId'] ?? ''), $accepted, true)) {
                return true;
            }
        }

        return false;
    }

    private function sameOriginPath(string $location): string
    {
        $base = parse_url($this->baseUri);
        $target = parse_url($location);
        if (! is_array($base) || ! is_array($target)) {
            throw new RuntimeException('Redfish Location 无效');
        }

        if (isset($target['query']) || isset($target['fragment']) || isset($target['user']) || isset($target['pass'])) {
            throw new RuntimeException('Redfish Location 无效');
        }

        if (! str_contains($location, '://')) {
            return '/'.ltrim((string) ($target['path'] ?? ''), '/');
        }

        $baseScheme = strtolower((string) ($base['scheme'] ?? ''));
        $targetScheme = strtolower((string) ($target['scheme'] ?? ''));
        $baseHost = strtolower((string) ($base['host'] ?? ''));
        $targetHost = strtolower((string) ($target['host'] ?? ''));
        $basePort = (int) ($base['port'] ?? ($baseScheme === 'https' ? 443 : 80));
        $targetPort = (int) ($target['port'] ?? ($targetScheme === 'https' ? 443 : 80));
        if ($baseScheme !== $targetScheme || $baseHost !== $targetHost || $basePort !== $targetPort) {
            throw new RuntimeException('Redfish Location 跨源');
        }

        return '/'.ltrim((string) ($target['path'] ?? ''), '/');
    }

    private function sessionLocationPath(string $location): string
    {
        if (trim($location) === '') {
            throw new HuaweiibmcApiException('HuaweiibmcInvalidSessionLocation', 'Redfish session Location 为空');
        }

        $path = $this->sameOriginPath($location);
        if (preg_match('#^/redfish/v1/SessionService/Sessions/[A-Za-z0-9._~-]+$#D', $path) !== 1) {
            throw new HuaweiibmcApiException('HuaweiibmcInvalidSessionLocation', 'Redfish session Location 路径无效');
        }

        return $path;
    }
}
