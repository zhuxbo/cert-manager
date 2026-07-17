<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 长轮询 deployer 的 bind 最坏耗时预算（供 CloudDeployPollBudgetTest 计算断言 ≤50s，不真 sleep）。
 *
 * 不变式：worstCaseBindSeconds ≤ 50（$timeout=55 留 5s 余量）。各字段从 deployer 的运行时常量
 * 派生（bindIterations = maxPollAttempts、intervalSeconds = pollIntervalSeconds、clientTimeoutSeconds
 * = 客户端 read timeout 单一来源），谁把常量回调破预算，测试即红。
 */
final class PollBudget
{
    /**
     * @param  int  $clientTimeoutSeconds  T：客户端 read/请求超时（秒），单一来源
     * @param  int  $uploadCalls  N_upload：uploader 上传 SDK 调用数（去重未命中 worst=1；内联型=0）
     * @param  int  $preIterCalls  N_pre：bind 内轮询前的 SDK 调用数（建任务/查资源等）
     * @param  int  $bindIterations  N_iter：bind 短窗首查轮询次数（= maxPollAttempts）
     * @param  int  $intervalSeconds  轮询间隔秒（末次不 sleep，故仅计 N_iter-1 次）
     */
    public function __construct(
        public readonly int $clientTimeoutSeconds,
        public readonly int $uploadCalls,
        public readonly int $preIterCalls,
        public readonly int $bindIterations,
        public readonly int $intervalSeconds,
    ) {}

    /**
     * bind 最坏墙钟：所有 SDK 调用各吃满 client timeout + 迭代间隔（末次不 sleep）。
     * = (N_upload + N_pre + N_iter) × T + max(0, N_iter − 1) × interval
     */
    public function worstCaseBindSeconds(): int
    {
        $calls = $this->uploadCalls + $this->preIterCalls + $this->bindIterations;

        return $calls * $this->clientTimeoutSeconds
            + max(0, $this->bindIterations - 1) * $this->intervalSeconds;
    }
}
