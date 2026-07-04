<?php

use App\Services\Plugin\PluginComposerRunner;
use App\Services\Plugin\PluginManager;
use App\Services\Upgrade\VersionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

afterEach(function () {
    Schema::dropIfExists('rollback_plugin_test_runtime');
    Schema::dropIfExists('rollback_plugin_timeout_runtime');
    Schema::dropIfExists('rollback_plugin_timeout_child');
    Schema::dropIfExists('rollback_plugin_never_runtime');
    Schema::dropIfExists('rollback_plugin_never_should_remain');
    DB::table('migrations')->where('migration', '2026_07_04_000000_create_rollback_plugin_test_runtime')->delete();
    DB::table('migrations')->where('migration', '2026_07_04_000001_create_rollback_plugin_timeout_runtime')->delete();
    DB::table('migrations')->where('migration', '2026_07_04_000002_create_rollback_plugin_timeout_child')->delete();
    DB::table('migrations')->where('migration', '2026_07_04_000003_never_run_rollback_plugin_migration')->delete();
    DB::table('admin_logs')->where('module', 'rollback-plugin-test')->delete();
    File::deleteDirectory(base_path('../plugins/rollback-plugin-test'));
    File::deleteDirectory(base_path('../plugins/rollback-timeout-test'));
    foreach (glob(sys_get_temp_dir().'/pmr-*') ?: [] as $path) {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        } else {
            @unlink($path);
        }
    }
});

function makeRollbackPluginZip(): string
{
    $zipPath = sys_get_temp_dir().'/pmr-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('rollback-plugin-test/plugin.json', json_encode([
        'name' => 'rollback-plugin-test',
        'version' => '1.0.0',
    ]));
    $zip->addFromString(
        'rollback-plugin-test/backend/migrations/2026_07_04_000000_create_rollback_plugin_test_runtime.php',
        <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollback_plugin_test_runtime', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollback_plugin_test_runtime');
    }
};
PHP
    );
    $zip->addFromString(
        'rollback-plugin-test/backend/Seeders/PluginSeeder.php',
        <<<'PHP'
<?php

namespace Plugins\RollbackPluginTest\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PluginSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('admin_logs')->insert([
            'module' => 'rollback-plugin-test',
            'action' => 'seed',
            'method' => 'CLI',
            'url' => 'plugin:seed-transaction',
            'status' => 0,
            'created_at' => now(),
        ]);

        throw new RuntimeException('seed boom');
    }
}
PHP
    );
    $zip->close();

    return $zipPath;
}

function makeTimeoutRollbackPluginZip(): string
{
    $zipPath = sys_get_temp_dir().'/pmr-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('rollback-timeout-test/plugin.json', json_encode([
        'name' => 'rollback-timeout-test',
        'version' => '1.0.0',
    ]));
    $zip->addFromString(
        'rollback-timeout-test/backend/migrations/2026_07_04_000001_create_rollback_plugin_timeout_runtime.php',
        <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollback_plugin_timeout_runtime', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollback_plugin_timeout_runtime');
    }
};
PHP
    );
    $zip->addFromString(
        'rollback-timeout-test/backend/migrations/2026_07_04_000002_create_rollback_plugin_timeout_child.php',
        <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollback_plugin_timeout_child', function (Blueprint $table) {
            $table->id();
        });

        sleep(5);
    }

    public function down(): void
    {
        if (! Schema::hasTable('rollback_plugin_timeout_runtime')) {
            throw new RuntimeException('parent table missing');
        }

        Schema::dropIfExists('rollback_plugin_timeout_child');
    }
};
PHP
    );
    $zip->addFromString(
        'rollback-timeout-test/backend/migrations/2026_07_04_000003_never_run_rollback_plugin_migration.php',
        <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollback_plugin_never_runtime', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollback_plugin_never_should_remain');
    }
};
PHP
    );
    $zip->close();

    return $zipPath;
}

test('installFromZip 在迁移成功但 seeder 失败时回滚本次新增迁移', function () {
    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(false);
    $runner->shouldNotReceive('install');

    $manager = new PluginManager(Mockery::mock(VersionManager::class), $runner);
    $ref = new ReflectionClass($manager);
    $ref->getProperty('pluginsPath')->setValue($manager, base_path('../plugins'));
    $downloadPath = sys_get_temp_dir().'/pmr-download-'.uniqid();
    File::makeDirectory($downloadPath, 0755, true);
    $ref->getProperty('downloadPath')->setValue($manager, $downloadPath);

    expect(fn () => $manager->installFromZip(makeRollbackPluginZip()))
        ->toThrow(RuntimeException::class, 'seed boom');

    expect(Schema::hasTable('rollback_plugin_test_runtime'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2026_07_04_000000_create_rollback_plugin_test_runtime')->exists())->toBeFalse()
        ->and(DB::table('admin_logs')->where('module', 'rollback-plugin-test')->exists())->toBeFalse()
        ->and(is_dir(base_path('../plugins/rollback-plugin-test')))->toBeFalse();
});

test('installFromZip 在 recorded 与 unrecorded 混合失败时按反向顺序精确回滚', function () {
    config()->set('plugin.operations.artisan_timeout', 2);
    Schema::create('rollback_plugin_never_should_remain', function ($table) {
        $table->id();
    });

    $runner = Mockery::mock(PluginComposerRunner::class);
    $runner->shouldReceive('pluginHasComposer')->andReturn(false);
    $runner->shouldNotReceive('install');

    $manager = new PluginManager(Mockery::mock(VersionManager::class), $runner);
    $ref = new ReflectionClass($manager);
    $ref->getProperty('pluginsPath')->setValue($manager, base_path('../plugins'));
    $downloadPath = sys_get_temp_dir().'/pmr-download-'.uniqid();
    File::makeDirectory($downloadPath, 0755, true);
    $ref->getProperty('downloadPath')->setValue($manager, $downloadPath);

    expect(fn () => $manager->installFromZip(makeTimeoutRollbackPluginZip()))
        ->toThrow(RuntimeException::class, '插件 rollback-timeout-test 迁移失败');

    expect(Schema::hasTable('rollback_plugin_timeout_runtime'))->toBeFalse()
        ->and(Schema::hasTable('rollback_plugin_timeout_child'))->toBeFalse()
        ->and(Schema::hasTable('rollback_plugin_never_should_remain'))->toBeTrue()
        ->and(Schema::hasTable('rollback_plugin_never_runtime'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2026_07_04_000001_create_rollback_plugin_timeout_runtime')->exists())->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2026_07_04_000002_create_rollback_plugin_timeout_child')->exists())->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2026_07_04_000003_never_run_rollback_plugin_migration')->exists())->toBeFalse()
        ->and(is_dir(base_path('../plugins/rollback-timeout-test')))->toBeFalse();
});
