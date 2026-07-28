<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Tests\Compat\Helpers;
use Tests\Compat\SnapshotListener;
use Tests\Support\PublicSuffixListFixture;

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
        // bootstrap/cache（services.php/packages.php）在 paratest 多 worker 并发首次编译时
        // 偶发 "Failed to open stream" —— 用兜底重试器包裹启动，清半态缓存 + 退避重试
        // （详见 bootstrap/resilient.php，与 public/index.php、artisan 同一套全局兜底）。
        $resilient = require dirname(__DIR__).'/bootstrap/resilient.php';
        $app = $resilient(fn () => parent::createApplication(), dirname(__DIR__).'/bootstrap/cache');

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
     * 测试 storage 隔离：把运行时 storage 路径重定向到本进程专属目录，避免测试写/删
     * 真实磁盘。paratest 下用它注入的 TEST_TOKEN 分 worker，避免跨进程文件竞争（如
     * PurgeCommand 扫 verification 根，按本 worker DB 判孤立，误删其他 worker 正在用的
     * verification/{orderId} 文件 → file_exists 偶发 false）。
     *
     * **单进程跑（php artisan test <文件>，无 TEST_TOKEN）同样隔离**，token 回落固定
     * 'single'：定向跑法是文档化的常规用法（AGENTS.md / finish-check /
     * composer test:snapshot），不隔离会让测试直接改真实 storage —— 已踩：支付设置类
     * 测试经 Setting::clearGroupCache → PayConfigCache::forget 删掉开发环境 storage/pay
     * 下的真实支付证书，且留下测试写的假证书；getPayConfig 只在文件缺失时才按设置重写，
     * 于是该环境此后一直用假证书签名、静默不可用。
     *
     * token 用固定值而非 pid：pid 每跑一个新目录，① 目录无界堆积；② 目录内的跨运行缓存
     * （如 DomainUtil 的 storage/domain-rules/public_suffix_list.dat，30 天 TTL）每跑都缺失
     * → 每跑实网重抓公共后缀表，断网时 DomainUtilTest 直接红、上游改一条后缀就自发飘红。
     * 固定 token 让单进程与 paratest worker 一样"首跑落缓存、后续复用"。
     *
     * 但首跑仍是空目录（全新克隆 / 干净 CI / 新增 worker），故建好目录后直接把仓内 PSL 快照
     * 灌进该缓存位（见 PublicSuffixListFixture）：测试彻底离线且不随上游改表飘红，
     * 生产代码 DomainUtil 一行不改、线上照旧抓最新表。
     *
     * storage_path() 与 Storage 门面（local/public disk）同步隔离，保持二者路径一致
     * （生产同为默认路径，对称）。framework 的 cache/log/session 用 bootstrap 时 config
     * 已解析的默认路径，不受影响。
     */
    private function isolateWorkerStorage(): void
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false || $token === '') {
            $token = 'single';
        }

        $workerStorage = storage_path('framework/testing/worker-'.$token);
        @mkdir($workerStorage.'/framework', 0755, true);
        @mkdir($workerStorage.'/app/public', 0755, true);
        PublicSuffixListFixture::seed($workerStorage);

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
