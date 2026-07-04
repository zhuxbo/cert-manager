<?php

namespace Plugins\CloudDeploy\Deployers\Upyun;

use Plugins\CloudDeploy\Deployers\Contracts\ProviderInterface;

/**
 * 又拍云 provider：凭证为控制台账号 username/password（对齐 certimate AccessConfigForUpyun）。
 *
 * 又拍云控制台 API 无 AK/SK 体系，鉴权走 console 账号登录拿 Cookie（见 UpyunRestClient）。
 * password 标 secret:true，前端表单按密码框渲染、脱敏体系（CredentialScrubber）兜底防泄露。
 */
class UpyunProvider implements ProviderInterface
{
    public function key(): string
    {
        return 'upyun';
    }

    public function label(): string
    {
        return '又拍云';
    }

    public function credentialSchema(): array
    {
        return [
            ['key' => 'username', 'label' => '账号用户名', 'required' => true],
            ['key' => 'password', 'label' => '账号密码', 'required' => true, 'secret' => true],
        ];
    }
}
