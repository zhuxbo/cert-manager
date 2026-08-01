<?php

use App\Support\Opcache;

// 锁定 Opcache::reset() 的结果分类：扩展未加载 / 当前 SAPI 未启用 / 重置成功 /
// opcache_reset() 返回 false / API 被 restrict_api 限制。第三种在真机上是 PHP 发
// E_WARNING → Laravel（error_reporting = -1）转成 ErrorException，不接住会中断升级；
// restrict_api 是 PHP_INI_SYSTEM、同进程改不了，故用替身在探测点抛出等价异常。
// 不用 TestCase：本类不碰框架、不碰 DB。

/** 把三个真实 opcache 调用点换成可控替身 */
class FakeOpcache extends Opcache
{
    public bool $hasExtension = true;

    public bool $isEnabled = true;

    public bool $resetReturns = true;

    public ?Throwable $probeThrows = null;

    public string $fakeSapi = 'cli';

    public int $resetCalls = 0;

    protected function available(): bool
    {
        return $this->hasExtension;
    }

    protected function enabled(): bool
    {
        if ($this->probeThrows !== null) {
            throw $this->probeThrows;
        }

        return $this->isEnabled;
    }

    protected function doReset(): bool
    {
        $this->resetCalls++;

        return $this->resetReturns;
    }

    protected function sapi(): string
    {
        return $this->fakeSapi;
    }
}

test('扩展未加载时跳过且不调用 opcache_reset', function () {
    $opcache = new FakeOpcache;
    $opcache->hasExtension = false;

    $result = $opcache->reset();

    expect($result['status'])->toBe(Opcache::SKIPPED)
        ->and($result['reason'])->toBe('extension_not_loaded')
        ->and($opcache->resetCalls)->toBe(0);
});

test('当前 SAPI 未启用 OPcache 时按跳过处理，不算失败', function () {
    $opcache = new FakeOpcache;
    $opcache->isEnabled = false;

    $result = $opcache->reset();

    expect($result['status'])->toBe(Opcache::SKIPPED)
        ->and($result['reason'])->toBe('not_enabled')
        ->and($opcache->resetCalls)->toBe(0);
});

test('启用且重置成功时返回 ok', function () {
    $opcache = new FakeOpcache;

    $result = $opcache->reset();

    expect($result['status'])->toBe(Opcache::OK)
        ->and($result['reason'])->toBeNull()
        ->and($opcache->resetCalls)->toBe(1);
});

test('opcache_reset 返回 false 时报 failed', function () {
    $opcache = new FakeOpcache;
    $opcache->resetReturns = false;

    $result = $opcache->reset();

    expect($result['status'])->toBe(Opcache::FAILED)
        ->and($result['reason'])->toBe('reset_returned_false');
});

test('restrict_api 抛 ErrorException 被就地接住，不外泄', function () {
    $opcache = new FakeOpcache;
    $opcache->probeThrows = new ErrorException(
        'Zend OPcache API is restricted by "restrict_api" configuration directive'
    );

    $result = $opcache->reset();

    expect($result['status'])->toBe(Opcache::SKIPPED)
        ->and($result['reason'])->toBe('api_restricted')
        ->and($result['message'])->toContain('restrict_api');
});

test('reset 阶段抛出的异常同样被接住', function () {
    // 探测通过后在真正 reset 时抛（restrict_api 场景下两个调用点都会抛）
    $throwing = new class extends FakeOpcache
    {
        protected function doReset(): bool
        {
            throw new ErrorException('restrict_api');
        }
    };

    expect($throwing->reset()['status'])->toBe(Opcache::SKIPPED);
});

test('结果携带当前 SAPI，isCli 只认命令行进程', function () {
    $cli = new FakeOpcache;
    $cli->fakeSapi = 'cli';

    $fpm = new FakeOpcache;
    $fpm->fakeSapi = 'fpm-fcgi';

    expect($cli->reset()['sapi'])->toBe('cli')
        ->and($cli->isCli())->toBeTrue()
        ->and($fpm->reset()['sapi'])->toBe('fpm-fcgi')
        ->and($fpm->isCli())->toBeFalse();
});

// 真实实现只断"不抛 + sapi 正确"：状态分类随宿主 ini 变化，断具体值等于把环境写进断言
test('真实实现在测试进程（CLI 默认 opcache.enable_cli=0）下不抛异常', function () {
    expect(fn () => (new Opcache)->reset())->not->toThrow(Throwable::class)
        ->and((new Opcache)->reset()['sapi'])->toBe(PHP_SAPI);
});

test('非 restrict_api 的异常按 failed/reset_threw 记，不误贴受限标签', function () {
    $throwing = new class extends FakeOpcache
    {
        protected function doReset(): bool
        {
            throw new RuntimeException('shared memory corrupted');
        }
    };

    $result = $throwing->reset();

    expect($result['status'])->toBe(Opcache::FAILED)
        ->and($result['reason'])->toBe('reset_threw')
        ->and($result['message'])->toContain('shared memory');
});
