<?php

namespace Plugins\CloudDeploy\Deployers\Huaweicloud;

/**
 * region 服务（elb/waf/live/apig）的项目 ID 反查。
 *
 * 对齐 certimate 各 region 型 deployer 的 getSDKProjectId：用 global 凭证调 IAM
 *   GET /v3/projects?name={region} → projects[0].id
 * 拿到 region 对应的项目 ID，再用 basic 凭证（WithProjectId）构造业务服务 client。华为云 region 服务的 endpoint 与签名
 * 都需要项目隔离（X-Project-Id 头），故业务调用前必须先反查。
 *
 * IAM 为全局服务（iam.myhuaweicloud.com），用 global 凭证（无 projectId）。
 *
 * 注入缝：deployer 的 makeClient('iam', …) 返回绑定 IAM host 的 HuaweicloudRestClient —— 测试 override makeClient
 * 即可让本 trait 拿到 mock，断言「先查 projectId、再用其构造业务 client」的两段流程。
 */
trait ResolvesHuaweiProjectId
{
    /**
     * 反查 region 对应项目 ID。
     *
     * @param  array<string,mixed>  $credentials
     */
    protected function resolveProjectId(array $credentials, string $region): string
    {
        /** @var HuaweicloudRestClient $iam */
        $iam = $this->makeClient('iam', $credentials);
        $resp = $iam->get('/v3/projects', ['name' => $region]);

        $projects = is_array($resp['projects'] ?? null) ? $resp['projects'] : [];
        foreach ($projects as $project) {
            if (is_array($project) && is_string($project['id'] ?? null) && $project['id'] !== '') {
                return $project['id'];
            }
        }

        throw new HuaweicloudApiException('ProjectNotFound', "未找到 region '$region' 对应的华为云项目 ID");
    }

    /**
     * IAM 全局 host。
     */
    protected function iamHost(): string
    {
        return 'iam.myhuaweicloud.com';
    }

    /**
     * region 服务 host：{service}.{region}.myhuaweicloud.com。
     */
    protected function regionalHost(string $service, string $region): string
    {
        return "$service.$region.myhuaweicloud.com";
    }

    /**
     * SCM 服务 host（按 region；region 为空回落 cn-north-4，对齐 certimate certmgr huaweicloud-scm）。
     */
    protected function scmHost(string $region = ''): string
    {
        $region = $region !== '' ? $region : 'cn-north-4';

        return "scm.$region.myhuaweicloud.com";
    }
}
