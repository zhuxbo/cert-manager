<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Acme;

/**
 * ACME refer_id 应用层防重
 *
 * acmes.refer_id 列为全局 unique（与 certs 表一致），DB 层兜底；
 * 入口提前查重给出友好错误，避免落库后才触发 unique 异常。
 *
 * 依赖宿主类已 use ApiResponse（Controller 基类已默认引入）以调用 $this->error()。
 */
trait AcmeReferIdCheck
{
    /**
     * 校验 refer_id 是否已被使用（限当前用户范围内）
     *
     * @param  string  $referId  下游传入的 refer_id；为空字符串时跳过
     * @param  int  $userId  当前认证用户 id
     */
    protected function checkAcmeReferId(string $referId, int $userId): void
    {
        if ($referId === '') {
            return;
        }

        $exists = Acme::where('refer_id', $referId)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            $this->error('Refer id already exists');
        }
    }
}
