<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * Provider 级抽象：声明该云厂商的凭证 schema（前端据此渲染凭证表单）。
 * 一个 provider 下可挂多个 (provider, product) deployer，共用同一套凭证。
 */
interface ProviderInterface
{
    /** provider 唯一标识，如 'aliyun'。 */
    public function key(): string;

    /** 展示名，如 '阿里云'。 */
    public function label(): string;

    /**
     * 凭证字段 schema，供前端表单渲染。
     *
     * @return list<array{key:string,label:string,required?:bool,secret?:bool,destination?:bool}>
     */
    public function credentialSchema(): array;
}
