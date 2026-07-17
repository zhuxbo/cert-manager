<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 长轮询 deployer 声明其 bind 最坏耗时预算，供 CloudDeployPollBudgetTest 计算断言 ≤50s。
 * 全仓仅 6 个长轮询 deployer（Aliyun CAS 托管 / Wangsu CDN Pro / Tencent COS / Tencent ssl-deploy /
 * Zenlayer CDN / Zenlayer GA）实现本接口。
 */
interface HasPollBudget
{
    public function pollBudget(): PollBudget;
}
