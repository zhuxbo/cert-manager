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
