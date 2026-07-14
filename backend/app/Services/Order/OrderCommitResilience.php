<?php

namespace App\Services\Order;

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;

/**
 * API 一条龙下单的 commit 段韧性原语（单一真相源）。
 *
 * V1/V2/Deploy 三个入口的 getData 曾各写一份逐字相同的 try-catch：**仅 commit 段**吞 SDK code=0
 * 与 MutationBusyException（订单停 pending、已扣费不回滚，靠 reconcile / 下游 pull 自愈），其它段
 * （new/renew/reissue/pay）照常报错。收敛到此处消除拷贝漂移——下次调整吞并集合只改一处，杜绝
 * 「改了 V1/V2 漏改 Deploy → 该入口冒泡 500/503、下游按失败重试建单双扣费」的 P0 形态。
 *
 * 【吞并边界是 P0 资金韧性红线，一条都不许挪】见 CLAUDE.md「API 一条龙下单韧性」段：
 * 仅 action==='commit' 段吞 code=0 + MutationBusyException、扣费不回滚。
 *
 * 【Deploy M-2 不对称由传入 $action 值天然保留】unpaid resume 走 pay(autoCommit=true)，其
 * MutationBusyException 经此以 $action='pay'≠'commit' 仍上抛 503——既有行为、有意不改，本原语
 * 忠实按传入 $action==='commit' 判断即自动保留该不对称，不做特殊处理。
 */
final class OrderCommitResilience
{
    /**
     * @param  callable():void  $invoke  执行目标 action（内部经 $this->success()/$this->error() 抛 ApiResponseException）
     * @param  string  $action  动作名，仅当 === 'commit' 时吞 code=0 / MutationBusyException
     * @param  callable(array):never  $onError  code=0 且非 commit 时的失败出口（调用方 $this->error(msg, errors)，
     *                                          重新构造 ApiResponseException 抛出——非 rethrow 原 $e，保持既有响应体）
     * @return array 成功响应（code=1）原样返回；commit 段被吞返回 []
     */
    public static function run(callable $invoke, string $action, callable $onError): array
    {
        try {
            $invoke();
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();
            if ($result['code'] === 0) {
                // commit 段：超时/失败（SDK code=0）不冒泡——订单停 pending、已扣费保留，靠对账/下游 pull 自愈。
                // 其它段保持原样：建单/扣费失败照常报错，触发本次请求失败。
                if ($action === 'commit') {
                    return [];
                }
                $onError($result);
            }
            // code===1（commit 成功由 success 抛出）落到末尾 return $result
        } catch (MutationBusyException $e) {
            // commit 段抢锁忙 = 成功态，不外抛 503（同 code=0，靠 pull/对账自愈）；其它经 mutex 路径向上抛。
            if ($action === 'commit') {
                return [];
            }
            throw $e;
        }

        return $result ?? [];
    }
}
