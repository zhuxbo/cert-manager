<?php

namespace Plugins\CloudDeploy\Deployers\Lecdn;

use GuzzleHttp\Client as GuzzleClient;
use Plugins\CloudDeploy\Deployers\Contracts\AbstractDeployer;
use Throwable;

/**
 * LeCDN 证书（内联型）。
 *
 * 对齐 certimate lecdn（deployTarget=certificate）：更新 LeCDN 已有证书内容（PUT /certificate/{id}，PEM 原样）。
 * 按凭证 api_role 选 client（用户端）/ master（主控端）SDK 分支：master 端 body 多 client_id。
 * - 完整链（certRef.cert + certRef.chain）→ ssl_pem（原样，非 base64）
 * - 私钥（certRef.key）→ ssl_key（原样）
 *
 * 内联型（usesRemoteCertStore=false）：bind 收 {cert,key,chain} 三元组。鉴权账号密码登录 + `Authorization: Bearer`。
 *
 * config：certificate_id（LeCDN 证书数字 ID，必填）+ client_id（客户 ID，选填，仅 master 角色生效）。
 * server_url / api_version / api_role / username / password / allow_insecure 归 credentialSchema。
 */
class LecdnDeployer extends AbstractDeployer
{
    public function provider(): string
    {
        return 'lecdn';
    }

    public function product(): string
    {
        return 'lecdn';
    }

    public function label(): string
    {
        return 'LeCDN 证书';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'certificate_id', 'label' => 'LeCDN 证书 ID', 'type' => 'number', 'required' => true],
            ['key' => 'client_id', 'label' => '客户 ID（仅主控端 master 需填）', 'type' => 'number', 'required' => false],
        ];
    }

    /**
     * @param  array{cert:string,key:string,chain:string}|string  $certRef  内联 PEM 三元组
     * @param  array<string,mixed>  $credentials
     * @param  array{certificate_id:int|string,client_id?:int|string}  $config
     */
    public function bind(string|array $certRef, array $credentials, array $config): void
    {
        $certificateId = (int) $this->requireConfig($config, 'certificate_id');
        $clientId = isset($config['client_id']) && $config['client_id'] !== '' ? (int) $config['client_id'] : 0;

        // 角色/版本校验是业务错误，须在 guardSdk 外抛（否则会被脱敏成通用文案）
        $this->assertRoleSupported($credentials);

        // 完整链（leaf + 中间证书），与 certimate 传 certPEM 完整链一致；LeCDN 收原样 PEM（非 base64）
        $fullChain = rtrim($certRef['cert']);
        if (trim($certRef['chain']) !== '') {
            $fullChain .= "\n".trim($certRef['chain']);
        }
        $key = $certRef['key'];

        $this->guardSdk(function () use ($credentials, $certificateId, $fullChain, $key, $clientId) {
            /** @var LecdnRestClient $client */
            $client = $this->makeClient('api', $credentials);
            $client->updateCertificate($certificateId, $fullChain, $key, $clientId);
        });
    }

    /**
     * 校验 api_version/api_role 合法（仅 v3 + client/master，对齐 certimate）。业务错误，须在 guardSdk 外调用。
     *
     * @param  array<string,mixed>  $credentials
     */
    protected function assertRoleSupported(array $credentials): void
    {
        $apiVersion = (string) ($credentials['api_version'] ?? '');
        $apiRole = (string) ($credentials['api_role'] ?? '');
        if ($apiVersion !== 'v3' || ! in_array($apiRole, [LecdnRestClient::ROLE_CLIENT, LecdnRestClient::ROLE_MASTER], true)) {
            $this->fail('LeCDN 仅支持 api_version=v3 且 api_role 为 client/master');
        }
    }

    protected function makeClient(string $kind, array $credentials): object
    {
        $serverUrl = rtrim((string) ($credentials['server_url'] ?? ''), '/');
        $apiRole = (string) ($credentials['api_role'] ?? '');

        return match ($kind) {
            'api' => new LecdnRestClient(
                new GuzzleClient([
                    'base_uri' => "$serverUrl/prod-api/",
                    'timeout' => 30,
                    'verify' => empty($credentials['allow_insecure']),
                ]),
                $apiRole,
                (string) ($credentials['username'] ?? ''),
                (string) ($credentials['password'] ?? ''),
            ),
        };
    }

    protected function sanitize(Throwable $e): string
    {
        return LecdnErrorSanitizer::sanitize($e);
    }
}
