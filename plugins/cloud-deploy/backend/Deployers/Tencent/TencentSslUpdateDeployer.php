<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\Models\UpdateCertificateInstanceRequest;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云 SSL 证书一键更新（证书服务型）。
 *
 * 对齐 certimate tencentcloud-ssl-update（主路径 UpdateCertificateInstance）：证书先经 SSL 上传拿**新**
 * CertificateId（走 RemoteCertStore 去重），再调 ssl.UpdateCertificateInstance 以 OldCertificateId（旧证书）
 * + CertificateId（新证书）+ ResourceTypes（云产品列表）一键把所有引用旧证书的云资源切换到新证书。
 *
 * config：
 * - certificate_id（必填）→ OldCertificateId，待替换的旧云证书 ID。
 * - resource_products（必填）→ ResourceTypes，云产品类型列表（cdn/clb/cos/waf/...），换行/逗号分隔。
 * - resource_regions（选填）→ 为需要地域的产品（apigateway/clb/cos/tcb/tke/tse/waf）构造 ResourceTypesRegions。
 *
 * 简化：不实现 certimate 的 isReplaced（UploadUpdateCertificateInstance 直传 PEM 路径，与 upload-first 模型
 * 不契合）与完成度轮询（DescribeHostUpdateRecordDetail）——UpdateCertificateInstance 调用即触发切换。
 */
class TencentSslUpdateDeployer extends AbstractDeployer
{
    /** 支持按地域过滤的云产品类型（对齐 certimate resourceProductsRequireRegion）。 */
    private const PRODUCTS_REQUIRE_REGION = ['apigateway', 'clb', 'cos', 'tcb', 'tke', 'tse', 'waf'];

    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'ssl-update';
    }

    public function label(): string
    {
        return '腾讯云 SSL 证书一键更新';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => '待替换的旧云证书 ID', 'type' => 'string', 'required' => true],
            ['key' => 'resource_products', 'label' => '云产品类型列表（如 cdn/clb/cos/waf）', 'type' => 'string', 'required' => true],
            ['key' => 'resource_regions', 'label' => '云资源地域列表（部分产品需要，选填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（**新**上传的 CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{certificate_id:string,resource_products:string|list<string>,resource_regions?:string|list<string>}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $oldCertId = (string) $this->requireConfig($config, 'certificate_id');
        $products = $this->normalizeList($this->requireConfig($config, 'resource_products'));
        if ($products === []) {
            $this->fail('缺少配置 resource_products');
        }
        $regions = $this->normalizeList($config['resource_regions'] ?? '');

        /** @var SslClient $client */
        $client = $this->makeClient('ssl', $credentials);

        $this->guardSdk(function () use ($client, $oldCertId, $certRef, $products, $regions) {
            $payload = [
                'OldCertificateId' => $oldCertId,
                'CertificateId' => (string) $certRef,
                'ResourceTypes' => $products,
            ];
            $resourceTypesRegions = $this->wrapResourceProductRegions($products, $regions);
            if ($resourceTypesRegions !== []) {
                $payload['ResourceTypesRegions'] = $resourceTypesRegions;
            }

            $req = new UpdateCertificateInstanceRequest;
            $req->deserialize($payload);

            return $client->UpdateCertificateInstance($req);
        });
    }

    /**
     * 为支持地域的云产品构造 ResourceTypesRegions（对齐 certimate wrapResourceProductRegions）。
     *
     * @param  list<string>  $products
     * @param  list<string>  $regions
     * @return list<array{ResourceType:string,Regions:list<string>}>
     */
    protected function wrapResourceProductRegions(array $products, array $regions): array
    {
        if ($products === [] || $regions === []) {
            return [];
        }

        $out = [];
        foreach ($products as $product) {
            if (in_array($product, self::PRODUCTS_REQUIRE_REGION, true)) {
                $out[] = ['ResourceType' => $product, 'Regions' => $regions];
            }
        }

        return $out;
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
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
