<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\CreateDeploymentJobRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\DescribeDeploymentJobRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\ListContactRequest;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;
use Plugins\CloudDeploy\Deployers\Contracts\HasPollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\PollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\ResumesRemoteJob;
use Throwable;

/**
 * 阿里云 SSL 证书部署（CAS 托管批量部署，证书服务型）。
 *
 * 对齐 certimate aliyun-cas-deploy：证书先经 CAS 上传拿 CertId（走 RemoteCertStore 去重），再调
 * cas.CreateDeploymentJob（JobType=user）把证书批量部署到指定云资源（ResourceIds），由阿里云 CAS
 * 托管下发；随后轮询 cas.DescribeDeploymentJob 直到任务到终态（success/error）。
 *
 * config：
 * - resource_ids（必填）→ ResourceIds，云资源 ID 列表（换行/逗号/分号分隔），逗号拼接传入。
 * - contact_ids（选填）→ ContactIds，云联系人 ID 列表；留空时调 ListContact 取账号下第一个联系人。
 *
 * CertIds 用**纯数字 CertId**（从 CertIdentifier "{certId}-{region}" 拆出，对齐 certimate `upres.CertId`）。
 */
class AliyunCasDeployDeployer extends AbstractDeployer implements HasPollBudget, ResumesRemoteJob
{
    use BuildsAliyunConfig, ParsesCasCertIdentifier;

    /** bind 短窗首查次数（G2 压窗）：未终态即抛 DeployPollPendingException 走重试/sweep-B 续查同一 jobId。 */
    protected int $maxPollAttempts = 1;

    /** resumePoll 续查次数（无前置建任务，预算宽松）。 */
    protected int $resumePollAttempts = 3;

    /** 每次轮询间隔秒数（测试子类置 0）。 */
    protected int $pollIntervalSeconds = 5;

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'casdeploy';
    }

    public function label(): string
    {
        return '阿里云 SSL 证书部署（CAS 托管）';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'resource_ids', 'label' => '云资源 ID 列表（换行或逗号分隔）', 'type' => 'string', 'required' => true],
            ['key' => 'contact_ids', 'label' => '云联系人 ID 列表（选填，留空取首个）', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，拆出数字 certId 作 CertIds）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{resource_ids:string|list<string>,contact_ids?:string|list<string>}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $resourceIds = $this->normalizeList($this->requireConfig($config, 'resource_ids'));
        if ($resourceIds === []) {
            $this->fail('缺少配置 resource_ids');
        }
        $contactIds = $this->normalizeList($config['contact_ids'] ?? '');
        [$certId] = $this->parseCertIdentifier((string) $certRef);

        /** @var Cas $client */
        $client = $this->makeClient('cas', $credentials);

        // 未指定联系人时取账号下第一个（对齐 certimate ListContact ShowSize=1）
        if ($contactIds === []) {
            $firstContactId = $this->guardSdk(function () use ($client) {
                $resp = $client->listContact(new ListContactRequest(['showSize' => 1, 'currentPage' => 1]));
                $list = $resp->body?->contactList ?? [];

                return isset($list[0]) ? (string) $list[0]->contactId : null;
            });
            if ($firstContactId !== null && $firstContactId !== '') {
                $contactIds = [$firstContactId];
            }
        }

        $jobId = $this->guardSdk(function () use ($client, $certId, $resourceIds, $contactIds) {
            $req = new CreateDeploymentJobRequest([
                'name' => 'clouddeploy_'.(int) (microtime(true) * 1000),
                'jobType' => 'user',
                'certIds' => (string) $certId,
                'resourceIds' => implode(',', $resourceIds),
                'contactIds' => implode(',', $contactIds),
            ]);

            return $client->createDeploymentJob($req)->body?->jobId;
        });

        if ($jobId === null || $jobId === '') {
            $this->fail('阿里云 CreateDeploymentJob 未返回 JobId');
        }

        $this->pollDeploymentJob($client, (string) $jobId, $this->maxPollAttempts);
    }

    /**
     * G2 续查：重试/sweep-B 复扫时续查**同一** jobId（不重建云端任务）。终态成功正常返回；
     * 终态 error / 空 / editing 抛业务错误；仍处理中抛 DeployPollPendingException（同 jobId 续期）。
     */
    public function resumePoll(string $remoteJobId, array $credentials, array $config): void
    {
        /** @var Cas $client */
        $client = $this->makeClient('cas', $credentials);
        $this->pollDeploymentJob($client, $remoteJobId, $this->resumePollAttempts);
    }

    public function pollBudget(): PollBudget
    {
        // N_upload=1（CAS UploadUserCertificate）+ N_pre=2（listContact worst + createDeploymentJob）；
        // T = read+connect 之和（darabonba 合成 Guzzle 总 timeout，见 BuildsAliyunConfig）
        return new PollBudget(
            clientTimeoutSeconds: self::aliyunCallBudgetSeconds(),
            uploadCalls: 1,
            preIterCalls: 2,
            bindIterations: $this->maxPollAttempts,
            intervalSeconds: $this->pollIntervalSeconds,
        );
    }

    /**
     * 有界轮询部署任务。终态 success 收敛；终态 error 抛业务错误（与 certimate「到达即停不区分成败」
     * 有意分化——履 ResumesRemoteJob 契约「终态失败抛 DeployBusinessException」，与 Wangsu/Tencent 一致，
     * G5 通知随之可达）；空/editing 视为异常（业务错误）；窗口耗尽抛 DeployPollPendingException
     * （携 jobId、guardSdk 之外）走重试/sweep-B 续查。
     *
     * @param  Cas  $client
     */
    protected function pollDeploymentJob(object $client, string $jobId, int $attempts): void
    {
        for ($i = 0; $i < $attempts; $i++) {
            $status = $this->guardSdk(function () use ($client, $jobId) {
                $resp = $client->describeDeploymentJob(new DescribeDeploymentJobRequest(['jobId' => $jobId]));

                return (string) ($resp->body?->status ?? '');
            });

            if ($status === 'success') {
                return; // 终态成功
            }
            if ($status === 'error') {
                $this->fail('阿里云部署任务终态失败（error）'); // 终态失败 → 业务错误（清 pending + G5 通知）
            }
            if ($status === '' || $status === 'editing') {
                $this->fail("阿里云部署任务状态异常：$status");
            }

            if ($i < $attempts - 1) {
                $this->sleep($this->pollIntervalSeconds); // 末次不 sleep（回收预算，timing M1）
            }
        }

        // 窗口耗尽：任务已提交、未在窗口内达终态 → 携 jobId 抛 poll_pending（重试/sweep-B resumePoll 续查同一任务）
        throw new DeployPollPendingException($jobId, '阿里云部署任务处理中，待确认（任务已提交云端）');
    }

    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * 归一为非空字符串数组：数组原样过滤；字符串按换行/逗号/中文逗号/分号切分。
     *
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
        return match ($kind) {
            'cas' => new Cas($this->aliyunConfig($credentials, 'cas.aliyuncs.com')),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
