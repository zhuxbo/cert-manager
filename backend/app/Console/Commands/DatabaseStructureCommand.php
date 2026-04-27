<?php

namespace App\Console\Commands;

use App\Services\Upgrade\DatabaseStructureService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as CommandAlias;

class DatabaseStructureCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:structure
        {--export : 导出数据库结构为 JSON}
        {--check : 检测数据库结构差异}
        {--fix : 自动修复缺失的结构（仅添加）}
        {--database= : 目标数据库连接}
        {--keep-container : 保持 Docker 容器运行}
        {--use-local : 使用本地 MySQL 而不是 Docker}
        {--skip-foreign-keys : 跳过外键添加}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '管理数据库结构：导出JSON、检测差异、自动修复';

    /**
     * Docker 容器配置
     */
    private string $containerName = 'laravel-mysql-temp';

    private int $containerPort = 33067;

    private string $mysqlImage = 'm.daocloud.io/docker.io/library/mysql:8.0'; // 为了兼容 使用 MySQL 8.0

    private string $mysqlPassword = 'temp123';

    private string $tempDatabase = 'tempdb';

    /**
     * 默认 JSON 文件路径
     */
    private string $defaultJsonPath = 'database/structure.json';

    /**
     * 备份原始 log 连接配置，避免迁移期改写后未恢复导致后续访问临时库
     */
    private ?array $originalLogConfig = null;

    public function __construct(
        private DatabaseStructureService $structureService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 禁用 IDE Helper 自动运行，避免容器清理后的连接错误
        putenv('BARRYVDH_IDE_HELPER_ENABLED=false');

        try {
            if ($this->option('export')) {
                return $this->handleExport();
            }

            if ($this->option('check')) {
                return $this->handleCheck();
            }

            if ($this->option('fix')) {
                return $this->handleFix();
            }

            $this->error('请指定操作: --export, --check, 或 --fix');

            return CommandAlias::FAILURE;
        } catch (Exception $e) {
            $this->error('执行失败: '.$e->getMessage());

            return CommandAlias::FAILURE;
        }
    }

    /**
     * 处理导出功能
     */
    private function handleExport(): int
    {
        $this->info('开始导出数据库结构...');

        try {
            if ($this->option('use-local')) {
                // 使用本地 MySQL
                $this->line('使用本地 MySQL 导出数据库结构...');

                // 直接使用默认连接导出
                $connection = $this->option('database') ?: 'mysql';
                $structure = $this->exportStructureToJson($connection);

                // 保存到默认路径 database/structure.json
                $outputPath = $this->defaultJsonPath;
                $this->saveJson($structure, $outputPath);

                $this->info("结构已导出到: $outputPath");

                return CommandAlias::SUCCESS;
            }

            // 使用 Docker 容器
            // 1. 启动 Docker 容器 (MySQL 8.0)
            $this->line('启动 MySQL 8.0 容器...');
            $this->startMysqlContainer();

            // 2. 等待 MySQL 就绪
            $this->line('等待 MySQL 服务就绪...');
            $this->waitForMysql();

            // 3. 配置临时数据库连接
            $this->configureTempConnection();

            // 4. 执行迁移
            $this->line('执行数据库迁移...');
            $this->runMigrations();

            // 5. 导出结构为 JSON
            $this->line('导出数据库结构...');
            $structure = $this->exportStructureToJson('temp');

            // 6. 保存到默认路径 database/structure.json
            $outputPath = $this->defaultJsonPath;
            $this->saveJson($structure, $outputPath);

            $this->info("结构已导出到: $outputPath");

            return CommandAlias::SUCCESS;
        } finally {
            // 迁移期间可能将 log 连接切至临时库，这里优先恢复原始配置
            if ($this->originalLogConfig !== null) {
                Config::set('database.connections.log', $this->originalLogConfig);
                DB::purge('log');
                $this->originalLogConfig = null;
            }

            // 在清理容器前断开临时数据库连接，避免在容器停止后仍有连接尝试
            if (! $this->option('use-local')) {
                DB::purge('temp');
                DB::disconnect('temp');

                // 清理容器
                if (! $this->option('keep-container')) {
                    $this->stopMysqlContainer();
                }
            }
        }
    }

    /**
     * 处理检测功能
     *
     * @throws Exception
     */
    private function handleCheck(): int
    {
        $this->line('开始检测数据库结构差异...');

        // 1. 加载标准结构
        $standardPath = $this->defaultJsonPath;
        if (! File::exists($standardPath)) {
            $this->error("标准结构文件不存在: $standardPath");
            $this->info('请先运行 --export 生成标准结构');

            return CommandAlias::FAILURE;
        }

        $standard = json_decode(File::get($standardPath), true);

        // 2. 获取当前数据库结构
        $database = $this->option('database') ?: 'mysql';
        $current = $this->exportStructureToJson($database);

        // 3. 对比差异
        $diff = $this->structureService->compareStructures($standard, $current);

        // 4. 显示报告
        $this->displayDiffReport($diff);

        // 返回状态码：有差异返回 1，无差异返回 0
        return $this->hasDifferences($diff) ? CommandAlias::FAILURE : CommandAlias::SUCCESS;
    }

    /**
     * 处理修复功能
     *
     * @throws Exception
     */
    private function handleFix(): int
    {
        $this->info('开始自动修复数据库结构...');

        // 1. 先执行检测
        $standardPath = $this->defaultJsonPath;
        if (! File::exists($standardPath)) {
            $this->error("标准结构文件不存在: $standardPath");

            return CommandAlias::FAILURE;
        }

        $standard = json_decode(File::get($standardPath), true);
        $database = $this->option('database') ?: 'mysql';
        $current = $this->exportStructureToJson($database);
        $diff = $this->structureService->compareStructures($standard, $current);

        // 2. 生成 ADD 语句
        $addStatements = $this->structureService->generateAddStatements($diff, (bool) $this->option('skip-foreign-keys'));

        if (empty($addStatements)) {
            $this->line('没有需要添加的结构');
        } else {
            // 3. 显示将要执行的 SQL
            $this->line('将要执行以下 SQL 语句:');
            $this->newLine();
            foreach ($addStatements as $sql) {
                $this->line($sql);
            }
            $this->newLine();

            // 4. 执行安全检查
            $safetyIssues = $this->performSafetyChecks($diff, $database);
            if (! empty($safetyIssues)) {
                $this->warn('[WARNING]  发现以下安全问题:');
                foreach ($safetyIssues as $issue) {
                    $this->line(' - '.$issue);
                }
                $this->newLine();
            }

            // 5. 确认执行
            if ($this->confirm('确认执行以上 SQL 语句？', true)) {
                $this->executeSqlStatements($addStatements, $database);
                $this->line('结构修复完成');
            } else {
                $this->line('已取消执行');
            }
        }

        // 5. 显示需要手动处理的项目
        $this->displayManualActions($diff);

        return CommandAlias::SUCCESS;
    }

    /**
     * 启动 MySQL Docker 容器
     *
     * @throws Exception
     */
    private function startMysqlContainer(): void
    {
        // 检查容器是否已存在
        exec("docker ps -a --filter name=$this->containerName --format '{{.Names}}'", $output);
        if (! empty($output)) {
            $this->line('移除已存在的容器...');
            exec("docker rm -f $this->containerName 2>&1");
        }

        // 查找可用端口
        $port = $this->findAvailablePort();
        $this->containerPort = $port;

        // 启动容器，使用 Laravel 配置的字符集和排序规则
        $charset = config('database.connections.mysql.charset') ?: 'utf8mb4';
        $collation = config('database.connections.mysql.collation') ?: 'utf8mb4_unicode_ci';

        $cmd = sprintf(
            'docker run -d --name %s -e MYSQL_ROOT_PASSWORD=%s -e MYSQL_DATABASE=%s -p %d:3306 %s --character-set-server=%s --collation-server=%s 2>&1',
            $this->containerName,
            $this->mysqlPassword,
            $this->tempDatabase,
            $this->containerPort,
            $this->mysqlImage,
            $charset,
            $collation
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception('Docker 容器启动失败: '.implode("\n", $output));
        }

        $this->info("容器已启动，端口: $this->containerPort");
    }

    /**
     * 查找可用端口
     *
     * @throws Exception
     */
    private function findAvailablePort(): int
    {
        $startPort = 33067;
        $maxPort = 33100;

        for ($port = $startPort; $port <= $maxPort; $port++) {
            $output = []; // 清理输出数组
            exec("lsof -i :$port 2>&1", $output, $returnCode);
            if ($returnCode !== 0) {
                return $port;
            }
        }

        throw new Exception('无法找到可用端口');
    }

    /**
     * 等待 MySQL 服务就绪
     *
     * @throws Exception
     */
    private function waitForMysql(): void
    {
        $maxAttempts = 60;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            exec(sprintf(
                'docker exec %s mysql -uroot -p%s -e "SELECT 1" 2>&1',
                $this->containerName,
                $this->mysqlPassword
            ), $output, $returnCode);

            if ($returnCode === 0) {
                $this->line('MySQL 服务已就绪');

                return;
            }

            sleep(1);
            $attempt++;
            $this->line("等待中... ($attempt/$maxAttempts)");
        }

        throw new Exception('MySQL 服务启动超时');
    }

    /**
     * 配置临时数据库连接
     */
    private function configureTempConnection(): void
    {
        // 使用与主数据库相同的字符集和排序规则
        Config::set('database.connections.temp', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => $this->containerPort,
            'database' => $this->tempDatabase,
            'username' => 'root',
            'password' => $this->mysqlPassword,
            'charset' => config('database.connections.mysql.charset', 'utf8mb4'),
            'collation' => config('database.connections.mysql.collation', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        // 清除连接缓存
        DB::purge('temp');
    }

    /**
     * 执行迁移
     */
    private function runMigrations(): void
    {
        // 备份原始 log 连接，并将 log 指向临时数据库，确保迁移期日志相关表也在临时库中创建
        if ($this->originalLogConfig === null) {
            $this->originalLogConfig = Config::get('database.connections.log');
        }
        Config::set('database.connections.log', Config::get('database.connections.temp'));
        DB::purge('log');

        // 执行迁移（仅主系统迁移，排除插件迁移）
        try {
            $this->call('migrate:fresh', [
                '--database' => 'temp',
                '--force' => true,
                '--path' => 'database/migrations',
            ]);
        } finally {
            // 迁移完成后立即恢复 log 连接，避免后续代码继续访问临时库
            if ($this->originalLogConfig !== null) {
                Config::set('database.connections.log', $this->originalLogConfig);
                DB::purge('log');
            }
        }
    }

    /**
     * 导出数据库结构为 JSON
     */
    private function exportStructureToJson(string $connection): array
    {
        $database = Config::get("database.connections.$connection.database");

        $structure = [
            'version' => '1.0',
            'mysql_version' => $this->getMysqlVersion($connection),
            'generated_at' => now()->toDateTimeString(),
            'database' => [
                'name' => 'manager',
                'charset' => Config::get("database.connections.$connection.charset"),
                'collation' => Config::get("database.connections.$connection.collation"),
            ],
            'tables' => [],
        ];

        // 获取所有表
        $tables = DB::connection($connection)
            ->select('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT, AUTO_INCREMENT
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = ?', [$database]);

        foreach ($tables as $table) {
            $tableName = $table->TABLE_NAME;

            // 跳过系统表
            if (in_array($tableName, ['migrations', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'])) {
                continue;
            }

            $structure['tables'][$tableName] = [
                'engine' => $table->ENGINE,
                'collation' => $table->TABLE_COLLATION,
                'comment' => $table->TABLE_COMMENT,
                'auto_increment' => $table->AUTO_INCREMENT,
                'columns' => $this->getTableColumns($connection, $database, $tableName),
                'indexes' => $this->getTableIndexes($connection, $database, $tableName),
                'foreign_keys' => $this->getTableForeignKeys($connection, $database, $tableName),
            ];
        }

        return $structure;
    }

    /**
     * 获取 MySQL 版本
     */
    private function getMysqlVersion(string $connection): string
    {
        $result = DB::connection($connection)->select('SELECT VERSION() as version');

        return $result[0]->version ?? 'unknown';
    }

    /**
     * 获取表的列信息
     */
    private function getTableColumns(string $connection, string $database, string $table): array
    {
        $columns = DB::connection($connection)
            ->select('SELECT * FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                     ORDER BY ORDINAL_POSITION', [$database, $table]);

        $result = [];
        foreach ($columns as $column) {
            $result[$column->COLUMN_NAME] = [
                'position' => $column->ORDINAL_POSITION,
                'type' => $column->COLUMN_TYPE,
                'nullable' => $column->IS_NULLABLE === 'YES',
                'default' => $column->COLUMN_DEFAULT,
                'extra' => $column->EXTRA,
                'comment' => $column->COLUMN_COMMENT,
            ];
        }

        return $result;
    }

    /**
     * 获取表的索引信息
     */
    private function getTableIndexes(string $connection, string $database, string $table): array
    {
        $indexes = DB::connection($connection)
            ->select('SELECT * FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                     ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$database, $table]);

        $result = [];
        foreach ($indexes as $index) {
            $indexName = $index->INDEX_NAME;

            if (! isset($result[$indexName])) {
                $result[$indexName] = [
                    'unique' => ! $index->NON_UNIQUE,
                    'type' => $index->INDEX_TYPE,
                    'columns' => [],
                    'sub_parts' => [], // 索引前缀长度
                ];
            }

            $result[$indexName]['columns'][] = $index->COLUMN_NAME;
            $result[$indexName]['sub_parts'][] = $index->SUB_PART; // 记录前缀长度
        }

        return $result;
    }

    /**
     * 获取表的外键信息
     */
    private function getTableForeignKeys(string $connection, string $database, string $table): array
    {
        $foreignKeys = DB::connection($connection)
            ->select('SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                            kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                            rc.UPDATE_RULE, rc.DELETE_RULE
                     FROM information_schema.KEY_COLUMN_USAGE kcu
                     JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                          ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                          AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                     WHERE kcu.TABLE_SCHEMA = ? AND kcu.TABLE_NAME = ?
                          AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                     ORDER BY kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION', [$database, $table]);

        $result = [];
        foreach ($foreignKeys as $fk) {
            $constraintName = $fk->CONSTRAINT_NAME;

            if (! isset($result[$constraintName])) {
                $result[$constraintName] = [
                    'columns' => [],
                    'references' => [
                        'table' => $fk->REFERENCED_TABLE_NAME,
                        'columns' => [],
                    ],
                    'on_delete' => $fk->DELETE_RULE,
                    'on_update' => $fk->UPDATE_RULE,
                ];
            }

            $result[$constraintName]['columns'][] = $fk->COLUMN_NAME;
            $result[$constraintName]['references']['columns'][] = $fk->REFERENCED_COLUMN_NAME;
        }

        return $result;
    }

    /**
     * 保存 JSON 文件
     */
    private function saveJson(array $structure, string $path): void
    {
        $directory = dirname($path);
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, json_encode($structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 停止并移除 Docker 容器
     */
    private function stopMysqlContainer(): void
    {
        $this->info('清理 Docker 容器...');

        // 静默地清理容器，避免输出干扰
        @exec("docker rm -f $this->containerName 2>&1 > /dev/null");
    }

    /**
     * 显示差异报告
     */
    private function displayDiffReport(array $diff): void
    {
        $this->newLine();
        $this->line('数据库结构对比报告');
        $this->info('========================');
        $this->newLine();

        $hasAdd = false;
        $hasModify = false;
        $hasDrop = false;

        // 显示可自动修复的项目（ADD）
        if (! empty($diff['missing_tables'])) {
            $hasAdd = true;
            $this->line('可自动修复（ADD）:');
            foreach ($diff['missing_tables'] as $tableName => $schema) {
                $this->line(" - 表 <comment>$tableName</comment> 缺失");
            }
        }

        foreach ($diff['table_differences'] as $tableName => $tableDiff) {
            if (! empty($tableDiff['missing_columns'])) {
                if (! $hasAdd) {
                    $hasAdd = true;
                    $this->line('可自动修复（ADD）:');
                }
                foreach ($tableDiff['missing_columns'] as $columnName => $columnDef) {
                    $this->line(" - 列 <comment>$tableName.$columnName</comment> 缺失");
                }
            }

            if (! empty($tableDiff['missing_indexes'])) {
                if (! $hasAdd) {
                    $hasAdd = true;
                    $this->line('可自动修复（ADD）:');
                }
                foreach ($tableDiff['missing_indexes'] as $indexName => $indexDef) {
                    $this->line(" - 索引 <comment>$tableName.$indexName</comment> 缺失");
                }
            }

            if (! empty($tableDiff['missing_foreign_keys'])) {
                if (! $hasAdd) {
                    $hasAdd = true;
                    $this->line('可自动修复（ADD）:');
                }
                foreach ($tableDiff['missing_foreign_keys'] as $fkName => $fkDef) {
                    $this->line(" - 外键 <comment>$tableName.$fkName</comment> 缺失");
                }
            }
        }

        if ($hasAdd) {
            $this->newLine();
        }

        // 显示需要手动处理的修改项（MODIFY）
        foreach ($diff['table_differences'] as $tableName => $tableDiff) {
            if (! empty($tableDiff['modified_columns'])) {
                if (! $hasModify) {
                    $hasModify = true;
                    $this->warn('[WARNING]  需手动处理（MODIFY）:');
                }
                foreach ($tableDiff['modified_columns'] as $columnName => $columnDiff) {
                    $differences = $this->structureService->describeColumnDifferences($columnDiff['standard'], $columnDiff['current']);
                    $this->line(sprintf(
                        '  - 列 <comment>%s.%s</comment>: %s',
                        $tableName,
                        $columnName,
                        $differences
                    ));
                }
            }
        }

        if ($hasModify) {
            $this->newLine();
        }

        // 显示需要手动确认的删除项（DROP）
        if (! empty($diff['extra_tables'])) {
            $hasDrop = true;
            $this->warn('[WARNING]  需手动处理（DROP）:');
            foreach ($diff['extra_tables'] as $tableName => $schema) {
                $this->line(" - 表 <comment>$tableName</comment> 在标准中不存在");
                $this->line("   建议: 确认后执行 DROP TABLE $tableName");
            }
        }

        foreach ($diff['table_differences'] as $tableName => $tableDiff) {
            if (! empty($tableDiff['extra_columns'])) {
                if (! $hasDrop) {
                    $hasDrop = true;
                    $this->warn('[WARNING]  需手动处理（DROP）:');
                }
                foreach ($tableDiff['extra_columns'] as $columnName => $columnDef) {
                    $this->line(" - 列 <comment>$tableName.$columnName</comment> 在标准中不存在");
                    $this->line("   建议: 确认后执行 ALTER TABLE $tableName DROP COLUMN $columnName");
                }
            }

            if (! empty($tableDiff['extra_indexes'])) {
                if (! $hasDrop) {
                    $hasDrop = true;
                    $this->warn('[WARNING]  需手动处理（DROP）:');
                }
                foreach ($tableDiff['extra_indexes'] as $indexName => $indexDef) {
                    $this->line(" - 索引 <comment>$tableName.$indexName</comment> 在标准中不存在");
                    $this->line("   建议: 确认后执行 ALTER TABLE $tableName DROP INDEX $indexName");
                }
            }

            if (! empty($tableDiff['extra_foreign_keys'])) {
                if (! $hasDrop) {
                    $hasDrop = true;
                    $this->warn('[WARNING]  需手动处理（DROP）:');
                }
                foreach ($tableDiff['extra_foreign_keys'] as $fkName => $fkDef) {
                    $this->line(" - 外键 <comment>$tableName.$fkName</comment> 在标准中不存在");
                    $this->line("   建议: 确认后执行 ALTER TABLE $tableName DROP FOREIGN KEY $fkName");
                }
            }
        }

        if (! $hasAdd && ! $hasModify && ! $hasDrop) {
            $this->line('数据库结构完全一致，无需修改');
        } else {
            $this->newLine();
            if ($hasAdd) {
                $this->info('运行 --fix 可自动修复 ADD 项目');
            }
        }
    }

    /**
     * 判断是否有差异
     */
    private function hasDifferences(array $diff): bool
    {
        return ! empty($diff['missing_tables']) ||
               ! empty($diff['extra_tables']) ||
               ! empty($diff['table_differences']);
    }

    /**
     * 执行安全检查
     */
    private function performSafetyChecks(array $diff, string $connection): array
    {
        $issues = [];

        // 检查非空列是否会导致问题
        foreach ($diff['table_differences'] ?? [] as $tableName => $tableDiff) {
            foreach ($tableDiff['missing_columns'] ?? [] as $columnName => $columnDef) {
                if (! $columnDef['nullable'] && $columnDef['default'] === null) {
                    // 检查表是否有数据
                    $count = DB::connection($connection)
                        ->table($tableName)
                        ->count();
                    if ($count > 0) {
                        $issues[] = "表 $tableName 有 $count 条记录，添加非空列 $columnName 可能失败。建议先添加可空列，填充数据后再改为非空";
                    }
                }
            }

            // 检查唯一索引是否会有重复数据
            foreach ($tableDiff['missing_indexes'] ?? [] as $indexName => $indexDef) {
                if ($indexDef['unique']) {
                    // 构建列名列表（不包含前缀长度）
                    $columns = $indexDef['columns'];
                    $columnList = implode(', ', array_map(fn ($col) => "`$col`", $columns));

                    // 检查是否有重复值
                    $duplicates = DB::connection($connection)
                        ->select("
                            SELECT $columnList, COUNT(*) as cnt
                            FROM `$tableName`
                            GROUP BY $columnList
                            HAVING cnt > 1
                        ");

                    if (! empty($duplicates)) {
                        $issues[] = "表 $tableName 在列 ($columnList) 上有重复数据，无法添加唯一索引 $indexName";
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * 执行 SQL 语句
     */
    private function executeSqlStatements(array $statements, string $connection): void
    {
        foreach ($statements as $sql) {
            try {
                DB::connection($connection)->statement($sql);
                $this->info('[OK] 执行成功: '.substr($sql, 0, 60).'...');
            } catch (Exception $e) {
                $this->error('[FAIL] 执行失败: '.$sql);
                $this->error('错误: '.$e->getMessage());
            }
        }
    }

    /**
     * 显示需要手动处理的操作
     */
    private function displayManualActions(array $diff): void
    {
        $hasManual = false;

        // MODIFY 项目
        foreach ($diff['table_differences'] ?? [] as $tableName => $tableDiff) {
            if (! empty($tableDiff['modified_columns'])) {
                if (! $hasManual) {
                    $this->newLine();
                    $this->warn('以下项目需要手动处理:');
                    $this->newLine();
                    $hasManual = true;
                }

                $this->warn('列修改（MODIFY）:');
                foreach ($tableDiff['modified_columns'] as $columnName => $columnDiff) {
                    $this->line("$tableName.$columnName: {$columnDiff['current']['type']} => {$columnDiff['standard']['type']}");
                }
            }
        }

        // DROP 项目
        if (! empty($diff['extra_tables'])) {
            if (! $hasManual) {
                $this->newLine();
                $this->warn('以下项目需要手动处理:');
                $this->newLine();
                $hasManual = true;
            }

            $this->warn('多余的表（DROP）:');
            foreach ($diff['extra_tables'] as $tableName => $schema) {
                $this->line("$tableName");
            }
        }

        foreach ($diff['table_differences'] ?? [] as $tableName => $tableDiff) {
            if (! empty($tableDiff['extra_columns'])) {
                if (! $hasManual) {
                    $this->newLine();
                    $this->warn('以下项目需要手动处理:');
                    $this->newLine();
                    $hasManual = true;
                }

                $this->warn('多余的列（DROP）:');
                foreach ($tableDiff['extra_columns'] as $columnName => $columnDef) {
                    $this->line("$tableName.$columnName");
                }
            }

            if (! empty($tableDiff['extra_indexes'])) {
                if (! $hasManual) {
                    $this->newLine();
                    $this->warn('以下项目需要手动处理:');
                    $this->newLine();
                    $hasManual = true;
                }

                $this->warn('多余的索引（DROP）:');
                foreach ($tableDiff['extra_indexes'] as $indexName => $indexDef) {
                    $this->line("$tableName.$indexName");
                }
            }

            if (! empty($tableDiff['missing_foreign_keys'])) {
                if (! $hasManual) {
                    $this->newLine();
                    $this->warn('以下项目需要手动处理:');
                    $this->newLine();
                    $hasManual = true;
                }

                $this->warn('缺失的外键（ADD FOREIGN KEY）:');
                foreach ($tableDiff['missing_foreign_keys'] as $fkName => $fkDef) {
                    $this->line("$tableName.$fkName");
                }
            }

            if (! empty($tableDiff['extra_foreign_keys'])) {
                if (! $hasManual) {
                    $this->newLine();
                    $this->warn('以下项目需要手动处理:');
                    $this->newLine();
                    $hasManual = true;
                }

                $this->warn('多余的外键（DROP FOREIGN KEY）:');
                foreach ($tableDiff['extra_foreign_keys'] as $fkName => $fkDef) {
                    $this->line("$tableName.$fkName");
                }
            }
        }
    }
}
