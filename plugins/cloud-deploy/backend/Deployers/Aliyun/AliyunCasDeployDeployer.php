<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\Cas\V20200407\Models\CreateDeploymentJobRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\DescribeDeploymentJobRequest;
use AlibabaCloud\SDK\Cas\V20200407\Models\ListContactRequest;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
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
class AliyunCasDeployDeployer extends AbstractDeployer
{
    use ParsesCasCertIdentifier;

    /** 轮询部署任务最大次数。 */
    protected int $maxPollAttempts = 60;

    /** 每次轮询间隔秒数（测试子类置 0）。 */
    protected int $pollIntervalSeconds = 10;

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

        $this->pollDeploymentJob($client, $jobId);
    }

    /**
     * 轮询部署任务直到终态（success/error）。对齐 certimate：success/error 均视为「轮询完成」，
     * 空/editing 视为异常；其余（调度/部署中）继续等待。超过 maxPollAttempts 抛超时业务错误。
     *
     * @param  Cas  $client
     */
    protected function pollDeploymentJob(object $client, mixed $jobId): void
    {
        for ($i = 0; $i < $this->maxPollAttempts; $i++) {
            $status = $this->guardSdk(function () use ($client, $jobId) {
                $resp = $client->describeDeploymentJob(new DescribeDeploymentJobRequest(['jobId' => $jobId]));

                return (string) ($resp->body?->status ?? '');
            });

            if ($status === 'success' || $status === 'error') {
                return; // 终态（对齐 certimate：到达即停，不区分成败）
            }
            if ($status === '' || $status === 'editing') {
                $this->fail("阿里云部署任务状态异常：$status");
            }

            $this->sleep($this->pollIntervalSeconds);
        }

        $this->fail('阿里云部署任务未在预期时间内完成');
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
            'cas' => new Cas(new Config([
                'accessKeyId' => $credentials['access_key_id'] ?? '',
                'accessKeySecret' => $credentials['access_key_secret'] ?? '',
                'endpoint' => 'cas.aliyuncs.com',
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
