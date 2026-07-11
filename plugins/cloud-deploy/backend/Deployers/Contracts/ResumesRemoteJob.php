<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * job-id 型 deployer（bind 时新建一次性云端部署任务再轮询）实现本接口，支持「续查已提交的云端任务」。
 *
 * CloudDeployJob 在重试/sweep 复扫时，若 Cache 中存有该 target 的 pending jobId 且 deployer
 * instanceof ResumesRemoteJob，则调 resumePoll 续查**同一** remoteJobId（不重建云端任务）：
 *   - 终态成功：正常返回（Job 走既有 success 路径）。
 *   - 终态失败：抛 DeployBusinessException（Job 走业务终态失败路径 + 通知）。
 *   - 仍处理中：抛 DeployPollPendingException（携同一 jobId，Job 续期 Cache 再等）。
 *
 * resumePoll 只做轮询、无前置的建任务/上传（预算宽松），故不复用 bind 的短窗首查逻辑。
 */
interface ResumesRemoteJob
{
    /**
     * @param  string  $remoteJobId  bind 时提交云端拿到的任务/记录 id
     * @param  array<string,mixed>  $credentials  云账号凭证（解密后）
     * @param  array<string,mixed>  $config  target 部署配置
     */
    public function resumePoll(string $remoteJobId, array $credentials, array $config): void;
}
