<?php

use App\Models\User;
use App\Services\UserData\UserDataExporter;
use App\Services\UserData\UserDataImporter;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\TestCase;

uses(TestCase::class);

test('导出文件无法创建时抛出稳定的领域错误', function () {
    $originalStoragePath = storage_path();
    $storagePath = sys_get_temp_dir().'/user_export_open_failure_'.uniqid();
    mkdir($storagePath);
    app()->useStoragePath($storagePath);

    File::shouldReceive('ensureDirectoryExists')->once();

    $user = new User;
    $user->id = 123;
    $pathPrefix = "$storagePath/app/private/exports/users/123_";

    try {
        $exporter = new UserDataExporter(Mockery::mock(OutputInterface::class));

        try {
            $exporter->export($user);
            test()->fail('预期导出文件创建失败');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toStartWith("无法创建导出文件：$pathPrefix")
                ->toEndWith('.sql');
        }
    } finally {
        app()->useStoragePath($originalStoragePath);
        expect(@rmdir($storagePath))->toBeTrue();
    }
});

test('导入文件无法打开时抛出稳定的领域错误', function () {
    $scheme = 'userdatafailopen';
    stream_wrapper_register($scheme, UserDataFailingOpenStream::class);
    $path = "$scheme://export.sql";

    try {
        expect(file_exists($path))->toBeTrue();

        $importer = new UserDataImporter(Mockery::mock(OutputInterface::class));
        $method = new ReflectionMethod($importer, 'validateFile');

        expect(fn () => $method->invoke($importer, $path, 123))
            ->toThrow(RuntimeException::class, "无法打开导入文件：$path");
    } finally {
        stream_wrapper_unregister($scheme);
    }
});

final class UserDataFailingOpenStream
{
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    /** @return array{mode:int,size:int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100644, 'size' => 0];
    }
}
