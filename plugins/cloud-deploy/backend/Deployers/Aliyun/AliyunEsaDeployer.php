<?php

namespace Plugins\CloudDeploy\Deployers\Aliyun;

use AlibabaCloud\SDK\Cas\V20200407\Cas;
use AlibabaCloud\SDK\ESA\V20240910\ESA;
use AlibabaCloud\SDK\ESA\V20240910\Models\SetCertificateRequest;
use Darabonba\OpenApi\Exceptions\AlibabaCloudException;
use Darabonba\OpenApi\Models\Config;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 阿里云 ESA（Edge Security Acceleration，边缘安全加速，证书服务型，复用 CAS 上传器）。
 *
 * 证书先经 AliyunCasUploader 上传拿 CertIdentifier（走 RemoteCertStore 去重，store_kind=cas），再调
 * esa.SetCertificate 以 Type=cas + CasId（**纯数字 CertId**）把证书配置到 ESA 站点。
 *
 * 与 dcdn/vod 同：ESA 的 SetCertificate.CasId 吃**纯数字 CertId（int）**（对齐 certimate aliyun-esa：
 * `certId, _ := strconv.ParseInt(upres.CertId, 10, 64)`，CasId: tea.Int64(certId)）——故此处用
 * ParsesCasCertIdentifier 从 CertIdentifier "{certId}-{region}" 拆出**数字 certId** 作 CasId（丢弃 region 段，
 * CasId 不需要它）。区别于 WAF/GA/APIG/ddospro 那种「整串作 certId」的服务。
 *
 * **幂等**：SetCertificate 对「同一证书已配置到站点」回 Code=`Certificate.Duplicated`，certimate 视其为
 * 成功（直接返回）。本类在 SDK 闭包内捕获 AlibabaCloudException、仅当 code===`Certificate.Duplicated` 时吞掉
 * 当成功（Job 重试/重复推送幂等）；其余异常重抛交 guardSdk 脱敏。
 *
 * region：ESA 站点 region（config.region，既定 esa endpoint 又作 SetCertificate.Region 入参——对齐 certimate
 * `Region: tea.String(d.config.Region)`）。证书的 CAS region 编在 CertIdentifier 里、CAS 全局，二者独立。
 * endpoint 空 region 回落 cn-hangzhou（对齐 certimate createSDKClient）。
 *
 * SDK：alibabacloud/esa-20240910 ^3（openapi-core 运行时，client extends Darabonba\OpenApi\OpenApiClient，
 * 类名大写 ESA），与 apigw/ddospro 同代；脱敏走 AliyunErrorSanitizer 的 AlibabaCloudException 分支。单测全程
 * mock client，不触达 HTTP；真实环境建议做一次冒烟验证。
 */
class AliyunEsaDeployer extends AbstractDeployer
{
    use ParsesCasCertIdentifier;

    /** ESA 已配置同证书时的幂等错误码（视为成功）。 */
    private const DUPLICATED_CODE = 'Certificate.Duplicated';

    public function provider(): string
    {
        return 'aliyun';
    }

    public function product(): string
    {
        return 'esa';
    }

    public function label(): string
    {
        return '阿里云 ESA';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'site_id', 'label' => 'ESA 站点 ID', 'type' => 'string', 'required' => true],
            ['key' => 'region', 'label' => '地域', 'type' => 'string', 'required' => false],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        // CAS 全局，上传不需要 region；复用 deployer 注入缝：测试 override makeClient('cas') 即作用于上传
        return new AliyunCasUploader(fn (array $credentials): object => $this->makeClient('cas', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（CertIdentifier "{certId}-{region}"，拆出数字 certId 作 CasId）
     * @param  array{access_key_id:string,access_key_secret:string}  $credentials
     * @param  array{site_id:int|string,region?:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $siteIdRaw = $this->requireConfig($config, 'site_id');
        if (! ctype_digit((string) $siteIdRaw)) {
            $this->fail('ESA 站点 ID（site_id）必须为数字');
        }
        $siteId = (int) $siteIdRaw;
        $region = isset($config['region']) ? (string) $config['region'] : '';
        // CasId 取 CertIdentifier 的数字 certId 段（区别于整串作 certId 的服务）
        [$certId] = $this->parseCertIdentifier((string) $certRef);

        $this->guardSdk(function () use ($credentials, $region, $siteId, $certId) {
            /** @var ESA $client */
            $client = $this->makeClient('esa', $credentials + ['region' => $region]);
            try {
                $client->setCertificate(new SetCertificateRequest([
                    'siteId' => $siteId,
                    'type' => 'cas',
                    'casId' => $certId,
                    'region' => $region,
                ]));
            } catch (AlibabaCloudException $e) {
                // 同证书已配置：幂等成功（对齐 certimate Certificate.Duplicated 短路）
                if (is_string($e->code) && $e->code === self::DUPLICATED_CODE) {
                    return;
                }
                throw $e;
            }
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $ak = $credentials['access_key_id'] ?? '';
        $sk = $credentials['access_key_secret'] ?? '';

        return match ($kind) {
            'cas' => new Cas(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => 'cas.aliyuncs.com',
            ])),
            // 接入点：esa.{region}.aliyuncs.com（空 region 回落 cn-hangzhou，对齐 certimate）
            'esa' => new ESA(new Config([
                'accessKeyId' => $ak,
                'accessKeySecret' => $sk,
                'endpoint' => $this->endpointForRegion($credentials['region'] ?? ''),
            ])),
        };
    }

    /** esa 接入点：region 为空回落杭州，否则 esa.{region}.aliyuncs.com。 */
    private function endpointForRegion(string $region): string
    {
        return $region === '' ? 'esa.cn-hangzhou.aliyuncs.com' : "esa.$region.aliyuncs.com";
    }

    protected function sanitize(Throwable $e): string
    {
        return AliyunErrorSanitizer::sanitize($e);
    }
}
