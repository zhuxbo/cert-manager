<?php

namespace App\Services\Upgrade;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class UpgradeStatusManager
{
    protected string $statusFile;

    protected ?int $expectedSteps = null;

    public function __construct()
    {
        $this->statusFile = storage_path('upgrades/status.json');
        $this->ensureDirectory();
    }

    /**
     * 设置预期步骤数
     */
    public function setExpectedSteps(int $steps): void
    {
        $this->expectedSteps = $steps;
    }

    protected function ensureDirectory(): void
    {
        $dir = dirname($this->statusFile);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true)) {
                Log::warning("无法创建升级状态目录: $dir");
            }
        }

        // 确保目录可写
        if (is_dir($dir) && ! is_writable($dir)) {
            @chmod($dir, 0755);
        }
    }

    /**
     * 开始升级
     */
    public function start(string $version): void
    {
        $this->save([
            'status' => 'running',
            'version' => $version,
            'started_at' => date('Y-m-d H:i:s'),
            'current_step' => null,
            'steps' => [],
            'progress' => 0,
            'error' => null,
        ]);
    }

    /**
     * 更新当前步骤
     */
    public function updateStep(string $step, string $status, ?string $error = null): void
    {
        $data = $this->get();
        if (! $data) {
            return;
        }

        $data['current_step'] = $step;

        // 更新或添加步骤
        $found = false;
        foreach ($data['steps'] as &$s) {
            if ($s['step'] === $step) {
                $s['status'] = $status;
                if ($error) {
                    $s['error'] = $error;
                }
                $found = true;
                break;
            }
        }

        if (! $found) {
            $stepData = ['step' => $step, 'status' => $status];
            if ($error) {
                $stepData['error'] = $error;
            }
            $data['steps'][] = $stepData;
        }

        // 计算进度
        $totalSteps = $this->getTotalSteps();
        $completedSteps = count(array_filter($data['steps'], fn ($s) => $s['status'] === 'completed'));
        $data['progress'] = $totalSteps > 0 ? round(($completedSteps / $totalSteps) * 100) : 0;

        $this->save($data);
    }

    /**
     * 标记步骤完成
     */
    public function completeStep(string $step): void
    {
        $this->updateStep($step, 'completed');
    }

    /**
     * 标记步骤开始
     */
    public function startStep(string $step): void
    {
        $this->updateStep($step, 'running');
    }

    /**
     * 标记步骤失败
     */
    public function failStep(string $step, string $error): void
    {
        $this->updateStep($step, 'failed', $error);
    }

    /**
     * 标记升级完成
     */
    public function complete(string $fromVersion, string $toVersion, ?array $structureCheck = null): void
    {
        $data = $this->get();
        if ($data) {
            $data['status'] = 'completed';
            $data['completed_at'] = date('Y-m-d H:i:s');
            $data['from_version'] = $fromVersion;
            $data['to_version'] = $toVersion;
            $data['progress'] = 100;
            if ($structureCheck !== null) {
                $data['structure_check'] = $structureCheck;
            }
            $this->save($data);
        }
    }

    /**
     * 标记升级失败
     *
     * @param  string  $error  错误信息（短文本，前端默认展示）
     * @param  array|null  $details  结构化失败上下文（如 PHP 环境检测：{type, current_php, required_php, missing_extensions, ...}）
     */
    public function fail(string $error, ?array $details = null): void
    {
        $data = $this->get();
        if ($data) {
            $data['status'] = 'failed';
            $data['error'] = $error;
            $data['failed_at'] = date('Y-m-d H:i:s');
            if ($details !== null) {
                $data['error_details'] = $details;
            }
            $this->save($data);
        }

        Log::error("升级失败: $error");
    }

    /**
     * 获取当前状态
     */
    public function get(): ?array
    {
        if (! file_exists($this->statusFile)) {
            return null;
        }

        $handle = fopen($this->statusFile, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_SH);
            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);

            return json_decode($content, true);
        } finally {
            fclose($handle);
        }
    }

    /**
     * 检查是否正在升级
     */
    public function isRunning(): bool
    {
        $data = $this->get();

        return $data && $data['status'] === 'running';
    }

    /**
     * 清除状态
     */
    public function clear(): void
    {
        if (file_exists($this->statusFile)) {
            unlink($this->statusFile);
        }
    }

    /**
     * 保存状态（使用文件锁防止并发冲突）
     */
    protected function save(array $data): void
    {
        // 确保目录存在且可写
        $this->ensureDirectory();

        $handle = fopen($this->statusFile, 'c');
        if ($handle === false) {
            $error = "无法打开状态文件: {$this->statusFile}";
            Log::error($error);
            throw new \RuntimeException($error);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException("无法获取文件锁: {$this->statusFile}");
            }

            ftruncate($handle, 0);
            rewind($handle);
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $result = fwrite($handle, $content);
            fflush($handle);
            flock($handle, LOCK_UN);

            if ($result === false) {
                throw new \RuntimeException("无法写入状态文件: {$this->statusFile}");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 获取总步骤数
     * 根据配置动态计算实际步骤数
     */
    protected function getTotalSteps(): int
    {
        // 如果已设置预期步骤数，使用设置的值
        if ($this->expectedSteps !== null) {
            return $this->expectedSteps;
        }

        // 基本步骤（必须执行）
        // fetch_release, check_version, download, extract, check_environment, apply, update_version, cleanup
        $steps = 8;

        // 可选步骤（根据配置）
        if (Config::get('upgrade.behavior.force_backup', true)) {
            $steps++; // backup
        }

        if (Config::get('upgrade.behavior.maintenance_mode', true)) {
            $steps += 2; // maintenance_on, maintenance_off
        }

        if (Config::get('upgrade.behavior.auto_migrate', true)) {
            $steps++; // migrate
        }

        if (Config::get('upgrade.behavior.clear_cache', true)) {
            $steps++; // clear_cache
        }

        if (Config::get('upgrade.behavior.auto_structure_check', true)) {
            $steps++; // structure_check
        }

        if (Config::get('upgrade.behavior.auto_seed', true)) {
            $steps++; // seed
        }

        // composer_install 是动态的，暂不计入
        // 实际执行时会通过 setExpectedSteps 设置

        return $steps;
    }
}
