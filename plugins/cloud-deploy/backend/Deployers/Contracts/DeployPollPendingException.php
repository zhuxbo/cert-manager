<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

use RuntimeException;

/**
 * 云端部署任务已提交、但未在本轮询窗口内达终态（携云端 jobId 供续查）。
 *
 * extends RuntimeException → 走 CloudDeployJob 的重试通道（占一次 attempt、退避重试），
 * 且**必须在 guardSdk 之外抛出**（guardSdk 会 catch(Throwable) 重建为无 jobId 的通用异常）。
 * CloudDeployJob catch 到本异常时把 remoteJobId 持久化到 Cache，重试/sweep 复扫时 resumePoll
 * 续查同一云端任务（不重建），消除「每 attempt 重建云端任务 → 旧任务终态永不被观察」的慢性误报。
 */
class DeployPollPendingException extends RuntimeException
{
    public function __construct(public readonly string $remoteJobId, string $message)
    {
        parent::__construct($message);
    }
}
