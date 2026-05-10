<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 启动应用时同时挂 Compat 钩子（仅 capture/compare 模式启用，否则零开销）。
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (\Tests\Compat\Helpers::isCaptureMode() || \Tests\Compat\Helpers::isCompareMode()) {
            \Tests\Compat\SnapshotListener::register();
            \Tests\Compat\SnapshotListener::setCurrentTest($this->toString());
        }
    }

    protected function tearDown(): void
    {
        if (\Tests\Compat\Helpers::isCaptureMode() || \Tests\Compat\Helpers::isCompareMode()) {
            \Tests\Compat\SnapshotListener::finalizeTest($this->toString());
            \Tests\Compat\SnapshotListener::setCurrentTest(null);
        }

        parent::tearDown();
    }
}
