<?php

declare(strict_types=1);

// 不要在此文件加 namespace —— 这是给测试用例的全局辅助函数。

if (! function_exists('expectsBreakingChange')) {
    /**
     * 在测试用例顶部声明本次响应 schema 变化是预期的破坏性变更。
     *
     * 仅在 compare 模式下生效，会跳过该用例的 schema 校验。
     * capture 模式下会刷新 fixture（原 fixture 被新 schema 覆盖），
     * 同时把 reason 追加到 tests/Compat/BREAKING_CHANGES.md。
     *
     * 用法：
     *   test('admin can update user', function () {
     *       expectsBreakingChange('v1.1.0: 移除 user.password，改用 reset-password 端点');
     *       // ...
     *   });
     *
     * 注意：本函数在测试 closure 内可调用；listener 会在 RequestHandled 时检查标记。
     */
    function expectsBreakingChange(string $reason): void
    {
        \Tests\Compat\SnapshotListener::markBreakingChange($reason);
    }
}
