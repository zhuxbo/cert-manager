<?php

namespace Plugins\CloudDeploy\Deployers\Contracts;

/**
 * 动态 Inline 端点仅在能把厂商任务标识收窄为无敏感材料的 opaque ID 时实现。
 * CloudDeployJob 只为该 opt-in 能力持久化并续查动态 Inline 任务。
 */
interface PersistsOpaqueInlineJobId extends ResumesRemoteJob
{
    /** 返回可安全落库的 canonical ID；无效、含敏感材料或超出厂商范围时返回 null。 */
    public function canonicalOpaqueInlineJobId(string $remoteJobId): ?string;
}
