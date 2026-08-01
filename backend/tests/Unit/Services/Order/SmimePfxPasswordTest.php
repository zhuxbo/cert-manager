<?php

use App\Services\Notification\Exceptions\TransientBuildException;
use App\Services\Order\Action;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

test('S/MIME PFX 密码固定六位且排除易混字符并同时包含字母数字', function () {
    $action = app(Action::class);
    expect(method_exists($action, 'generateSmimePfxPassword'))->toBeTrue();

    $method = new ReflectionMethod($action, 'generateSmimePfxPassword');
    $samples = [];
    for ($i = 0; $i < 500; $i++) {
        $password = $method->invoke($action);
        $samples[] = $password;

        expect($password)->toMatch('/^[ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789]{6}$/')
            ->and($password)->toMatch('/[A-Za-z]/')
            ->and($password)->toMatch('/[2-9]/');
    }

    expect(count(array_unique($samples)))->toBeGreaterThan(1);
});

test('证书归档安全名移除路径穿越控制字符和平台保留字符并保留 Unicode', function () {
    $action = app(Action::class);
    $method = new ReflectionMethod($action, 'safeCertificateName');

    expect($method->invoke($action, '../folder\\bad:name?*'."\0\n".'测试', 42))
        ->toBe('folder-bad-name-STAR-测试')
        ->and($method->invoke($action, "../..\0", 42))
        ->toBe('certificate-42')
        ->and($method->invoke($action, '证书@example.test', 42))
        ->toBe('证书@example.test');
});

test('临时文件完整写入 helper 可处理短写且零进展时抛瞬态异常', function () {
    $action = new class extends Action
    {
        public bool $stall = false;

        public function writeFile(string $path, string $contents): void
        {
            $this->writeTemporaryFile($path, $contents);
        }

        /**
         * @param  resource  $stream
         */
        protected function writeTemporaryChunk($stream, string $contents): int|false
        {
            if ($this->stall) {
                return 0;
            }

            return fwrite($stream, substr($contents, 0, 1));
        }
    };
    $tempDir = sys_get_temp_dir().'/smime-write-'.bin2hex(random_bytes(8));
    mkdir($tempDir, 0700);
    $path = $tempDir.'/complete.bin';

    try {
        expect(method_exists($action, 'writeTemporaryFile'))->toBeTrue();

        $action->writeFile($path, 'complete-content');
        expect(file_get_contents($path))->toBe('complete-content');

        $action->stall = true;
        expect(fn () => $action->writeFile($tempDir.'/stalled.bin', 'secret'))
            ->toThrow(TransientBuildException::class);
    } finally {
        File::deleteDirectory($tempDir);
    }
});

test('OpenSSL stdin 密码在异常 trace 中由 PHP 引擎隐藏', function () {
    $secret = 'A2b3C4';
    $action = new class extends Action
    {
        public string $secret;

        public function failWhileWritingPassword(): void
        {
            $this->runProcess(['/bin/cat'], $this->secret);
        }

        /**
         * @param  resource  $stream
         */
        protected function writeTemporaryChunk($stream, #[SensitiveParameter] string $contents): int|false
        {
            return 0;
        }
    };
    $action->secret = $secret;
    $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        $action->failWhileWritingPassword();
        $this->fail('预期 OpenSSL 密码管道写入失败');
    } catch (TransientBuildException $exception) {
        $trace = $exception->getTrace();
        $traceStrings = [];
        array_walk_recursive($trace, function (mixed $value) use (&$traceStrings): void {
            if (is_string($value)) {
                $traceStrings[] = $value;
            }
        });
        $runProcessFrame = collect($trace)->first(
            fn (array $frame): bool => ($frame['function'] ?? null) === 'runProcess'
        );

        expect(implode("\n", $traceStrings))->not->toContain($secret)
            ->and($runProcessFrame)->not->toBeNull()
            ->and($runProcessFrame['args'][1] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);
    } finally {
        if ($previousIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
        }
    }
});
