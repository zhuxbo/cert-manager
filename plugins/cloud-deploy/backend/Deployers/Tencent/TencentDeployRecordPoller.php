<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;

/**
 * 腾讯云 SSL「一键部署」任务详情轮询（DescribeHostDeployRecordDetail）的纯业务终态判定。
 *
 * DeployCertificateInstance 是异步接口，返回 DeployRecordId 后真正部署在腾讯侧排队执行。
 * certimate（tencentcloud-cos / tencentcloud-ssl-deploy）以 10s 间隔轮询任务详情，直到
 * succeeded+failed==total 判定终态：任一 failed 抛错；全 succeeded 即完成。本类把这段
 * 终态判定抽成单一来源供 COS / ssl-deploy 两端点复用。
 *
 * **职责边界（关键）**：本类只做「拿到一次详情响应 → 判定是否终态 / 是否失败」的纯逻辑，
 * 不直接调 SDK。每次轮询的 SDK 调用由调用方经 `$fetchDetail` 注入——调用方在 `$fetchDetail`
 * 内用 deployer 的 guardSdk 包裹真正的 DescribeHostDeployRecordDetail（让 SDK 异常被脱敏）。
 * 而本类抛出的「失败子任务 / 等待超时」是**业务错误**，必须在 guardSdk 之外抛出，否则会被
 * TencentErrorSanitizer 当作非 SDK 异常吞成泛化文案（「腾讯云调用失败: ...」）丢失语义。
 *
 * 与 certimate 的差异：certimate 用 xwait 无限轮询（依赖外层 ctx 超时取消）；插件无外层 ctx，
 * 故改为有界轮询（maxAttempts 上限），超时不当失败——任务已提交、腾讯侧仍会异步落地，
 * 抛「等待超时」业务错误仅为让上层可见，避免 Job 误判已彻底失败而触发不必要的整体回滚/重试风暴。
 *
 * 字段大小写（亲读 vendor DescribeHostDeployRecordDetailResponse）：TotalCount /
 * SuccessTotalCount / FailedTotalCount / RunningTotalCount 均为 public + 同名 getter；
 * 本 SDK 版本响应体**无 PendingTotalCount**（certimate 经 lo.FromPtr 容 nil），故不读 pending。
 */
class TencentDeployRecordPoller
{
    /**
     * @param  callable():object  $fetchDetail  返回一次 DescribeHostDeployRecordDetailResponse（调用方内部已 guardSdk）
     * @param  string  $recordId  DeployRecordId（窗口耗尽时携入 DeployPollPendingException 供 resumePoll 续查）
     * @param  int  $maxAttempts  最大轮询次数
     * @param  int  $intervalSeconds  每次轮询间隔（传给 $sleeper）
     * @param  callable(int):void  $sleeper  休眠实现（测试注入 no-op）
     */
    public static function poll(
        callable $fetchDetail,
        string $recordId,
        int $maxAttempts,
        int $intervalSeconds,
        callable $sleeper,
    ): void {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $resp = $fetchDetail();

            $total = $resp->getTotalCount();
            if ($total !== null) {
                $succeeded = (int) ($resp->getSuccessTotalCount() ?? 0);
                $failed = (int) ($resp->getFailedTotalCount() ?? 0);
                $total = (int) $total;

                if ($succeeded + $failed >= $total) {
                    if ($failed > 0) {
                        // 终态失败：抛业务错误（满足 ResumesRemoteJob 契约「终态失败抛 DeployBusinessException」→
                        // CloudDeployJob 清 pending + 终态 + 通知，不无谓重试）。变量后紧跟中文须加花括号。
                        throw new DeployBusinessException(
                            "腾讯云证书部署任务存在失败子任务（成功 {$succeeded}，失败 {$failed}，共 {$total}）"
                        );
                    }

                    // 全部成功，部署完成
                    return;
                }
            }
            // total===null：任务详情未就绪（腾讯侧刚建任务尚未展开子任务），继续等待

            if ($attempt < $maxAttempts - 1) {
                $sleeper($intervalSeconds); // 末次不 sleep（回收预算，timing M1）
            }
        }

        // 窗口耗尽仍未达终态：任务已提交、腾讯侧异步落地 → 携 recordId 抛 poll_pending（guardSdk 之外），
        // 走 CloudDeployJob 重试/sweep-B resumePoll 续查同一 DeployRecordId（不重建部署任务）。
        throw new DeployPollPendingException($recordId, '腾讯云证书部署任务处理中，待确认（任务已提交，稍后在控制台确认部署状态）');
    }
}
