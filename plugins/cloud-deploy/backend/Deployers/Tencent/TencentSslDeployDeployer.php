<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Ssl\V20191205\Models\DeployCertificateInstanceRequest;
use TencentCloud\Ssl\V20191205\Models\DescribeHostDeployRecordDetailRequest;
use TencentCloud\Ssl\V20191205\SslClient;
use Throwable;

/**
 * 腾讯云一键部署（通用，证书服务型 + 一键部署）：证书先经 SSL 服务上传拿 CertificateId，
 * 再调 SSL **DeployCertificateInstance** 以 **config 指定的** ResourceType + InstanceIdList
 * 部署到任意腾讯云支持的资源类型（ddos/tke/cdn/clb/live/vod/waf/teo/cos/apigateway 等）。
 *
 * 这是「通用兜底」端点（对齐 certimate tencentcloud-ssl-deploy）——前面 cdn/clb/waf/... 是
 * 各产品专用绑定路径，本端点把腾讯 SSL 统一部署 API 直接暴露：用户填 resource_type +
 * instance_id_list 即可覆盖任何 DeployCertificateInstance 支持但插件未单独建端点的资源类型。
 *
 * config：
 * - resource_type（必填）→ ResourceType，腾讯资源类型枚举（cdn/clb/cos/ddos/live/teo/vod/waf/...）
 * - instance_id_list（必填）→ InstanceIdList，资源实例 ID 列表；接受数组或换行/逗号分隔字符串。
 *   各资源类型 InstanceId 格式不同（如 cos 为 `region|bucket|domain`、clb 为 listenerId），
 *   由用户按腾讯文档自行拼装，本端点不做格式假设（通用性优先）。
 * - region（选填）→ client region（certimate resourceRegion）。SSL 接口本身全局，但部分资源类型
 *   要求 client region 与资源地域一致；留空走全局。
 *
 * 异步处理：与 COS 同——DeployCertificateInstance 返回 DeployRecordId，pollDeployRecord()
 * 有界轮询 DescribeHostDeployRecordDetail 直到终态（复用 TencentDeployRecordPoller）。
 * Status 固定 1（启用）。
 */
class TencentSslDeployDeployer extends AbstractDeployer
{
    /** 轮询部署任务最大次数。 */
    protected int $maxPollAttempts = 30;

    /** 每次轮询间隔秒数（测试子类置 0）。 */
    protected int $pollIntervalSeconds = 10;

    public function provider(): string
    {
        return 'tencent';
    }

    public function product(): string
    {
        return 'ssl-deploy';
    }

    public function label(): string
    {
        return '腾讯云一键部署（通用）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'resource_type', 'label' => '云资源类型（如 cdn/clb/cos/ddos/live/teo/vod/waf）', 'type' => 'string', 'required' => true],
            ['key' => 'instance_id_list', 'label' => '资源实例 ID 列表（换行或逗号分隔）', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '云资源地域（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // 腾讯 SSL 上传是全局服务（空 region）
        return new TencentSslUploader(fn (array $credentials): object => $this->makeClient('ssl', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertificateId）
     * @param  array{secret_id:string,secret_key:string}  $credentials
     * @param  array{resource_type:string,instance_id_list:string|list<string>,region?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $resourceType = (string) $this->requireConfig($config, 'resource_type');
        $instanceIdList = $this->normalizeInstanceIdList($this->requireConfig($config, 'instance_id_list'));
        if ($instanceIdList === []) {
            $this->fail('缺少配置 instance_id_list');
        }
        // region 选填：client region（certimate resourceRegion，omitempty）
        $region = (string) ($config['region'] ?? '');

        /** @var SslClient $client */
        $client = $this->makeClient('ssl', $credentials, $region);

        // SDK 调用单独 guardSdk（异常脱敏）；返回的 recordId 用于后续轮询
        $recordId = $this->guardSdk(function () use ($client, $resourceType, $instanceIdList, $certRef) {
            $req = new DeployCertificateInstanceRequest;
            $req->deserialize([
                'CertificateId' => $certRef,
                'ResourceType' => $resourceType,
                'InstanceIdList' => $instanceIdList,
                'Status' => 1,
            ]);

            return $client->DeployCertificateInstance($req)->getDeployRecordId();
        });

        if ($recordId === null || $recordId === '') {
            return;
        }

        // 轮询在 guardSdk 之外：终态/失败判定的业务错误需透传（不被 sanitizer 吞）
        $this->pollDeployRecord($client, (string) $recordId);
    }

    /**
     * 把 instance_id_list 归一为非空字符串数组：数组原样过滤空项；字符串按换行/逗号/分号切分。
     *
     * @return list<string>
     */
    protected function normalizeInstanceIdList(mixed $raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } else {
            // 换行 / 逗号 / 中文逗号 / 分号 任一为分隔符（用户多渠道粘贴容错）
            $items = preg_split('/[\r\n,，;]+/u', (string) $raw) ?: [];
        }

        $out = [];
        foreach ($items as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 每次 DescribeHostDeployRecordDetail 单独 guardSdk 脱敏，终态判定的业务错误由 poller 在 guard 外抛。
     *
     * @param  SslClient  $client
     */
    protected function pollDeployRecord(object $client, string $recordId): void
    {
        TencentDeployRecordPoller::poll(
            fn () => $this->guardSdk(function () use ($client, $recordId) {
                $req = new DescribeHostDeployRecordDetailRequest;
                $req->deserialize(['DeployRecordId' => $recordId, 'Limit' => 200]);

                return $client->DescribeHostDeployRecordDetail($req);
            }),
            $this->maxPollAttempts,
            $this->pollIntervalSeconds,
            fn (int $seconds) => $this->sleep($seconds),
        );
    }

    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function makeClient(string $kind, array $credentials, string $region = ''): object
    {
        $cred = new Credential($credentials['secret_id'] ?? '', $credentials['secret_key'] ?? '');
        $http = new HttpProfile;
        $http->setReqTimeout(15);
        $profile = new ClientProfile;
        $profile->setHttpProfile($http);

        return match ($kind) {
            // certimate ssl-deploy 把 resourceRegion 作为 client region 传入（部分资源类型要求一致）
            'ssl' => new SslClient($cred, $region, $profile),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return TencentErrorSanitizer::sanitize($e);
    }
}
