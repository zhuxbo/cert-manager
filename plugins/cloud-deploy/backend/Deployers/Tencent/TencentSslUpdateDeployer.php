<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertificateDeliveryMode;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Contracts\HasPollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\PersistsOpaqueInlineJobId;
use Plugins\CloudDeploy\Deployers\Contracts\PollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\SelectsCertificateDeliveryMode;
use RuntimeException;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\Models\DescribeHostUpdateRecordDetailRequest;
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
 * - is_replaced（默认 false）→ true 时保留原证书 ID，通过 UploadUpdateCertificateInstance 内联更新证书内容。
 */
class TencentSslUpdateDeployer extends AbstractDeployer implements HasPollBudget, PersistsOpaqueInlineJobId, SelectsCertificateDeliveryMode
{
    use UsesTencentEndpoint;

    /** 支持按地域过滤的云产品类型（对齐 certimate resourceProductsRequireRegion）。 */
    private const PRODUCTS_REQUIRE_REGION = ['apigateway', 'clb', 'cos', 'tcb', 'tke', 'tse', 'waf'];

    public const CLIENT_TIMEOUT_SECONDS = 10;

    protected int $maxPollAttempts = 1;

    protected int $resumePollAttempts = 3;

    protected int $pollIntervalSeconds = 5;

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
            ['key' => 'endpoint', 'label' => '接口端点（选填）', 'type' => 'string', 'required' => false, 'destination' => true],
            ['key' => 'certificate_id', 'label' => '待替换的旧云证书 ID', 'type' => 'string', 'required' => true],
            ['key' => 'resource_products', 'label' => '云产品类型列表（如 cdn/clb/cos/waf）', 'type' => 'string', 'required' => true],
            ['key' => 'resource_regions', 'label' => '云资源地域列表（部分产品需要，选填）', 'type' => 'string', 'required' => false],
            ['key' => 'is_replaced', 'label' => '更新原证书（保持证书 ID 不变）', 'type' => 'boolean', 'required' => false, 'default' => false],
        ];
    }

    public function certificateDeliveryMode(array $config): CertificateDeliveryMode
    {
        return ($config['is_replaced'] ?? false) === true
            ? CertificateDeliveryMode::Inline
            : CertificateDeliveryMode::RemoteStore;
    }

    public function canonicalOpaqueInlineJobId(string $remoteJobId): ?string
    {
        if ($remoteJobId === '' || preg_match('/^[0-9]+$/D', $remoteJobId) !== 1) {
            return null;
        }

        $canonical = ltrim($remoteJobId, '0');
        if ($canonical === '') {
            return null;
        }

        $max = (string) PHP_INT_MAX;
        if (strlen($canonical) > strlen($max)
            || (strlen($canonical) === strlen($max) && strcmp($canonical, $max) > 0)) {
            return null;
        }

        return $canonical;
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $this->withTencentEndpoint($credentials, $config)));
    }

    /**
     * @param  string  $certRef  remote_cert_id（**新**上传的 CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{certificate_id:string,resource_products:string|list<string>,resource_regions?:string|list<string>}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        $oldCertId = (string) $this->requireConfig($config, 'certificate_id');
        $products = $this->normalizeList($this->requireConfig($config, 'resource_products'));
        if ($products === []) {
            $this->fail('缺少配置 resource_products');
        }
        $regions = $this->normalizeList($config['resource_regions'] ?? '');

        /** @var SslClient $client */
        $client = $this->makeClient('ssl', $credentials);

        if (($config['is_replaced'] ?? false) === true) {
            $this->bindUploadUpdate($client, $oldCertId, $certRef, $products, $regions);

            return;
        }

        $response = $this->guardSdk(function () use ($client, $oldCertId, $certRef, $products, $regions) {
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

        $recordId = $response->getDeployRecordId();
        if ($recordId <= 0) {
            $this->fail('腾讯云一键更新未返回 DeployRecordId');
        }
        $this->pollUpdateRecord($client, (string) $recordId, $this->maxPollAttempts);
    }

    public function resumePoll(string $remoteJobId, array $credentials, array $config): void
    {
        $credentials = $this->withTencentEndpoint($credentials, $config);
        if (($config['is_replaced'] ?? false) === true) {
            $canonicalJobId = $this->canonicalOpaqueInlineJobId($remoteJobId);
            if ($canonicalJobId === null) {
                throw new DeployBusinessException('腾讯云上传更新证书任务 ID 无效');
            }
            /** @var SslClient $client */
            $client = $this->makeClient('ssl', $credentials);
            $this->pollUploadUpdateRecord($client, $canonicalJobId, $this->resumePollAttempts);

            return;
        }
        /** @var SslClient $client */
        $client = $this->makeClient('ssl', $credentials);
        $this->pollUpdateRecord($client, $remoteJobId, $this->resumePollAttempts);
    }

    public function pollBudget(): PollBudget
    {
        return new PollBudget(
            clientTimeoutSeconds: self::CLIENT_TIMEOUT_SECONDS,
            uploadCalls: 1,
            preIterCalls: 1,
            bindIterations: $this->maxPollAttempts,
            intervalSeconds: $this->pollIntervalSeconds,
        );
    }

    protected function pollUpdateRecord(SslClient $client, string $recordId, int $attempts): void
    {
        TencentDeployRecordPoller::poll(
            fn () => $this->guardSdk(function () use ($client, $recordId) {
                $request = new DescribeHostUpdateRecordDetailRequest;
                $request->deserialize(['DeployRecordId' => $recordId, 'Limit' => '200']);

                return $client->DescribeHostUpdateRecordDetail($request);
            }),
            $recordId,
            $attempts,
            $this->pollIntervalSeconds,
            fn (int $seconds) => $this->sleep($seconds),
        );
    }

    /**
     * @param  string|array{cert:string,key:string,chain:string}  $certRef
     * @param  list<string>  $products
     * @param  list<string>  $regions
     */
    private function bindUploadUpdate(SslClient $client, string $oldCertId, string|array $certRef, array $products, array $regions): void
    {
        $certPem = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';
        $chainPem = is_array($certRef) ? (string) ($certRef['chain'] ?? '') : '';
        $keyPem = is_array($certRef) ? (string) ($certRef['key'] ?? '') : '';
        if ($certPem === '' || $keyPem === '') {
            $this->fail('缺少内联证书材料');
        }

        $payload = [
            'OldCertificateId' => $oldCertId,
            'CertificatePublicKey' => rtrim($certPem)."\n".trim($chainPem),
            'CertificatePrivateKey' => $keyPem,
            'ResourceTypes' => $products,
        ];
        $resourceTypesRegions = $this->wrapResourceProductRegions($products, $regions);
        if ($resourceTypesRegions !== []) {
            $payload['ResourceTypesRegions'] = $resourceTypesRegions;
        }

        $response = $this->callSslJson(
            $client,
            'UploadUpdateCertificateInstance',
            $payload,
            '腾讯云上传更新证书任务调用失败',
            '腾讯云上传更新证书任务响应异常',
        );
        $recordId = $this->uploadUpdateRecordId($response);
        $this->pollUploadUpdateRecord($client, $recordId, $this->maxPollAttempts);
    }

    private function uploadUpdateRecordId(mixed $response): string
    {
        if (! is_array($response)
            || array_key_exists('Message', $response)
            || ($response['DeployStatus'] ?? null) !== 1
            || ! is_int($response['DeployRecordId'] ?? null)
            || $response['DeployRecordId'] <= 0) {
            throw new DeployBusinessException('腾讯云上传更新证书任务响应异常');
        }

        return (string) $response['DeployRecordId'];
    }

    protected function pollUploadUpdateRecord(SslClient $client, string $recordId, int $attempts): void
    {
        TencentUploadUpdateRecordPoller::poll(
            fn (): array => $this->callSslJson(
                $client,
                'DescribeHostUploadUpdateRecordDetail',
                ['DeployRecordId' => (int) $recordId, 'Limit' => 200],
                '腾讯云上传更新证书任务详情调用失败',
                '腾讯云上传更新证书任务详情响应异常',
            ),
            $recordId,
            $attempts,
            $this->pollIntervalSeconds,
            fn (int $seconds) => $this->sleep($seconds),
        );
    }

    /**
     * PHP SDK 未生成两个 UploadUpdate action 的模型；common-json 调用必须丢弃所有上游错误文本。
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function callSslJson(SslClient $client, string $action, array $payload, string $callFailure, string $responseFailure): array
    {
        try {
            $response = $client->callJson($action, json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new RuntimeException($callFailure, 0);
        }

        if (! is_array($response)
            || array_key_exists('Error', $response)
            || array_key_exists('Message', $response)
            || array_key_exists('Response', $response)) {
            throw new RuntimeException($responseFailure, 0);
        }

        return $response;
    }

    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
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
        $http->setReqTimeout(self::CLIENT_TIMEOUT_SECONDS);
        $this->configureTencentEndpoint($http, $credentials, $kind);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            'ssl' => new SslClient($cred, '', $profile),
            default => throw new \InvalidArgumentException("不支持的客户端类型: $kind"),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
