<?php

namespace Plugins\CloudDeploy\Deployers\Zenlayer;

use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Plugins\CloudDeploy\Deployers\Contracts\HasPollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\MatchesCertificateHostnames;
use Plugins\CloudDeploy\Deployers\Contracts\PollBudget;
use Plugins\CloudDeploy\Deployers\Contracts\ReceivesRemoteCertificateMaterial;
use Throwable;

/**
 * Zenlayer CDN（证书服务型）：证书先经 CDN 证书服务上传拿 certificateId（走 RemoteCertStore 去重），
 * 再绑定到 CDN 加速域名。
 *
 * 对齐 certimate zenlayer-cdn 的域名与证书两类部署目标：
 *   1. 证书经 ZenlayerCertUploader 上传（CreateCertificate，service=cdn）拿 certificateId（store_kind=zenlayer_cdn）。
 *   2. DescribeDomains（domainStatus=ENABLED）分页拉加速域名，exact 过滤 domainName == config.domain 得 domainId 列表。
 *   3. 逐个 domainId：DescribeDomainCertificate 若已是该证书则跳过；否则 ModifyDomainCertificate 绑定，
 *      再 DescribeDomains 轮询 configStatus 直到 DEPLOYED（FAILED 报错）。
 *
 * 域名目标支持 exact / wildcard / certsan；certificate 目标按 certificate_id 原位替换。
 * certsan 经可选远端证书材料契约取得叶证书，私钥不进入 bind 上下文。
 * 轮询走 sleep() 注入缝（测试 no-op），上限 30 次（与 certimate 10s 间隔等价的有界收敛）。
 */
class ZenlayerCdnDeployer extends AbstractDeployer implements HasPollBudget, ReceivesRemoteCertificateMaterial
{
    use MatchesCertificateHostnames;

    private const PAGE_SIZE = 100;

    /** bind 轮询 configStatus 次数（G2 压窗）：状态轮询型，超窗抛 DeployTimeout（guardSdk 内重包装为可重试
     * RuntimeException），重试/sweep 自续观察同一域名收敛，无需 jobId 续查。 */
    protected int $maxPollAttempts = 1;

    /** 每次轮询间隔秒数（测试子类置 0）。 */
    protected int $pollIntervalSeconds = 5;

    public function provider(): string
    {
        return 'zenlayer';
    }

    public function product(): string
    {
        return 'cdn';
    }

    public function label(): string
    {
        return 'Zenlayer CDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'deploy_target', 'label' => '部署目标（domain/certificate）', 'type' => 'string', 'required' => false],
            ['key' => 'domain_match_pattern', 'label' => '域名匹配（exact/wildcard/certsan）', 'type' => 'string', 'required' => false],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => false],
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
            fn (array $credentials): object => $this->makeClient('cdn', $credentials),
            'zenlayer_cdn',
            (string) (($config['deploy_target'] ?? 'domain') === 'certificate' ? ($config['certificate_id'] ?? '') : ''),
        );
    }

    /**
     * @param  string|array{remote_cert_id:string,cert:string,chain:string}  $certRef  证书 id 或 opt-in 的 leaf/中间链上下文
     * @param  array{access_key_id:string,access_key_password:string}  $credentials
     * @param  array{deploy_target?:string,domain_match_pattern?:string,domain?:string,certificate_id?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $deployTarget = (string) ($config['deploy_target'] ?? 'domain');
        if ($deployTarget === 'certificate') {
            $this->requireConfig($config, 'certificate_id');

            return;
        }
        if ($deployTarget !== 'domain') {
            $this->fail("不支持的部署目标: $deployTarget");
        }
        $matchPattern = (string) ($config['domain_match_pattern'] ?? 'exact');
        $domain = $matchPattern === 'certsan' ? '' : (string) $this->requireConfig($config, 'domain');
        $certId = is_array($certRef) ? (string) ($certRef['remote_cert_id'] ?? '') : (string) $certRef;
        $certificate = is_array($certRef) ? (string) ($certRef['cert'] ?? '') : '';

        $this->guardSdk(function () use ($credentials, $domain, $matchPattern, $certificate, $certId) {
            /** @var ZenlayerRestClient $client */
            $client = $this->makeClient('cdn', $credentials);

            $domainIds = $this->findDomainIds($client, $domain, $matchPattern, $certificate);
            if ($domainIds === []) {
                throw new ZenlayerApiException('DomainNotFound', "未找到匹配的 Zenlayer CDN 域名: $domain");
            }

            foreach ($domainIds as $domainId) {
                $this->updateDomainCertificate($client, $domainId, $certId);
            }
        });
    }

    /**
     * DescribeDomains（ENABLED）分页拉域名，exact 过滤出 domainName == $domain 的 domainId 列表。
     *
     * @return list<string>
     */
    private function findDomainIds(ZenlayerRestClient $client, string $domain, string $matchPattern, string $certificate): array
    {
        $domainIds = [];
        $page = 1;

        while (true) {
            $resp = $client->call('DescribeDomains', [
                'domainStatus' => 'ENABLED',
                'pageNum' => $page,
                'pageSize' => self::PAGE_SIZE,
            ]);
            $dataSet = is_array($resp['dataSet'] ?? null) ? $resp['dataSet'] : [];

            foreach ($dataSet as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = is_string($item['domainName'] ?? null) ? $item['domainName'] : '';
                $id = isset($item['domainId']) ? (string) $item['domainId'] : '';
                $matched = match ($matchPattern) {
                    '', 'exact' => $name === $domain,
                    'wildcard' => $this->matchesWildcard($domain, $name),
                    'certsan' => $this->certificateMatchesHostname($certificate, $name),
                    default => throw new ZenlayerApiException('InvalidMatchPattern', "不支持的域名匹配模式: $matchPattern"),
                };
                if ($matched && $id !== '') {
                    $domainIds[] = $id;
                }
            }

            if (count($dataSet) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return $domainIds;
    }

    private function matchesWildcard(string $pattern, string $hostname): bool
    {
        if (strcasecmp($pattern, $hostname) === 0) {
            return true;
        }
        if (str_starts_with($hostname, '.') || str_starts_with($hostname, '*.')) {
            return strcasecmp(ltrim($pattern, '*'), ltrim($hostname, '*')) === 0;
        }
        if (! str_starts_with($pattern, '*.')) {
            return false;
        }
        $suffix = substr($pattern, 2);
        $prefix = substr($hostname, 0, -strlen('.'.$suffix));

        return str_ends_with(strtolower($hostname), '.'.strtolower($suffix)) && $prefix !== '' && ! str_contains($prefix, '.');
    }

    /**
     * 绑定单个域名证书：已是该证书则跳过；否则 ModifyDomainCertificate + 轮询 configStatus 到 DEPLOYED。
     */
    private function updateDomainCertificate(ZenlayerRestClient $client, string $domainId, string $certId): void
    {
        // 查询既有证书，避免重复绑定
        $descResp = $client->call('DescribeDomainCertificate', ['domainId' => $domainId]);
        $certificate = is_array($descResp['certificate'] ?? null) ? $descResp['certificate'] : [];
        if (($certificate['certificateId'] ?? null) === $certId) {
            return;
        }

        // 修改域名证书
        $client->call('ModifyDomainCertificate', ['domainId' => $domainId, 'certificateId' => $certId]);

        // 轮询部署状态：DEPLOYED 成功 / FAILED 报错 / 超窗抛 DeployTimeout（guardSdk 内重包装为可重试 RuntimeException）
        for ($attempt = 0; $attempt < $this->maxPollAttempts; $attempt++) {
            $pollResp = $client->call('DescribeDomains', ['domainIds' => [$domainId], 'pageNum' => 1, 'pageSize' => 1]);
            $dataSet = is_array($pollResp['dataSet'] ?? null) ? $pollResp['dataSet'] : [];
            if ($dataSet === []) {
                throw new ZenlayerApiException('DomainNotFound', "轮询时未找到 Zenlayer CDN 域名: $domainId");
            }

            $status = is_string($dataSet[0]['configStatus'] ?? null) ? $dataSet[0]['configStatus'] : '';
            if ($status === 'DEPLOYED') {
                return;
            }
            if ($status === 'FAILED') {
                throw new ZenlayerApiException('DeployFailed', "Zenlayer CDN 域名 $domainId 证书部署失败");
            }

            if ($attempt < $this->maxPollAttempts - 1) {
                $this->sleep($this->pollIntervalSeconds); // 末次不 sleep（timing M1）
            }
        }

        throw new ZenlayerApiException('DeployTimeout', "Zenlayer CDN 域名 $domainId 证书部署超时");
    }

    public function pollBudget(): PollBudget
    {
        // N_upload=1（CreateCertificate）+ N_pre=3（DescribeDomains 找域名 + DescribeDomainCertificate + ModifyDomainCertificate）。
        // 假设声明：worst=50 恰在 ≤50 边界，按「单页单匹配域名」计——DescribeDomains 分页（>100 域名）或
        // exact 命中多个 domainId 时逐域名串行，真实最坏可超预算；接受残余：超出部分由 CloudDeployJob
        // $timeout=55 SIGALRM 优雅兜底（状态轮询型，重试自续观察收敛，无慢性误报）。
        return new PollBudget(
            clientTimeoutSeconds: ZenlayerRestClient::TIMEOUT_SECONDS,
            uploadCalls: 1,
            preIterCalls: 3,
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
            'cdn' => new ZenlayerRestClient(
                'cdn',
                '2022-11-20',
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
