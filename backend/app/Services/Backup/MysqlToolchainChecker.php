<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Services\Binary\BinaryLocator;
use App\Services\Binary\Exceptions\BinaryNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class MysqlToolchainChecker
{
    private const SUPPORTED_SERIES = ['5.7', '8.0', '8.4'];

    public function __construct(private BinaryLocator $locator) {}

    /**
     * @return array{
     *   supported:bool, errors:list<string>, warnings:list<string>,
     *   server:array{vendor:string, version:string, series:string},
     *   mysql:?array{path:string,vendor:string,version:string,series:string},
     *   mysqldump:?array{path:string,vendor:string,version:string,series:string},
     *   gzip:array{path:string,version:string}
     * }
     */
    public function inspect(bool $requireMysql, bool $requireMysqldump): array
    {
        $errors = [];
        $server = $this->inspectServer($errors);
        $mysql = $this->inspectMysqlTool('mysql', $requireMysql, $server['series'], $errors);
        $mysqldump = $this->inspectMysqlTool('mysqldump', $requireMysqldump, $server['series'], $errors);
        $gzip = $this->inspectGzip($errors);

        if ($server['vendor'] !== 'mysql') {
            $errors[] = $this->unsupportedComponentError('服务端', $server);
        } elseif (! in_array($server['series'], self::SUPPORTED_SERIES, true)) {
            $errors[] = $this->unsupportedSeriesError('服务端', $server);
        }

        foreach (['mysql' => $mysql, 'mysqldump' => $mysqldump] as $name => $tool) {
            if ($tool === null) {
                continue;
            }

            if ($tool['vendor'] !== 'mysql') {
                if ($server['vendor'] === 'mysql') {
                    $targetServer = in_array($server['series'], self::SUPPORTED_SERIES, true)
                        ? $server
                        : null;
                    $errors[] = $this->unsupportedComponentError("$name 客户端", $tool, $targetServer);
                }

                continue;
            }

            if (! in_array($tool['series'], self::SUPPORTED_SERIES, true)) {
                $errors[] = $this->unsupportedSeriesError("$name 客户端", $tool);

                continue;
            }

            if ($server['vendor'] === 'mysql' && $tool['series'] !== $server['series']) {
                $errors[] = "MySQL 版本系列不一致：服务端 {$server['series']}，{$name} 客户端 {$tool['series']}（{$tool['path']}）。宝塔请使用 /www/server/mysql/bin/{$name}，或配置与服务端同系列的 Oracle MySQL 客户端。";
            }
        }

        return [
            'supported' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'warnings' => [],
            'server' => $server,
            'mysql' => $mysql,
            'mysqldump' => $mysqldump,
            'gzip' => $gzip,
        ];
    }

    public function assertSupported(bool $requireMysql, bool $requireMysqldump): void
    {
        $inspection = $this->inspect($requireMysql, $requireMysqldump);
        if (! $inspection['supported']) {
            throw new RuntimeException(implode("\n", $inspection['errors']));
        }
    }

    /**
     * @param  list<string>  $errors
     * @return array{vendor:string, version:string, series:string}
     */
    private function inspectServer(array &$errors): array
    {
        try {
            $row = DB::selectOne('SELECT VERSION() AS version, @@version_comment AS version_comment');
            $version = (string) ($row->version ?? '');
            $comment = (string) ($row->version_comment ?? '');
            $parsed = $this->parseMysqlVersion($version, $comment);
            if ($parsed === null) {
                $errors[] = "无法解析 MySQL 服务端版本：$version $comment";

                return ['vendor' => 'unknown', 'version' => $version ?: 'unknown', 'series' => 'unknown'];
            }

            return $parsed;
        } catch (Throwable $e) {
            $errors[] = '无法读取 MySQL 服务端版本：'.$e->getMessage();

            return ['vendor' => 'unknown', 'version' => 'unknown', 'series' => 'unknown'];
        }
    }

    /**
     * @param  list<string>  $errors
     * @return array{path:string,vendor:string,version:string,series:string}|null
     */
    private function inspectMysqlTool(string $tool, bool $required, string $serverSeries, array &$errors): ?array
    {
        if (! $required) {
            return null;
        }

        try {
            $path = $this->locator->$tool();
        } catch (BinaryNotFoundException) {
            $errors[] = "未找到 {$tool} 客户端的可用路径；已探测宝塔 /www/server/mysql/bin/{$tool}、标准系统路径和 PATH。";
            $series = in_array($serverSeries, self::SUPPORTED_SERIES, true)
                ? " $serverSeries 系列"
                : '同系列';
            $errors[] = "安装 Oracle MySQL{$series}客户端（先启用对应系列的 Oracle MySQL 官方仓库）：Debian/Ubuntu 执行 `apt-get update && apt-get install -y mysql-client`；RHEL/Rocky/Alma 执行 `dnf install -y mysql-community-client`。";

            return null;
        }

        $output = $this->runVersion($path);
        if ($output === null) {
            $errors[] = "无法执行 $tool --version：$path";

            return null;
        }

        $parsed = $this->parseMysqlVersion($output);
        if ($parsed === null) {
            $errors[] = "无法解析 $tool 版本（{$path}）：".trim($output);

            return null;
        }

        return ['path' => $path] + $parsed;
    }

    /**
     * @param  list<string>  $errors
     * @return array{path:string,version:string}
     */
    private function inspectGzip(array &$errors): array
    {
        try {
            $path = $this->locator->gzip();
        } catch (BinaryNotFoundException $e) {
            $errors[] = '未找到 gzip，已检查：'.implode(', ', $e->getTriedPaths());
            $errors = array_values(array_merge($errors, $e->diagnose()));

            return ['path' => '', 'version' => 'unknown'];
        }

        $output = $this->runVersion($path);
        if ($output === null || ! preg_match('/gzip\s+(\d+\.\d+(?:\.\d+)?)/i', $output, $matches)) {
            $errors[] = "无法解析 gzip 版本：$path";
            $errors = array_values(array_merge($errors, $this->locator->diagnose('gzip')));

            return ['path' => $path, 'version' => 'unknown'];
        }

        return ['path' => $path, 'version' => $matches[1]];
    }

    private function runVersion(string $path): ?string
    {
        try {
            $process = new Process([$path, '--version']);
            $process->run();

            return $process->isSuccessful() ? $process->getOutput().$process->getErrorOutput() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{vendor:string, version:string, series:string}|null
     */
    private function parseMysqlVersion(string $version, string $comment = ''): ?array
    {
        if (! preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
            return null;
        }

        $source = strtolower($version.' '.$comment);
        $vendor = match (true) {
            str_contains($source, 'mariadb') => 'mariadb',
            str_contains($source, 'percona') => 'percona',
            str_contains($source, 'mysql community server'),
            str_contains($source, 'mysql enterprise server'),
            str_contains($source, 'oracle mysql'),
            str_contains($source, 'source distribution'),
            preg_match('/^(?:mysql|mysqldump)\s+ver\s+.+\bdistrib\s+5\.7\./i', $version) === 1 => 'mysql',
            default => 'unknown',
        };

        return [
            'vendor' => $vendor,
            'version' => "$matches[1].$matches[2].$matches[3]",
            'series' => "$matches[1].$matches[2]",
        ];
    }

    /**
     * @param  array{vendor:string,version:string,series:string,path?:string}  $component
     */
    private function unsupportedComponentError(string $name, array $component, ?array $targetServer = null): string
    {
        if ($targetServer !== null && $component['vendor'] === 'mariadb') {
            return "当前 {$name}为 MariaDB {$component['version']}，不受支持；目标服务端为 MySQL {$targetServer['version']}，请改用 MySQL {$targetServer['series']} 系列客户端。";
        }

        $path = isset($component['path']) ? "（{$component['path']}）" : '';

        return match ($component['vendor']) {
            'mariadb' => "{$name}为 MariaDB {$component['version']}{$path}，不受支持；请改用 Oracle MySQL 5.7、8.0 或 8.4。",
            'percona' => "{$name}为 Percona {$component['version']}{$path}，不受支持；请改用 Oracle MySQL 5.7、8.0 或 8.4。",
            default => "{$name}类型无法确认（{$component['version']}{$path}）；请核实 VERSION()、@@version_comment 和客户端 --version 输出是否为 Oracle MySQL。",
        };
    }

    /**
     * @param  array{vendor:string,version:string,series:string,path?:string}  $component
     */
    private function unsupportedSeriesError(string $name, array $component): string
    {
        $path = isset($component['path']) ? "（{$component['path']}）" : '';

        return "{$name}为 Oracle MySQL {$component['version']}{$path}，仅支持 5.7、8.0、8.4 系列。";
    }
}
