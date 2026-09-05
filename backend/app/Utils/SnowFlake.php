<?php

declare(strict_types=1);

namespace App\Utils;

use App\Support\RuntimeCache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

class SnowFlake
{
    /**
     * 起始时间戳（毫秒）
     */
    const int EPOCH = 1696152000000;

    /**
     * 41位时间偏移基数
     */
    const int MAX_41_BIT = 1099511627775;

    /**
     * @var int 机器ID，需外部设置，每台机器唯一[0-7]
     */
    protected static int $machineId = 0;

    /**
     * @var string 锁Key
     */
    protected static string $lockKey = 'snowflake_lock';

    /**
     * @var string 存储 last 和 count 的key
     */
    protected static string $stateKey = 'snowflake_state';

    /**
     * @var Lock|null 当前持有的锁实例
     */
    protected static ?Lock $currentLock = null;

    /**
     * 设置机器ID
     */
    public static function setMachineId(int $mId): void
    {
        if ($mId < 0 || $mId > 7) {
            throw new InvalidArgumentException('机器ID必须在0到7之间');
        }
        self::$machineId = $mId;
    }

    /**
     * 尝试获取锁
     */
    protected static function acquireLock(int $timeoutSeconds = 1, int $retryCount = 10, int $retryDelayUs = 50000): bool
    {
        for ($i = 0; $i < $retryCount; $i++) {
            $lock = RuntimeCache::lock(self::$lockKey, $timeoutSeconds);
            if ($lock->get()) {
                self::$currentLock = $lock;

                return true;
            }
            usleep($retryDelayUs);
        }

        return false;
    }

    /**
     * 释放锁
     */
    protected static function releaseLock(): void
    {
        if (self::$currentLock !== null) {
            self::$currentLock->release();
            self::$currentLock = null;
        }
    }

    /**
     * 初始化 state（只执行一次，如果初次运行可先初始化）
     */
    public static function initState(): void
    {
        if (! Cache::store('runtime')->has(self::$stateKey)) {
            Cache::store('runtime')->forever(self::$stateKey, ['last' => 0, 'count' => 0]);
        }
    }

    /**
     * 生成雪花ID
     *
     * @throws RuntimeException
     */
    public static function generateParticle(): int
    {
        if (self::$machineId === 0) {
            self::setMachineId((int) config('app.snowflake.machine_id'));
        }

        self::initState();

        // 获取锁
        if (! self::acquireLock()) {
            throw new RuntimeException('无法获取雪花锁，生成ID失败');
        }

        try {
            $currentTime = (int) floor(microtime(true) * 1000);
            $offsetTime = $currentTime - self::EPOCH;

            $state = Cache::store('runtime')->get(self::$stateKey, ['last' => 0, 'count' => 0]);
            $last = (int) $state['last'];
            $count = (int) $state['count'];

            // 检测回拨
            if ($offsetTime < $last) {
                throw new RuntimeException('检测到时钟回拨，拒绝生成雪花ID');
            }

            // 同一毫秒内count递增，如果溢出则等待下毫秒
            if ($offsetTime == $last) {
                $count = ($count + 1) & 7;
                if ($count == 0) {
                    // count用尽等待下一个毫秒
                    while (true) {
                        usleep(1000); // 等待1ms
                        $currentTime = (int) floor(microtime(true) * 1000);
                        $offsetTime = $currentTime - self::EPOCH;
                        if ($offsetTime > $last) {
                            $count = 0;
                            break;
                        }
                    }
                }
            } else {
                $count = 0;
            }

            $base = decbin(self::MAX_41_BIT + $offsetTime);
            $machineIdBin = str_pad(decbin(self::$machineId), 3, '0', STR_PAD_LEFT);
            $sequence = str_pad(decbin($count), 3, '0', STR_PAD_LEFT);
            $binaryStr = $base.$machineIdBin.$sequence;
            $id = bindec($binaryStr);

            // 更新状态
            Cache::store('runtime')->forever(self::$stateKey, [
                'last' => $offsetTime,
                'count' => $count,
            ]);

            return $id;
        } finally {
            self::releaseLock();
        }
    }
}
