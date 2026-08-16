<?php

namespace Plugins\CloudDeploy\Deployers\Proxmoxbs;

use GuzzleHttp\ClientInterface;

class ProxmoxbsClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /** @param array<string,mixed> $body */
    public function nodeUploadCustomCertificate(string $node, array $body): void
    {
        $response = $this->http->request('POST', 'nodes/'.rawurlencode($node).'/certificates/custom', [
            'json' => $body,
            'http_errors' => false,
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new ProxmoxbsApiException('ProxmoxbsRequestFailed', 'Proxmox BS 接口请求失败');
        }

        $raw = (string) $response->getBody();
        if ($raw === '') {
            return;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (! is_array($decoded) || ! str_starts_with(ltrim($raw), '{')) {
            throw new ProxmoxbsApiException('ProxmoxbsInvalidResponse', 'Proxmox BS 接口返回无效响应');
        }
    }
}
