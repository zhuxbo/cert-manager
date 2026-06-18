<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Tests\Compat\Helpers;
use Tests\Compat\SnapshotListener;

abstract class TestCase extends BaseTestCase
{
    /**
     * 物理阻断：测试库名必须含 '_test'，否则拒绝运行。
     *
     * phpunit.xml 的 DB_DATABASE force 只覆盖 .env/.env.testing 文件值，不覆盖 OS
     * 环境变量（docker -e / shell export）。此处在 createApplication 之后、
     * RefreshDatabase 清库之前做运行期兜底，杜绝任何跑法把测试跑进开发库 ssl_manager。
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $conn = (string) $app['config']->get('database.default');
        $db = (string) $app['config']->get("database.connections.{$conn}.database");
        if (! str_contains($db, '_test')) {
            fwrite(STDERR, "\n[FATAL] 测试库名 \"{$db}\" 不含 '_test'，已拒绝运行以防清空非测试库。\n");
            exit(1);
        }

        return $app;
    }

    /**
     * 启动应用时同时挂 Compat 钩子（仅 capture/compare 模式启用，否则零开销）。
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateWorkerStorage();

        if (Helpers::isCaptureMode() || Helpers::isCompareMode()) {
            SnapshotListener::register();
            SnapshotListener::setCurrentTest($this->toString());
        }
    }

    /**
     * 并行测试 storage 隔离：paratest 给每个 worker 注入 TEST_TOKEN，据此把运行时
     * storage 路径重定向到 worker 专属目录，避免多 worker 共享真实磁盘产生跨进程
     * 文件竞争（如 PurgeCommand 扫 verification 根，按本 worker DB 判孤立，误删其他
     * worker 正在用的 verification/{orderId} 文件 → file_exists 偶发 false）。
     *
     * storage_path() 与 Storage 门面（local/public disk）同步隔离，保持二者路径一致
     * （生产同为默认路径，对称）。framework 的 cache/log/session 用 bootstrap 时 config
     * 已解析的默认路径，不受影响。单进程跑（无 TEST_TOKEN）直接返回，零侵入。
     */
    private function isolateWorkerStorage(): void
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false || $token === '') {
            return;
        }

        $workerStorage = storage_path('framework/testing/worker-'.$token);
        @mkdir($workerStorage.'/app/public', 0755, true);

        $this->app->useStoragePath($workerStorage);
        config([
            'filesystems.disks.local.root' => $workerStorage.'/app',
            'filesystems.disks.public.root' => $workerStorage.'/app/public',
        ]);
        Storage::forgetDisk(['local', 'public']);
    }

    protected function tearDown(): void
    {
        try {
            // compare 模式下 finalizeTest 命中 diff 会 Assert::fail 抛异常；
            // 必须用 finally 兜住，否则会跳过 parent::tearDown()（含 RefreshDatabase
            // 事务 rollback），导致连接持锁泄漏 + 事务层级漂移，串行跑全套时雪崩
            // 成 Lock wait timeout。
            if (Helpers::isCaptureMode() || Helpers::isCompareMode()) {
                SnapshotListener::finalizeTest($this->toString());
                SnapshotListener::setCurrentTest(null);
            }
        } finally {
            parent::tearDown();
        }
    }
}
