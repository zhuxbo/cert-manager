<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use GuzzleHttp\ClientInterface;
use TencentCloud\Common\Exception\TencentCloudSDKException;

/** EdgeOne Pages Makers API 薄客户端。 */
class TencentEoMakersClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /** @return list<array{domain:string,zone_id:string}> */
    public function listCustomDomains(string $projectId): array
    {
        $response = $this->http->request('POST', '', [
            'http_errors' => false,
            'json' => [
                'Action' => 'DescribePagesZoneCustomDomains',
                'ProjectId' => $projectId,
            ],
        ]);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new TencentCloudSDKException('MakersHttpError', 'EdgeOne Makers 接口请求失败');
        }
        try {
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new TencentCloudSDKException('MalformedResponse', 'EdgeOne Makers 接口响应格式错误');
        }
        if (! is_array($json) || ! str_starts_with(ltrim($raw), '{') || ! array_key_exists('Code', $json) || ! is_int($json['Code'])) {
            throw new TencentCloudSDKException('MalformedResponse', 'EdgeOne Makers 接口响应格式错误');
        }
        if ($json['Code'] !== 0) {
            throw new TencentCloudSDKException('MakersApiError', 'EdgeOne Makers 接口返回业务错误');
        }

        $data = $json['Data'] ?? null;
        $innerResponse = is_array($data) ? ($data['Response'] ?? null) : null;
        $items = is_array($innerResponse) ? ($innerResponse['PagesDomains'] ?? null) : null;
        if (! is_array($items)) {
            throw new TencentCloudSDKException('MalformedResponse', 'EdgeOne Makers 接口响应格式错误');
        }
        $domains = [];
        foreach ($items as $item) {
            if (! is_array($item) || ($item['Type'] ?? '') !== 'Custom') {
                continue;
            }
            $domain = is_string($item['Domain'] ?? null) ? $item['Domain'] : '';
            $zoneId = is_string($item['ZoneId'] ?? null) ? $item['ZoneId'] : '';
            if ($domain !== '' && $zoneId !== '') {
                $domains[] = ['domain' => $domain, 'zone_id' => $zoneId];
            }
        }

        return $domains;
    }
}
