<?php

namespace Plugins\CloudDeploy\Deployers\Tencent;

use Plugins\CloudDeploy\Deployers\Contracts\DeployBusinessException;
use Plugins\CloudDeploy\Deployers\Contracts\DeployPollPendingException;

/** 腾讯云「上传更新」任务的 common-json 详情响应终态判定。 */
class TencentUploadUpdateRecordPoller
{
    /**
     * @param  callable():mixed  $fetchDetail
     * @param  callable(int):void  $sleeper
     */
    public static function poll(callable $fetchDetail, string $recordId, int $maxAttempts, int $intervalSeconds, callable $sleeper): void
    {
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $records = self::records($fetchDetail());
            $running = $succeeded = $failed = $total = 0;
            foreach ($records as $record) {
                $running = self::checkedAdd($running, $record['RunningTotalCount']);
                $succeeded = self::checkedAdd($succeeded, $record['SuccessTotalCount']);
                $failed = self::checkedAdd($failed, $record['FailedTotalCount']);
                $total = self::checkedAdd($total, $record['TotalCount']);
            }

            $terminal = self::checkedAdd($succeeded, $failed);
            if ($terminal > $total || $running > $total - $terminal) {
                throw new DeployBusinessException('腾讯云上传更新证书任务详情响应异常');
            }

            if ($terminal === $total) {
                if ($failed > 0) {
                    throw new DeployBusinessException("腾讯云证书部署任务存在失败子任务（成功 {$succeeded}，失败 {$failed}，共 {$total}）");
                }

                return;
            }

            if ($attempt < $maxAttempts - 1) {
                $sleeper($intervalSeconds);
            }
        }

        throw new DeployPollPendingException($recordId, '腾讯云证书部署任务处理中，待确认（任务已提交，稍后在控制台确认部署状态）');
    }

    /** @return list<array{RunningTotalCount:int,SuccessTotalCount:int,FailedTotalCount:int,TotalCount:int}> */
    private static function records(mixed $response): array
    {
        if (! is_array($response) || array_key_exists('Message', $response) || ! is_array($response['DeployRecordDetail'] ?? null) || $response['DeployRecordDetail'] === []) {
            throw new DeployBusinessException('腾讯云上传更新证书任务详情响应异常');
        }

        $records = [];
        foreach ($response['DeployRecordDetail'] as $record) {
            if (! is_array($record)
                || ! is_int($record['RunningTotalCount'] ?? null)
                || ! is_int($record['SuccessTotalCount'] ?? null)
                || ! is_int($record['FailedTotalCount'] ?? null)
                || ! is_int($record['TotalCount'] ?? null)
                || $record['RunningTotalCount'] < 0
                || $record['SuccessTotalCount'] < 0
                || $record['FailedTotalCount'] < 0
                || $record['TotalCount'] <= 0) {
                throw new DeployBusinessException('腾讯云上传更新证书任务详情响应异常');
            }
            $terminal = self::checkedAdd($record['SuccessTotalCount'], $record['FailedTotalCount']);
            if ($terminal > $record['TotalCount']
                || $record['RunningTotalCount'] > $record['TotalCount'] - $terminal) {
                throw new DeployBusinessException('腾讯云上传更新证书任务详情响应异常');
            }
            $records[] = $record;
        }

        return $records;
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new DeployBusinessException('腾讯云上传更新证书任务详情响应异常');
        }

        return $left + $right;
    }
}
