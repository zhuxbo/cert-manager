<?php

namespace Plugins\CloudDeploy\Deployers\Wangsu;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;
use Plugins\CloudDeploy\Deployers\Contracts\HasPollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\PollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\ResumesRemoteJob;
use Throwable;

/**
 * 网宿云 CDN Pro（内联型，自带证书上传 + 部署任务轮询）。
 *
 * 与 cdn / certificate 端点不同，CDN Pro 走**独立的证书体系**（/cdn/certificates，对象 id + 版本号），
 * 且私钥须用 apiKey 做 **AES-128-CBC 加密**后随证书外发，再创建**部署任务**异步落地。故本端点为内联型
 * （usesRemoteCertStore=false，不经 RemoteCertStore / WangsuCertUploader），bind 收 {cert,key,chain}。
 *
 * 流程对齐 certimate wangsu-cdnpro：
 *   1. GetHostnameDetail(domain) 确认加速域名存在。
 *   2. encryptPrivateKey(privkey, apiKey, ts)：HMAC-SHA256(apiKey, RFC1123 date) → 64 hex；
 *      前 32 hex 为 IV、后 32 hex 为 key（AES-128），PKCS7 填充后 CBC 加密、base64。
 *   3. 未填 certificate_id → CreateCertificate(POST /cdn/certificates) 新建（version=1）；
 *      填了 → UpdateCertificate(PATCH /cdn/certificates/{id}) 追加新版本（version 解析自 Location）。
 *   4. CreateDeploymentTask(POST /cdn/deploymentTasks)：action=deploy_cert + certId + version + target(环境)。
 *   5. 轮询 GetDeploymentTaskDetail 直到 succeeded / finishTime 非空；failed 即报错（有界，防无限等）。
 *
 * 凭证：除 access_key_id/access_key_secret 外，**必须**有 api_key（私钥 AES 加密用）。
 * config：domain（必填）/ environment（部署环境，必填，certimate target，默认 production）/
 *   certificate_id（选填，填则更新已有证书）/ webhook_id（选填）。
 */
class WangsuCdnProDeployer extends AbstractDeployer implements HasPollBudget, ResumesRemoteJob
{
    /** bind 短窗首查次数（G2 压窗）：未终态即抛 DeployPollPendingException 走重试/sweep-B 续查同一 taskId。 */
    protected int $maxPollAttempts = 1;

    /** resumePoll 续查次数（无前置建任务，预算宽松）。 */
    protected int $resumePollAttempts = 3;

    /** 每次轮询间隔秒数（测试子类置 0 免真实 sleep）。 */
    protected int $pollIntervalSeconds = 5;

    public function provider(): string
    {
        return 'wangsu';
    }

    public function product(): string
    {
        return 'cdnpro';
    }

    public function label(): string
    {
        return '网宿云 CDN Pro';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
            ['key' => 'environment', 'label' => '部署环境（如 production / staging）', 'type' => 'string', 'required' => true],
            ['key' => 'certificate_id', 'label' => '证书 ID（选填，填则更新已有证书）', 'type' => 'string', 'required' => false],
            ['key' => 'webhook_id', 'label' => 'Webhook ID（选填）', 'type' => 'string', 'required' => false],
        ];
    }

    /**
     * 内联型：bind 收 {cert,key,chain} 三元组。
     *
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array{access_key_id:string,access_key_secret:string,api_key?:string}  $credentials
     * @param  array{domain:string,environment:string,certificate_id?:string,webhook_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $domain = (string) $this->requireConfig($config, 'domain');
        $environment = (string) $this->requireConfig($config, 'environment');
        $certificateId = isset($config['certificate_id']) ? (string) $config['certificate_id'] : '';
        $webhookId = isset($config['webhook_id']) ? (string) $config['webhook_id'] : '';

        // CDN Pro 私钥加密强依赖 apiKey —— 缺失走业务错误（非 SDK 异常），文案可读。
        $apiKey = (string) ($credentials['api_key'] ?? '');
        if ($apiKey === '') {
            $this->fail('网宿云 CDN Pro 需要 API Key（用于私钥加密），请在凭证中补全 api_key');
        }

        if (! is_array($certRef)) {
            $this->fail('网宿云 CDN Pro 为内联型，应收到证书 PEM 三元组');
        }

        $certPem = (string) ($certRef['cert'] ?? '');
        $keyPem = (string) ($certRef['key'] ?? '');
        $chainPem = (string) ($certRef['chain'] ?? '');
        $fullChain = trim($chainPem) === '' ? rtrim($certPem) : rtrim($certPem)."\n".trim($chainPem);

        /** @var WangsuRestClient $client */
        $client = $this->makeClient('api', $credentials);

        // 1) 确认加速域名存在（SDK 调用单独 guardSdk 脱敏）。
        $this->guardSdk(fn () => $client->getCdnProHostnameDetail($domain));

        // 2) 私钥 AES 加密（apiKey 派生 key/iv；本地计算、非 SDK 调用，业务错误直接透传）。
        $timestamp = $this->now();
        $encryptedPrivateKey = $this->encryptPrivateKey($keyPem, $apiKey, $timestamp);
        $newVersion = [
            'certificate' => $fullChain,
            'privateKey' => $encryptedPrivateKey,
        ];

        // 3) 新建 or 更新证书，拿对象 certId + 版本号（SDK 调用单独 guardSdk）。
        $name = 'clouddeploy_'.(int) (microtime(true) * 1000);
        $cert = $this->guardSdk(fn () => $certificateId === ''
            ? $client->createCdnProCertificate($name, $newVersion, $timestamp)
            : $client->updateCdnProCertificate($certificateId, $name, $newVersion, $timestamp));
        if ($cert['certId'] === '') {
            $this->fail('网宿云 CDN Pro 证书接口未返回证书 ID');
        }

        // 4) 创建部署任务（SDK 调用单独 guardSdk）。
        $taskName = 'clouddeploy_'.(int) (microtime(true) * 1000);
        $taskId = $this->guardSdk(fn () => $client->createCdnProDeploymentTask($taskName, $environment, $cert['certId'], $cert['version'], $webhookId));
        if ($taskId === '') {
            $this->fail('网宿云 CDN Pro 创建部署任务未返回任务 ID');
        }

        // 5) 有界轮询任务状态（每次 describe 单独 guardSdk，终态/失败判定的业务错误在 guard 外抛）。
        $this->pollDeploymentTask($client, $taskId, $this->maxPollAttempts);
    }

    /**
     * G2 续查：重试/sweep-B 复扫续查**同一** taskId（不重建证书/部署任务）。succeeded/finishTime 收敛；
     * status=failed 抛业务错误；窗口耗尽抛 DeployPollPendingException（同 taskId 续期）。
     */
    public function resumePoll(string $remoteJobId, array $credentials, array $config): void
    {
        /** @var WangsuRestClient $client */
        $client = $this->makeClient('api', $credentials);
        $this->pollDeploymentTask($client, $remoteJobId, $this->resumePollAttempts);
    }

    public function pollBudget(): PollBudget
    {
        // 内联型（N_upload=0）；N_pre=3（getHostnameDetail + create/update 证书 + createDeploymentTask）
        return new PollBudget(
            clientTimeoutSeconds: WangsuRestClient::TIMEOUT_SECONDS,
            uploadCalls: 0,
            preIterCalls: 3,
            bindIterations: $this->maxPollAttempts,
            intervalSeconds: $this->pollIntervalSeconds,
        );
    }

    /**
     * 有界轮询部署任务详情，直到 succeeded / finishTime 非空。status=failed 抛业务错误；窗口耗尽抛
     * DeployPollPendingException（携 taskId、guardSdk 之外）走重试/sweep-B 续查（任务已提交、仅未在窗口内落地）。
     */
    protected function pollDeploymentTask(WangsuRestClient $client, string $taskId, int $attempts): void
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $detail = $this->guardSdk(fn () => $client->getCdnProDeploymentTaskDetail($taskId));
            $status = $detail['status'];

            if ($status === 'failed') {
                $this->fail('网宿云 CDN Pro 部署任务失败');
            }
            if ($status === 'succeeded' || $detail['finishTime'] !== '') {
                return;
            }

            if ($attempt < $attempts - 1) {
                $this->sleep($this->pollIntervalSeconds); // 末次不 sleep（timing M1）
            }
        }

        // 窗口耗尽：任务已提交、未在窗口内落地 → 携 taskId 抛 poll_pending（重试/sweep-B resumePoll 续查同一任务）
        throw new DeployPollPendingException($taskId, '网宿云 CDN Pro 部署任务处理中，待确认（任务已提交）');
    }

    /**
     * 私钥 AES-128-CBC 加密（逐字节对齐 certimate wangsu-cdnpro encryptPrivateKey）：
     *   dateStr = RFC1123 GMT(timestamp)
     *   hmacHex = lower(hex(HMAC-SHA256(apiKey, dateStr)))   // 64 hex
     *   iv  = hex_decode(hmacHex[:32])   // 16 bytes
     *   key = hex_decode(hmacHex[32:64]) // 16 bytes → AES-128
     *   PKCS7 填充明文 → CBC 加密 → base64。
     */
    protected function encryptPrivateKey(string $privkeyPem, string $apiKey, int $timestamp): string
    {
        $dateStr = gmdate('D, d M Y H:i:s \G\M\T', $timestamp);
        $hmacHex = strtolower(hash_hmac('sha256', $dateStr, $apiKey));
        if (strlen($hmacHex) !== 64) {
            $this->fail('网宿云 CDN Pro 私钥加密密钥派生异常');
        }

        $iv = (string) hex2bin(substr($hmacHex, 0, 32));
        $key = (string) hex2bin(substr($hmacHex, 32, 32));

        // PKCS7 填充（块大小 16）。
        $blockSize = 16;
        $padLen = $blockSize - (strlen($privkeyPem) % $blockSize);
        $padded = $privkeyPem.str_repeat(chr($padLen), $padLen);

        $encrypted = openssl_encrypt($padded, 'aes-128-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        if ($encrypted === false) {
            $this->fail('网宿云 CDN Pro 私钥加密失败');
        }

        return base64_encode($encrypted);
    }

    /** 当前 Unix 时间戳（抽出便于测试固定）。 */
    protected function now(): int
    {
        return time();
    }

    /** 抽出 sleep 便于测试子类 override 为 no-op（避免真实等待）。 */
    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new WangsuRestClient(
                $credentials['access_key_id'] ?? '',
                $credentials['access_key_secret'] ?? '',
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return WangsuErrorSanitizer::sanitize($e);
    }
}
