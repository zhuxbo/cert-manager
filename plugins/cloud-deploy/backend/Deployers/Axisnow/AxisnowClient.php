<?php

namespace Plugins\CloudDeploy\Deployers\Axisnow;

use GuzzleHttp\ClientInterface;

class AxisnowClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /** @return array<string,mixed> */
    public function listCertificates(int $page, int $perPage): array
    {
        return $this->request('GET', 'certificates', [
            'query' => ['page' => $page, 'per_page' => $perPage],
        ]);
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function addCertificate(array $body): array
    {
        $response = $this->request('POST', 'certificates', ['json' => $body]);
        $result = $response['result'] ?? [];

        return is_array($result) ? $result : [];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        $response = $this->http->request($method, $path, $options + ['http_errors' => false]);
        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $json = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            throw new AxisnowApiException('AxisnowRequestFailed', 'AxisNow 接口请求失败');
        }

        if (($json['success'] ?? false) !== true || ! empty($json['errors'])) {
            throw new AxisnowApiException('AxisnowApiError', 'AxisNow 接口返回错误');
        }

        return $json;
    }
}
