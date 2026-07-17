<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\SslClient;
use TencentCloud\Tse\V20201207\Models\CreateCloudNativeAPIGatewayCertificateRequest;
use TencentCloud\Tse\V20201207\Models\ModifyCloudNativeAPIGatewayCertificateRequest;
use TencentCloud\Tse\V20201207\TseClient;
use Throwable;

/**
 * 腾讯云 TSE 云原生网关（内联型）。
 *
 * 对齐 certimate tencentcloud-tse（服务类型=云原生网关 cloudnative）：
 * - **新建**（未填 certificate_id）：先 ssl.UploadCertificate 拿 CertId，再 tse.CreateCloudNativeAPIGatewayCertificate
 *   以 GatewayId + CertId + BindDomains 在网关上创建证书。BindDomains 优先用 config.domains，留空则取证书 SAN。
 * - **更新**（填了 certificate_id）：tse.ModifyCloudNativeAPIGatewayCertificate 以 GatewayId + Id（网关证书 ID）
 *   + Crt/Key（直灌 PEM）+ CertSource=native 更新已有网关证书内容。
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} PEM 三元组；新建路径内部自行上传到 SSL
 * （复用 TencentSslUploader），不走 RemoteCertStore（网关证书与 SSL 证书是两层，去重语义不同）。
 *
 * config：region（必填，TSE 地域）/ gateway_id（必填，云原生网关 ID）/ domains（选填，绑定域名，留空取证书 SAN）/
 * certificate_id（选填，填则走更新已有网关证书）。
 */
class TencentTseDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'tse';
    }

    public function label(): string
    {
        return '腾讯云 TSE 云原生网关';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => true],
            ['key' => 'gateway_id', 'label' => '云原生网关 ID', 'type' => 'string', 'required' => true],
            ['key' => 'domains', 'label' => '绑定域名（选填，留空取证书 SAN）', 'type' => 'string', 'required' => false],
            ['key' => 'certificate_id', 'label' => '网关证书 ID（选填，填则更新已有证书）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{region:string,gateway_id:string,domains?:string|list<string>,certificate_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $region = (string) $this->requireConfig($config, 'region');
        $gatewayId = (string) $this->requireConfig($config, 'gateway_id');
        $certificateId = isset($config['certificate_id']) ? (string) $config['certificate_id'] : '';
        $fullChain = rtrim($certRef['cert'])."\n".trim($certRef['chain']);
        $key = $certRef['key'];

        if ($certificateId === '') {
            // 新建：先上传到 SSL 拿 CertId
            $uploader = new TencentSslUploader(fn (array $cred): object => $this->makeClient('ssl', $cred));
            $certId = $uploader->upload($certRef['cert'], $key, $certRef['chain'], $credentials);

            $domains = $this->normalizeList($config['domains'] ?? '');
            if ($domains === []) {
                $domains = $this->parseCertSans($certRef['cert']);
            }

            $this->guardSdk(function () use ($credentials, $region, $gatewayId, $certId, $domains) {
                /** @var TseClient $client */
                $client = $this->makeClient('tse', $credentials + ['region' => $region]);
                $req = new CreateCloudNativeAPIGatewayCertificateRequest;
                $req->deserialize([
                    'GatewayId' => $gatewayId,
                    'Name' => 'clouddeploy_'.(int) (microtime(true) * 1000),
                    'CertId' => $certId,
                    'BindDomains' => $domains,
                ]);

                return $client->CreateCloudNativeAPIGatewayCertificate($req);
            });
        } else {
            // 更新：直灌 PEM 到已有网关证书
            $this->guardSdk(function () use ($credentials, $region, $gatewayId, $certificateId, $fullChain, $key) {
                /** @var TseClient $client */
                $client = $this->makeClient('tse', $credentials + ['region' => $region]);
                $req = new ModifyCloudNativeAPIGatewayCertificateRequest;
                $req->deserialize([
                    'GatewayId' => $gatewayId,
                    'Id' => $certificateId,
                    'Crt' => $fullChain,
                    'Key' => $key,
                    'CertSource' => 'native',
                ]);

                return $client->ModifyCloudNativeAPIGatewayCertificate($req);
            });
        }
    }

    /**
     * 解析证书 SAN（subjectAltName）的 DNS 条目；解析失败返回空数组。
     *
     * @return list<string>
     */
    protected function parseCertSans(string $certPem): array
    {
        $parsed = @openssl_x509_parse($certPem);
        $san = $parsed['extensions']['subjectAltName'] ?? '';
        if (! is_string($san) || $san === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $out[] = substr($entry, 4);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    protected function normalizeList(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : (preg_split('/[\r\n,，;；]+/u', (string) $raw) ?: []);
        $out = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            'tse' => new TseClient($cred, (string) ($credentials['region'] ?? ''), $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
