<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Compat\Helpers;
use Tests\Compat\SnapshotListener;

abstract class TestCase extends BaseTestCase
{
    /**
     * 启动应用时同时挂 Compat 钩子（仅 capture/compare 模式启用，否则零开销）。
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Helpers::isCaptureMode() || Helpers::isCompareMode()) {
            SnapshotListener::register();
            SnapshotListener::setCurrentTest($this->toString());
        }
    }

    protected function tearDown(): void
    {
        if (Helpers::isCaptureMode() || Helpers::isCompareMode()) {
            SnapshotListener::finalizeTest($this->toString());
            SnapshotListener::setCurrentTest(null);
        }

        parent::tearDown();
    }
}
