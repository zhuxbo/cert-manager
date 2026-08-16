<?php

namespace App\Services\Upgrade;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        $pid = getmypid();
        $this->save([
            'status' => 'running',
            'version' => $version,
            'started_at' => date('Y-m-d H:i:s'),
            // 记录升级进程自身 PID（web 路径下 upgrade:run 后台进程即调用方）+ 进程启动时刻——
            // watchdog 据此探测「进程死否」，区分 SIGKILL/OOM（该解维护）与慢单步（不该解维护）；
            // pid_starttime 与 pid 二元校验防「死升级 PID 被长寿进程复用致误判进程活、永不自愈」。
            'pid' => $pid,
            'pid_starttime' => is_int($pid) && $pid > 0 ? self::readProcStarttime($pid) : null,
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

        $handle = @fopen($this->statusFile, 'r');
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
     *
     * running 且非 stale 才算「运行中」：进程活 → 闸门保持关闭（防叠加双升级）；
     * 进程死 + 超时（stale）→ 闸门重开、shutdown 守卫早退，交 watchdog 收拾。
     */
    public function isRunning(): bool
    {
        $data = $this->get();
        if (! $data || ($data['status'] ?? null) !== 'running') {
            return false;
        }

        return ! $this->isStale($data);
    }

    /**
     * 卡死判定：running 且「超时」且「进程已死」三者同时成立。
     *
     * PID 存活是「不动作」的一票否决——慢单步（大库 migrate / 慢镜像 composer）超阈值但进程活着时
     * 绝不算 stale，避免误 up 把半迁移库 + 半换代码放给流量、并唤醒被 down 暂停的 worker 去 pop
     * 半迁移库上的 job（比卡死 503 更坏）。
     *
     * @param  array<string, mixed>  $data
     */
    public function isStale(array $data): bool
    {
        return ($data['status'] ?? null) === 'running'
            && $this->isTimeStale($data)
            && ! $this->isProcessAlive($data);
    }

    /**
     * 心跳超时判定：updated_at（缺失回落 started_at）距今超过 upgrade.stale_seconds。
     *
     * 时钟全链走 Carbon/now()（与 Carbon::setTestNow 同源，测试可驱动）；解析失败或缺时间基准
     * 视为超时 + Log，损坏 status.json 可被 watchdog 自愈。
     *
     * @param  array<string, mixed>  $data
     */
    public function isTimeStale(array $data): bool
    {
        $reference = $data['updated_at'] ?? $data['started_at'] ?? null;
        if (! is_string($reference) || $reference === '') {
            Log::warning('[Upgrade] status.json 缺时间基准，判为 stale');

            return true;
        }

        try {
            // absolute:true —— Carbon 3 的 diffInSeconds 带符号，用绝对值取「距今经过秒数」，
            // 同时对未来时间戳（时钟漂移/损坏）也倾向判 stale（配合 PID 死判定后自愈）。
            $elapsed = now()->diffInSeconds(Carbon::parse($reference), absolute: true);
        } catch (Throwable $e) {
            Log::warning('[Upgrade] status.json 时间解析失败，判为 stale', [
                'value' => $reference,
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        return $elapsed > (int) Config::get('upgrade.stale_seconds', 3600);
    }

    /**
     * 升级进程存活探测。
     *
     * pid 缺失（旧格式 status.json）→ 视为不存活；生产宝塔 = Linux 恒走 /proc；
     * 非 Linux 开发机回落 posix_kill；两者皆不可用 → false + Log::warning（保住自愈能力）。
     *
     * @param  array<string, mixed>  $data
     */
    public function isProcessAlive(array $data): bool
    {
        $pid = $data['pid'] ?? null;
        if (! is_int($pid) && ! (is_string($pid) && ctype_digit($pid))) {
            return false;
        }
        $pid = (int) $pid;
        if ($pid <= 0) {
            return false;
        }

        // Linux 生产恒有 /proc：/proc/{pid} 存在 = 有进程占用该 PID
        if (is_dir('/proc')) {
            if (! file_exists("/proc/$pid")) {
                return false;
            }

            // PID 复用防护：/proc/{pid} 存在只证明「有进程占用该 PID」，不证明是原升级进程。
            // 有记录 starttime 时二次校验 /proc/{pid}/stat 的 starttime——不符即原进程已死、PID 被复用 → 判死。
            // 旧格式无 pid_starttime → 回落只判存在（兼容，不因缺字段误判）；starttime 读不到（罕见竞态）→
            // 保守判活（不据读取失败推断复用，维持「进程真活就一票否决」不误 up 半迁移库）。
            $recorded = $data['pid_starttime'] ?? null;
            if ($recorded !== null && $recorded !== '') {
                $actual = self::readProcStarttime($pid);
                if ($actual !== null && (string) $actual !== (string) $recorded) {
                    return false;
                }
            }

            return true;
        }

        // 非 Linux（macOS 开发机）回落 posix_kill($pid, 0)：发信号成功即进程存活。
        // 无 /proc 无法取 starttime，starttime 校验仅 Linux 生效（生产恒 Linux，开发机 PID 复用无危害）。
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        Log::warning('[Upgrade] 无法探测升级进程存活（/proc 与 posix_kill 均不可用），保守判不存活', [
            'pid' => $pid,
        ]);

        return false;
    }

    /**
     * 读取 Linux 进程启动时刻（/proc/{pid}/stat 第 22 字段 starttime，自 boot 的 clock ticks）。
     *
     * 用于 PID 复用防护：pid 相同但 starttime 不同 = 原进程已死、PID 被复用。
     * comm（第 2 字段）可能含空格/括号，取最后一个 ')' 之后再切分，规避 comm 干扰。
     * 非 Linux / 不可读 / 解析失败 → null（调用方回落只判 /proc 存在）。
     */
    private static function readProcStarttime(int $pid): ?string
    {
        $stat = @file_get_contents("/proc/$pid/stat");
        if ($stat === false || $stat === '') {
            return null;
        }

        $rparen = strrpos($stat, ')');
        if ($rparen === false) {
            return null;
        }

        // ')' 之后从 state（第 3 字段）起，starttime=第 22 字段 → 索引 19
        $fields = preg_split('/\s+/', trim(substr($stat, $rparen + 1)));

        return $fields[19] ?? null;
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

        // 单一写入口注入心跳时间戳（Carbon/now()，与 Carbon::setTestNow 同源，供 watchdog 判 stale）。
        $data['updated_at'] = now()->toDateTimeString();

        $handle = @fopen($this->statusFile, 'c');
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
