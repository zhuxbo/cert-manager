<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\SkipWhenUpgradeFrozen;

/**
 * trait：声明 Job 接入 SkipWhenUpgradeFrozen Queue middleware。
 *
 * 所有 ShouldQueue 实现都必须 use 此 trait，以确保升级 freeze 期间
 * 不执行业务逻辑（队列暂停机制）。
 *
 * 使用：
 *
 *     class FooJob implements ShouldQueue
 *     {
 *         use HasUpgradeFreezeMiddleware;
 *         // ...
 *     }
 *
 * 反射守门测试 HasUpgradeFreezeMiddlewareReflectionTest 会扫描
 * app/Jobs/ 下所有 ShouldQueue 实现，缺失 trait 会失败。
 *
 * 如果某个 Job 已有自定义的 middleware()，请改名为 customMiddleware()
 * 后通过本 trait 的 middleware() 合并；当前 4 个内置 Job 均无此方法。
 *
 * @return list<object>
 */
trait HasUpgradeFreezeMiddleware
{
    /**
     * Queue middleware 列表（Laravel 自动调用）。
     *
     * 如果 Job 定义了 customMiddleware() 方法，自动合并到 SkipWhenUpgradeFrozen 之后。
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $custom = method_exists($this, 'customMiddleware') ? $this->customMiddleware() : [];

        return [new SkipWhenUpgradeFrozen, ...$custom];
    }
}
