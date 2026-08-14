<?php

use App\Support\ApplicationBootstrapLock;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->root = storage_path('framework/testing/bootstrap-lock-'.bin2hex(random_bytes(4)));
    mkdir($this->root.'/source/public', 0777, true);
    mkdir($this->root.'/target/backend/public', 0777, true);

    $snippet = <<<'PHP'
// SSL_MANAGER_BOOTSTRAP_LOCK_V1_BEGIN
$handle = @fopen(__DIR__.'/../../.upgrade-bootstrap.lock', 'c');
if ($handle !== false) {
    flock($handle, LOCK_SH);
}
// SSL_MANAGER_BOOTSTRAP_LOCK_V1_END
PHP;
    file_put_contents(
        $this->root.'/source/public/index.php',
        "<?php\n\n{$snippet}\n\n// Register the Composer autoloader...\n"
    );
    file_put_contents(
        $this->root.'/target/backend/public/index.php',
        "<?php\n\n// legacy entry\n// Register the Composer autoloader...\n"
    );
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

test('首次采用会原子注入共享锁片段并记录排空状态', function () {
    $source = $this->root.'/source/public/index.php';
    $target = $this->root.'/target/backend/public/index.php';

    ApplicationBootstrapLock::prepareLegacyHttpEntry($source, $target, 0);

    $contents = file_get_contents($target);
    $state = json_decode(file_get_contents($this->root.'/target/.upgrade-bootstrap-prepared.json'), true);

    expect(substr_count($contents, 'SSL_MANAGER_BOOTSTRAP_LOCK_V1_BEGIN'))->toBe(1)
        ->and($contents)->toContain('// legacy entry')
        ->and($contents)->toContain('// Register the Composer autoloader...')
        ->and($state['status'])->toBe('draining')
        ->and($state['ready_at'])->toBeGreaterThanOrEqual($state['started_at']);
});

test('注入后中断重试仍等待状态文件中的剩余排空时间', function () {
    $source = $this->root.'/source/public/index.php';
    $target = $this->root.'/target/backend/public/index.php';

    ApplicationBootstrapLock::prepareLegacyHttpEntry($source, $target, 0);
    file_put_contents($this->root.'/target/.upgrade-bootstrap-prepared.json', json_encode([
        'status' => 'draining',
        'started_at' => time(),
        'ready_at' => time() + 1,
    ]));

    $started = microtime(true);
    ApplicationBootstrapLock::prepareLegacyHttpEntry($source, $target, 0);

    expect(microtime(true) - $started)->toBeGreaterThanOrEqual(0.8)
        ->and(substr_count(file_get_contents($target), 'SSL_MANAGER_BOOTSTRAP_LOCK_V1_BEGIN'))->toBe(1);
});

test('入口原生带锁且没有首次准备状态时不增加固定等待', function () {
    $source = $this->root.'/source/public/index.php';
    $target = $this->root.'/target/backend/public/index.php';
    copy($source, $target);

    $started = microtime(true);
    ApplicationBootstrapLock::prepareLegacyHttpEntry($source, $target, 2);

    expect(microtime(true) - $started)->toBeLessThan(0.5)
        ->and(file_exists($this->root.'/target/.upgrade-bootstrap-prepared.json'))->toBeFalse();
});

test('旧入口缺少稳定锚点时在改动文件前失败关闭', function () {
    $source = $this->root.'/source/public/index.php';
    $target = $this->root.'/target/backend/public/index.php';
    file_put_contents($target, "<?php\n// unknown legacy entry\n");

    expect(fn () => ApplicationBootstrapLock::prepareLegacyHttpEntry($source, $target, 0))
        ->toThrow(RuntimeException::class, '缺少 Composer 加载锚点');
    expect(file_get_contents($target))->toBe("<?php\n// unknown legacy entry\n");
});

test('独占锁文件无法打开时抛出稳定的领域错误', function () {
    $originalBase = base_path();
    File::makeDirectory($this->root.'/target/.upgrade-bootstrap.lock', 0777, true);
    app()->setBasePath($this->root.'/target/backend');

    try {
        expect(fn () => ApplicationBootstrapLock::acquireExclusive())
            ->toThrow(RuntimeException::class, '无法创建应用启动切换锁');
    } finally {
        app()->setBasePath($originalBase);
    }
});
