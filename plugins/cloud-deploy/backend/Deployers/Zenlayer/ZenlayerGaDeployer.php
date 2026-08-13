<?php

namespace Plugins\CloudDeploy\Deployers\Zenlayer;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\HasPollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\PollBudget;
use Throwable;

/**
 * Zenlayer 全球网络加速 ZGA（证书服务型）：证书先经 ZGA 证书服务上传拿 certificateId（走 RemoteCertStore 去重），
 * 再绑定到加速器。
 *
 * 对齐 certimate zenlayer-ga 的 deployToAccelerator（DEPLOY_TARGET_ACCELERATOR）：
 *   1. 证书经 ZenlayerCertUploader 上传（CreateCertificate，service=zga）拿 certificateId（store_kind=zenlayer_zga）。
 *   2. DescribeAccelerators（acceleratorIds=[config.accelerator_id]）查加速器；若既有证书非该证书则
 *      ModifyAcceleratorCertificate 绑定。
 *   3. 轮询 DescribeAccelerators 的 acceleratorStatus 直到 Accelerating（NotAccelerate/StopAccelerate/
 *      AccelerateFailure 报错）。
 *
 * 与 certimate 对齐的取舍：certimate 支持 DEPLOY_TARGET_ACCELERATOR + DEPLOY_TARGET_CERTIFICATE。本端点
 * **仅实现 DEPLOY_TARGET_ACCELERATOR**（accelerator_id 必填），与插件其他端点单目标口径一致。轮询走 sleep()
 * 注入缝（测试 no-op），上限 30 次。
 */
class ZenlayerGaDeployer extends AbstractDeployer implements HasPollBudget
{
    /** bind 轮询 acceleratorStatus 次数（G2 压窗）：状态轮询型，超窗抛 DeployTimeout（guardSdk 内重包装为可重试
     * RuntimeException），重试/sweep 自续观察同一加速器收敛，无需 jobId 续查。 */
    protected int $maxPollAttempts = 1;

    /** 每次轮询间隔秒数（测试子类置 0）。 */
    protected int $pollIntervalSeconds = 5;

    public function provider(): string
    {
        return 'zenlayer';
    }

    public function product(): string
    {
        return 'ga';
    }

    public function label(): string
    {
        return 'Zenlayer 全球加速 ZGA';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'deploy_target', 'label' => '部署目标（accelerator/certificate）', 'type' => 'string', 'required' => false],
            ['key' => 'accelerator_id', 'label' => '加速器 ID', 'type' => 'string', 'required' => false],
            ['key' => 'certificate_id', 'label' => '证书 ID（certificate 目标）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new ZenlayerCertUploader(
            fn (array $credentials): object => $this->makeClient('zga', $credentials),
            'zenlayer_zga',
            (string) (($config['deploy_target'] ?? 'accelerator') === 'certificate' ? ($config['certificate_id'] ?? '') : ''),
        );
    }

    /**
     * @param  string  $certRef  remote_cert_id（Zenlayer ZGA certificateId）
     * @param  array{access_key_id:string,access_key_password:string}  $credentials
     * @param  array{accelerator_id:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $deployTarget = (string) ($config['deploy_target'] ?? 'accelerator');
        if ($deployTarget === 'certificate') {
            $this->requireConfig($config, 'certificate_id');

            return;
        }
        if ($deployTarget !== 'accelerator') {
            $this->fail("不支持的部署目标: $deployTarget");
        }
        $acceleratorId = (string) $this->requireConfig($config, 'accelerator_id');
        $certId = (string) $certRef;

        $this->guardSdk(function () use ($credentials, $acceleratorId, $certId) {
            /** @var ZenlayerRestClient $client */
            $client = $this->makeClient('zga', $credentials);

            // 查询加速器信息
            $descResp = $client->call('DescribeAccelerators', [
                'acceleratorIds' => [$acceleratorId],
                'pageNum' => 1,
                'pageSize' => 1,
            ]);
            $dataSet = is_array($descResp['dataSet'] ?? null) ? $descResp['dataSet'] : [];
            if ($dataSet === []) {
                throw new ZenlayerApiException('AcceleratorNotFound', "未找到 Zenlayer 加速器: $acceleratorId");
            }

            // 若既有证书非目标证书，则修改加速器证书
            $certificate = is_array($dataSet[0]['certificate'] ?? null) ? $dataSet[0]['certificate'] : [];
            if (($certificate['certificateId'] ?? null) !== $certId) {
                $client->call('ModifyAcceleratorCertificate', ['acceleratorId' => $acceleratorId, 'certificateId' => $certId]);
            }

            // 轮询加速器状态到 Accelerating
            $this->pollAcceleratorStatus($client, $acceleratorId);
        });
    }

    /**
     * 轮询加速器状态：Accelerating 成功 / NotAccelerate|StopAccelerate|AccelerateFailure 报错。
     */
    private function pollAcceleratorStatus(ZenlayerRestClient $client, string $acceleratorId): void
    {
        for ($attempt = 0; $attempt < $this->maxPollAttempts; $attempt++) {
            $resp = $client->call('DescribeAccelerators', [
                'acceleratorIds' => [$acceleratorId],
                'pageNum' => 1,
                'pageSize' => 1,
            ]);
            $dataSet = is_array($resp['dataSet'] ?? null) ? $resp['dataSet'] : [];
            if ($dataSet === []) {
                throw new ZenlayerApiException('AcceleratorNotFound', "轮询时未找到 Zenlayer 加速器: $acceleratorId");
            }

            $status = is_string($dataSet[0]['acceleratorStatus'] ?? null) ? $dataSet[0]['acceleratorStatus'] : '';
            if ($status === 'Accelerating') {
                return;
            }
            if (in_array($status, ['NotAccelerate', 'StopAccelerate', 'AccelerateFailure'], true)) {
                throw new ZenlayerApiException('DeployFailed', "Zenlayer 加速器 $acceleratorId 状态异常: $status");
            }

            if ($attempt < $this->maxPollAttempts - 1) {
                $this->sleep($this->pollIntervalSeconds); // 末次不 sleep（timing M1）
            }
        }

        throw new ZenlayerApiException('DeployTimeout', "Zenlayer 加速器 $acceleratorId 证书部署超时");
    }

    public function pollBudget(): PollBudget
    {
        // N_upload=1（CreateCertificate）+ N_pre=2（DescribeAccelerators + ModifyAcceleratorCertificate）
        return new PollBudget(
            clientTimeoutSeconds: ZenlayerRestClient::TIMEOUT_SECONDS,
            uploadCalls: 1,
            preIterCalls: 2,
            bindIterations: $this->maxPollAttempts,
            intervalSeconds: $this->pollIntervalSeconds,
        );
    }

    /** 轮询间隔（秒）；测试 override 为 no-op。 */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'zga' => new ZenlayerRestClient(
                'zga',
                '2023-07-06',
                $credentials['access_key_id'] ?? '',
                $credentials['access_key_password'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return ZenlayerErrorSanitizer::sanitize($e);
    }
}
