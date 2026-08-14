<?php

namespace App\Services\UserData;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\OutputInterface;

class UserDataExporter
{
    private OutputInterface $output;

    private int $chunkSize;

    /** @var array<string, string[]> 表 => 生成列名（缓存，避免重复 schema introspection） */
    private array $generatedColumnsCache = [];

    public function __construct(OutputInterface $output, int $chunkSize = 1000)
    {
        $this->output = $output;
        $this->chunkSize = $chunkSize;
    }

    /**
     * 导出用户数据为 SQL dump
     *
     * @return string 导出文件路径
     */
    public function export(User $user): string
    {
        $exportDir = storage_path('app/private/exports/users');
        File::ensureDirectoryExists($exportDir);

        $filename = "{$user->id}_".date('Y-m-d_His').'.sql';
        $filePath = "$exportDir/$filename";

        $handle = @fopen($filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException("无法创建导出文件：$filePath");
        }

        try {
            $tableCounts = $this->collectTableCounts($user);

            // 写入文件头
            $this->writeHeader($handle, $user, $tableCounts);

            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

            // 导出各表数据
            foreach (UserDataTableRegistry::exportTables() as $item) {
                $count = $tableCounts[$item['table']] ?? 0;
                if ($count === 0) {
                    continue;
                }

                $this->output->writeln("导出{$item['name']} ($count 条)...");
                $this->exportTable($handle, $item, $user);
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");

            fclose($handle);

            $fileSize = File::size($filePath);
            $this->output->writeln("\n导出完成：$filePath");
            $this->output->writeln('文件大小：'.$this->formatSize($fileSize));

            return $filePath;
        } catch (\Throwable $e) {
            fclose($handle);
            File::delete($filePath);

            throw $e;
        }
    }

    /**
     * 收集各表数据量
     */
    private function collectTableCounts(User $user): array
    {
        $counts = [];
        $schema = DB::getSchemaBuilder();

        foreach (UserDataTableRegistry::exportTables() as $item) {
            if (! $schema->hasTable($item['table'])) {
                $counts[$item['table']] = 0;

                continue;
            }

            $counts[$item['table']] = match ($item['type']) {
                'user' => 1,
                'direct' => DB::table($item['table'])->where('user_id', $user->id)->count(),
                'indirect' => DB::table($item['table'])
                    ->whereIn('order_id', fn ($q) => $q->select('id')->from('orders')->where('user_id', $user->id))
                    ->count(),
                'notification' => DB::table('notifications')
                    ->where('notifiable_type', User::class)
                    ->where('notifiable_id', $user->id)
                    ->count(),
                default => 0,
            };
        }

        return $counts;
    }

    /**
     * 写入文件头部注释
     */
    private function writeHeader($handle, User $user, array $tableCounts): void
    {
        $tablesSummary = collect($tableCounts)
            ->filter(fn ($count) => $count > 0)
            ->map(fn ($count, $table) => "$table($count)")
            ->implode(', ');

        fwrite($handle, "-- 用户数据导出\n");
        fwrite($handle, "-- user_id: $user->id\n");
        fwrite($handle, "-- username: $user->username\n");
        fwrite($handle, '-- exported_at: '.date('Y-m-d H:i:s')."\n");
        fwrite($handle, "-- tables: $tablesSummary\n\n");
    }

    /**
     * 导出单个表
     */
    private function exportTable($handle, array $item, User $user): void
    {
        match ($item['type']) {
            'user' => $this->exportUserTable($handle, $user),
            'direct' => $this->exportDirectTable($handle, $item['table'], $user->id),
            'indirect' => $this->exportIndirectTable($handle, $item['table'], $user->id),
            'notification' => $this->exportNotifications($handle, $user),
            default => null,
        };
    }

    /**
     * 导出 users 表（排除 password）
     */
    private function exportUserTable($handle, User $user): void
    {
        $row = DB::table('users')->where('id', $user->id)->first();
        if (! $row) {
            return;
        }

        $row = (array) $row;
        $row['password'] = '';

        fwrite($handle, "-- ========== users ==========\n");
        fwrite($handle, $this->buildInsertSql('users', [$row])."\n\n");
    }

    /**
     * 导出直接通过 user_id 关联的表
     */
    private function exportDirectTable($handle, string $table, int $userId): void
    {
        fwrite($handle, "-- ========== $table ==========\n");

        $batch = [];
        DB::table($table)->where('user_id', $userId)
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use ($handle, $table, &$batch) {
                foreach ($rows as $row) {
                    $batch[] = (array) $row;

                    if (count($batch) >= 100) {
                        fwrite($handle, $this->buildInsertSql($table, $batch)."\n");
                        $batch = [];
                    }
                }
            });

        if (! empty($batch)) {
            fwrite($handle, $this->buildInsertSql($table, $batch)."\n");
        }

        fwrite($handle, "\n");
    }

    /**
     * 导出通过 order_id 间接关联的表
     */
    private function exportIndirectTable($handle, string $table, int $userId): void
    {
        fwrite($handle, "-- ========== $table ==========\n");

        $batch = [];
        DB::table($table)
            ->whereIn('order_id', fn ($q) => $q->select('id')->from('orders')->where('user_id', $userId))
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use ($handle, $table, &$batch) {
                foreach ($rows as $row) {
                    $batch[] = (array) $row;

                    if (count($batch) >= 100) {
                        fwrite($handle, $this->buildInsertSql($table, $batch)."\n");
                        $batch = [];
                    }
                }
            });

        if (! empty($batch)) {
            fwrite($handle, $this->buildInsertSql($table, $batch)."\n");
        }

        fwrite($handle, "\n");
    }

    /**
     * 导出通知（多态关联）
     */
    private function exportNotifications($handle, User $user): void
    {
        fwrite($handle, "-- ========== notifications ==========\n");

        $batch = [];
        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use ($handle, &$batch) {
                foreach ($rows as $row) {
                    $batch[] = (array) $row;

                    if (count($batch) >= 100) {
                        fwrite($handle, $this->buildInsertSql('notifications', $batch)."\n");
                        $batch = [];
                    }
                }
            });

        if (! empty($batch)) {
            fwrite($handle, $this->buildInsertSql('notifications', $batch)."\n");
        }

        fwrite($handle, "\n");
    }

    /**
     * 构建 INSERT SQL 语句
     */
    private function buildInsertSql(string $table, array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $columns = array_keys($rows[0]);

        // 排除生成列：MySQL 不允许向生成列显式写值，写进 INSERT 导入会报 3105
        $generated = $this->generatedColumns($table);
        if (! empty($generated)) {
            $columns = array_values(array_filter($columns, fn ($col) => ! in_array($col, $generated, true)));
        }

        // 自增 ID 表排除 id 列，避免跨系统导入冲突
        if (UserDataTableRegistry::isAutoIncrement($table)) {
            $columns = array_values(array_filter($columns, fn ($col) => $col !== 'id'));
        }
        $columnList = implode(', ', array_map(fn ($col) => "`$col`", $columns));

        $pdo = DB::connection()->getPdo();
        $valueGroups = [];

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                if ($val === null) {
                    $values[] = 'NULL';
                } elseif (is_int($val) || is_float($val)) {
                    $values[] = $val;
                } else {
                    $values[] = $pdo->quote((string) $val);
                }
            }
            $valueGroups[] = '('.implode(', ', $values).')';
        }

        return "INSERT INTO `$table` ($columnList) VALUES\n".implode(",\n", $valueGroups).';';
    }

    /**
     * 表的生成列名（MySQL 生成列不能显式写值，导出时需排除）
     *
     * @return string[]
     */
    private function generatedColumns(string $table): array
    {
        if (! array_key_exists($table, $this->generatedColumnsCache)) {
            $this->generatedColumnsCache[$table] = collect(DB::getSchemaBuilder()->getColumns($table))
                ->filter(fn ($c) => ! empty($c['generation']))
                ->pluck('name')
                ->all();
        }

        return $this->generatedColumnsCache[$table];
    }

    /**
     * 格式化文件大小
     */
    private function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2).' '.$units[$i];
    }
}
