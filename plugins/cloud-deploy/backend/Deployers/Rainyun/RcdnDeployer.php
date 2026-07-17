<?php

namespace Plugins\CloudDeploy\Deployers\Rainyun;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Plugins\CloudDeploy\Deployers\Contracts\CertUploaderInterface;
use Throwable;

/**
 * 雨云 RCDN（证书服务型）。
 *
 * 对齐 certimate rainyun-rcdn：先把证书上传到雨云证书中心拿 cert id（由 RainyunSslcenterUploader +
 * RemoteCertStore 完成；因雨云 create 不返回 id，上传器内 list-match 反查 id），再把证书绑定到 RCDN 实例
 * （POST /product/rcdn/instance/{instanceId}/ssl_bind {cert_id, domains}）。
 *
 * usesRemoteCertStore=true：bind 收 remote_cert_id（雨云证书中心 cert id 字符串）。
 * 仅 exact 域名匹配（对齐 certimate rcdn 当前仅支持精确匹配 DOMAIN_MATCH_PATTERN_EXACT）。
 * 鉴权 API Key（api_key 凭证）。
 *
 * config：instance_id（必填，数字）/ domain（必填，加速域名）。
 */
class RcdnDeployer extends AbstractDeployer
{
    private const BASE_URI = 'https://api.v2.rainyun.com/';

    public function provider(): string
    {
        return 'rainyun';
    }

    public function product(): string
    {
        return 'rcdn';
    }

    public function label(): string
    {
        return '雨云 RCDN';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'instance_id', 'label' => 'RCDN 实例 ID', 'type' => 'number', 'required' => true],
            ['key' => 'domain', 'label' => '加速域名', 'type' => 'string', 'required' => true],
        ];
    }

    public function usesRemoteCertStore(): bool
    {
        return true;
    }

    public function certUploader(array $config = []): ?CertUploaderInterface
    {
        return new RainyunSslcenterUploader(fn (array $credentials): object => $this->makeClient('api', $credentials));
    }

    /**
     * @param  string  $certRef  remote_cert_id（雨云证书中心 cert id）
     * @param  array{api_key:string}  $credentials
     * @param  array{instance_id:int|string,domain:string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $instanceId = (int) $this->requireConfig($config, 'instance_id');
        $domain = (string) $this->requireConfig($config, 'domain');
        $certId = (int) $certRef;

        $this->guardSdk(function () use ($credentials, $instanceId, $certId, $domain) {
            /** @var RainyunClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->rcdnInstanceSslBind($instanceId, $certId, [$domain]);
        });
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        return match ($kind) {
            'api' => new RainyunClient(new GuzzleClient([
                'base_uri' => self::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'X-API-Key' => (string) ($credentials['api_key'] ?? ''),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
            ])),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return RainyunErrorSanitizer::sanitize($e);
    }
}
