<?php

use App\Services\Binary\BinaryLocator;
use App\Services\Nginx\NginxRenderer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->renderer = app(NginxRenderer::class);
    $this->testDir = storage_path('upgrades/test_nginx_renderer_'.uniqid());
    File::makeDirectory("$this->testDir/nginx", 0755, true);
});

afterEach(function () {
    if (File::isDirectory($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

test('render success: stub render.sh 被调用并写入哨兵文件', function () {
    $stub = "#!/usr/bin/env bash\nmkdir -p \"\$1/nginx/enabled/routes\"\nprintf 'ok' > \"\$1/nginx/enabled/routes/admin.conf\"\nexit 0\n";
    File::put("$this->testDir/nginx/render.sh", $stub);
    chmod("$this->testDir/nginx/render.sh", 0755);

    $result = $this->renderer->render($this->testDir);

    expect($result)->toBeTrue();
    expect("$this->testDir/nginx/enabled/routes/admin.conf")->toBeFile();
    expect(File::get("$this->testDir/nginx/enabled/routes/admin.conf"))->toBe('ok');
});

test('render non-fatal: render.sh 不存在时返回 false 不抛异常', function () {
    // 故意不创建 render.sh
    $result = $this->renderer->render($this->testDir);

    expect($result)->toBeFalse();
});

test('render non-fatal: stub render.sh exit 1 时返回 false 不抛异常', function () {
    File::put("$this->testDir/nginx/render.sh", "#!/usr/bin/env bash\nexit 1\n");
    chmod("$this->testDir/nginx/render.sh", 0755);

    $result = $this->renderer->render($this->testDir);

    expect($result)->toBeFalse();
});

test('render falls back to /bin/bash when BinaryLocator::bash() throws (frozen old class)', function () {
    // 仿"半换码进程里旧 BinaryLocator 无 bash()":bash() 抛 \Error
    $this->app->instance(BinaryLocator::class, new class extends BinaryLocator
    {
        public function bash(): string
        {
            throw new Error('Call to undefined method bash()');
        }
    });

    // stub render.sh:建 enabled/routes 并写哨兵文件，验证即便 bash() 抛错，render 仍经 /bin/bash 跑通
    $stub = implode("\n", [
        '#!/usr/bin/env bash',
        'mkdir -p "$1/nginx/enabled/routes"',
        'printf \'ok\' > "$1/nginx/enabled/routes/admin.conf"',
        'exit 0',
    ]);
    File::put("$this->testDir/nginx/render.sh", $stub);
    chmod("$this->testDir/nginx/render.sh", 0755);

    $result = $this->renderer->render($this->testDir);

    expect($result)->toBeTrue();
    expect("$this->testDir/nginx/enabled/routes/admin.conf")->toBeFile();
});

test('render 不因 render.sh 向 stderr 写满管道缓冲而死锁', function () {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('需要 pcntl + posix 扩展来检测死锁');
    }

    // render.sh 向 stderr 写 100KB（远超 Linux 管道缓冲 ~64KB）、stdout 近空、正常退出。
    // 「先 stream_get_contents(stdout) 读到 EOF 再读 stderr」的串行实现会与子进程
    // 「写满 stderr 阻塞」互锁（子进程卡在写 stderr 不退出 → stdout 永不 EOF）；
    // 并发排空两管道则不会。这正是同 PR 在 BinaryLocator 修掉的 proc_open 死锁同根因。
    $stub = "#!/usr/bin/env bash\nyes 'deadlock-canary' | head -c 100000 >&2\nexit 0\n";
    File::put("$this->testDir/nginx/render.sh", $stub);
    chmod("$this->testDir/nginx/render.sh", 0755);

    // 在子进程跑 render()，主进程带 15s 超时轮询。死锁时子进程永不退出，主进程超时即
    // SIGKILL 并判失败——避免「断言挂死整个测试套件」。子进程写完结果后用 SIGKILL 自杀，
    // 不触发 Pest shutdown handler 污染输出。
    $resultFile = "$this->testDir/render_result";
    $pid = pcntl_fork();
    if ($pid === 0) {
        $ok = $this->renderer->render($this->testDir);
        file_put_contents($resultFile, $ok ? 'true' : 'false');
        posix_kill(posix_getpid(), SIGKILL);
    }

    $deadline = time() + 15;
    $finished = false;
    while (time() < $deadline) {
        if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
            $finished = true;
            break;
        }
        usleep(200_000);
    }

    if (! $finished) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        throw new RuntimeException('render() 死锁：15 秒内未返回（被 stderr 管道缓冲阻塞）');
    }

    expect(File::isFile($resultFile))->toBeTrue();
    expect(File::get($resultFile))->toBe('true');
});
