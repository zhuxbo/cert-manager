<?php

namespace App\Support;

use App\Models\ErrorLog;
use App\Services\LogBuffer;
use App\Utils\LogScrubber;
use Throwable;

/**
 * OPcache 字节码缓存重置
 *
 * 三种"没清成"含义不同，调用方不要一律当失败：
 * 1. 扩展未加载 —— function_exists('opcache_reset') 为 false；
 * 2. 当前 SAPI 未启用（CLI 下 opcache.enable_cli 默认 0，这是命令行的常态）—— 含义是
 *    "本来就没缓存可清"。裸调时它表现为 opcache_reset() 返回 false，与真失败无法区分，
 *    故这里先用 opcache_get_status() 探测再决定是否 reset，把它归为 skipped 而非 failed；
 * 3. 配了 opcache.restrict_api 且调用脚本路径不匹配 —— PHP 发 E_WARNING，而 Laravel
 *    引导后 error_reporting = -1，HandleExceptions 会把它转成 ErrorException 抛出；
 *    升级流程里不接住会中断升级。其余异常不贴这个标签，按 failed/reset_threw 记。
 *
 * 另注：命令行进程只能清自己的 OPcache，够不到 PHP-FPM 常驻进程的字节码缓存——那条路径
 * 只能靠重载 PHP-FPM（deploy/scripts/bt-automate.sh 的 bt_reload_php_fpm）。
 */
class Opcache
{
    public const OK = 'ok';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    /**
     * 重置 OPcache，任何情况下都不抛异常
     *
     * @return array{status: string, reason: string|null, message: string|null, sapi: string}
     */
    public function reset(): array
    {
        if (! $this->available()) {
            return $this->result(self::SKIPPED, 'extension_not_loaded');
        }

        try {
            if (! $this->enabled()) {
                return $this->result(self::SKIPPED, 'not_enabled');
            }

            return $this->doReset()
                ? $this->result(self::OK)
                : $this->result(self::FAILED, 'reset_returned_false');
        } catch (Throwable $e) {
            // 只有确认是 restrict_api 才贴该标签：其余异常（扩展 bug、SHM 异常）按失败记，
            // 否则确定性措辞会把排障带偏
            return str_contains($e->getMessage(), 'restrict_api')
                ? $this->result(self::SKIPPED, 'api_restricted', $e->getMessage())
                : $this->result(self::FAILED, 'reset_threw', $e->getMessage());
        }
    }

    /**
     * 清理没成功时落 error_logs
     *
     * 插件生命周期会丢弃底层输出、只给界面一个成功态，不落库的话
     * restrict_api 这类失败对管理员和运维完全不可见——界面报成功、字节码分文未动，
     * 正是本类要消灭的假成功信号。正常跳过（扩展缺失 / 当前 SAPI 未启用）不记。
     *
     * @param  array{status: string, reason: string|null, message: string|null, sapi: string}  $result
     * @param  string  $source  命令行 / 无 HTTP 上下文时写进 url 列的来源标识
     */
    public function reportFailure(array $result, string $source): void
    {
        if ($result['status'] !== self::FAILED && $result['reason'] !== 'api_restricted') {
            return;
        }

        // 用本类自己的 SAPI 判定，不用 runningInConsole()：后者在 PHPUnit 里恒 true，
        // HTTP 分支将永远无法被测试触达（那三个字段只能等生产 FPM 第一次执行）
        $request = $this->isCli() ? null : request();

        $message = sprintf(
            'OPcache 清理未生效：status=%s reason=%s sapi=%s',
            $result['status'],
            $result['reason'] ?? '-',
            $result['sapi'],
        );
        if ($result['message'] !== null) {
            $message .= ' detail='.$result['message'];
        }

        LogBuffer::add(ErrorLog::class, [
            'method' => $request?->method() ?? 'CLI',
            'url' => $request !== null ? LogScrubber::scrubUrl($request->fullUrl()) : $source,
            'exception' => 'OpcacheResetFailed',
            'message' => mb_substr($message, 0, 1000),
            'status_code' => 500,
            'ip' => $request?->ip(),
        ]);
    }

    /**
     * 当前是否命令行进程（清不到 PHP-FPM 的字节码缓存）
     */
    public function isCli(): bool
    {
        return in_array($this->sapi(), ['cli', 'phpdbg'], true);
    }

    protected function available(): bool
    {
        return function_exists('opcache_reset');
    }

    /**
     * 当前 SAPI 是否真的启用了 OPcache
     */
    protected function enabled(): bool
    {
        if (! function_exists('opcache_get_status')) {
            // 极少见：有 reset 无 status，交给 opcache_reset() 自行判定
            return true;
        }

        // false 参数省略 scripts，避免拉回大数组
        $status = opcache_get_status(false);

        return is_array($status) && ($status['opcache_enabled'] ?? false) === true;
    }

    protected function doReset(): bool
    {
        return opcache_reset();
    }

    protected function sapi(): string
    {
        return PHP_SAPI;
    }

    /**
     * @return array{status: string, reason: string|null, message: string|null, sapi: string}
     */
    private function result(string $status, ?string $reason = null, ?string $message = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'message' => $message,
            'sapi' => $this->sapi(),
        ];
    }
}
