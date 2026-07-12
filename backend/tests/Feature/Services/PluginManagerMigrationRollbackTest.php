<?php

use App\Services\Plugin\PluginComposerRunner;
use App\Services\Plugin\PluginManager;
use App\Services\Upgrade\VersionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 独立 PDO 连接查询/清理。
 *
 * 迁移子进程（plugin:migrate）以独立 Laravel 进程运行，其 DDL 与 migrations 行已提交入库；
 * 主进程测试跑在 RefreshDatabase 长事务里，其快照隔离看不到这些提交、MySQL 8.0 的
 * information_schema 又在事务内快照 —— 用主连接的 Schema::hasTable / migrations 查询会读到旧值
 * （8.4 假绿、5.7 暴露）。改从主连接同一份 config 另起独立 PDO（自动提交、无长事务），直读真实
 * 提交状态。清理同理走独立连接：主连接在事务内 DELETE migrations 会随 rollback 撤销而清不掉已提交行，
 * 主连接 DDL 又会隐式提交污染 RefreshDatabase。
 */
function freshDbQuery(): object
{
    $config = config('database.connections.mysql');
    $host = is_array($config['host']) ? $config['host'][0] : $config['host'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $host,
        $config['port'] ?? 3306,
        $config['database'],
        $config['charset'] ?? 'utf8mb4',
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    return new class($pdo)
    {
        public function __construct(private PDO $pdo) {}

        public function tableExists(string $table): bool
        {
            // 全量列举后精确成员判定，避开 LIKE 下划线通配符的误匹配
            $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

            return in_array($table, $tables, true);
        }

        public function migrationExists(string $migration): bool
        {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration = ?');
            $stmt->execute([$migration]);

            return (int) $stmt->fetchColumn() > 0;
        }

        public function dropTableIfExists(string $table): void
        {
            $this->pdo->exec('DROP TABLE IF EXISTS `'.str_replace('`', '', $table).'`');
        }

        public function deleteMigration(string $migration): void
        {
            $stmt = $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?');
            $stmt->execute([$migration]);
        }
    };
}

afterEach(function () {
    $db = freshDbQuery();
    $db->dropTableIfExists('rollback_plugin_test_runtime');
    $db->dropTableIfExists('rollback_plugin_timeout_runtime');
    $db->dropTableIfExists('rollback_plugin_timeout_child');
    $db->dropTableIfExists('rollback_plugin_never_runtime');
    $db->dropTableIfExists('rollback_plugin_never_should_remain');
    $db->deleteMigration('2026_07_04_000000_create_rollback_plugin_test_runtime');
    $db->deleteMigration('2026_07_04_000001_create_rollback_plugin_timeout_runtime');
    $db->deleteMigration('2026_07_04_000002_create_rollback_plugin_timeout_child');
    $db->deleteMigration('2026_07_04_000003_never_run_rollback_plugin_migration');
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

    $db = freshDbQuery();
    expect($db->tableExists('rollback_plugin_test_runtime'))->toBeFalse()
        ->and($db->migrationExists('2026_07_04_000000_create_rollback_plugin_test_runtime'))->toBeFalse()
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

    $db = freshDbQuery();
    expect($db->tableExists('rollback_plugin_timeout_runtime'))->toBeFalse()
        ->and($db->tableExists('rollback_plugin_timeout_child'))->toBeFalse()
        ->and($db->tableExists('rollback_plugin_never_should_remain'))->toBeTrue()
        ->and($db->tableExists('rollback_plugin_never_runtime'))->toBeFalse()
        ->and($db->migrationExists('2026_07_04_000001_create_rollback_plugin_timeout_runtime'))->toBeFalse()
        ->and($db->migrationExists('2026_07_04_000002_create_rollback_plugin_timeout_child'))->toBeFalse()
        ->and($db->migrationExists('2026_07_04_000003_never_run_rollback_plugin_migration'))->toBeFalse()
        ->and(is_dir(base_path('../plugins/rollback-timeout-test')))->toBeFalse();
});
