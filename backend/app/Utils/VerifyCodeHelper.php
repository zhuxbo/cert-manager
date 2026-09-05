<?php

namespace App\Utils;

use App\Bootstrap\ApiExceptions;
use Illuminate\Support\Facades\Cache;
use Throwable;

class VerifyCodeHelper
{
    /** 验证码缓存前缀 */
    protected const CODE_PREFIX = 'verify_code_';

    /** 失败计数缓存前缀 */
    protected const FAIL_PREFIX = 'verify_code_fail_';

    /** 发送冷却缓存前缀 */
    protected const COOLDOWN_PREFIX = 'verify_code_cooldown_';

    /** 每日发送计数缓存前缀 */
    protected const DAILY_PREFIX = 'verify_code_daily_';

    /** 校验失败达到该次数后作废当前验证码 */
    protected const MAX_FAIL_ATTEMPTS = 5;

    /** 同一目标两次发送之间的最小间隔（秒） */
    protected const SEND_COOLDOWN_SECONDS = 60;

    /** 同一目标每日最大发送次数 */
    protected const DAILY_SEND_LIMIT = 10;

    /**
     * 发送手机验证码
     *
     * @param  string  $mobile  手机号
     * @param  string  $type  验证码类型
     * @return array 发送结果和验证码信息
     *
     * @throws Throwable
     */
    public static function sendSmsCode(string $mobile, string $type = 'verify_code'): array
    {
        $codeExpire = get_system_setting('sms', 'expire') ?? 600;

        // 发送冷却 + 每日上限
        if ($cooldown = self::checkSendCooldown($mobile)) {
            return $cooldown;
        }

        // 生成验证码
        $code = self::generateCode();
        $codeKey = self::CODE_PREFIX.$type.'_'.$mobile;

        // 发送验证码
        $sms = new Sms;
        $result = $sms->send($mobile, $type, ['code' => $code]);

        if ($result['code'] === 1) {
            // 保存验证码到缓存，并重置失败计数
            Cache::store('runtime')->put($codeKey, $code, $codeExpire);
            self::resetFailCount($mobile, $type);
            self::markSent($mobile);

            return [
                'code' => 1,
                'data' => null,
            ];
        } else {
            // 发送失败：释放已抢占的冷却位，允许立即重试
            self::releaseSendCooldown($mobile);

            return [
                'code' => 0,
                'msg' => $result['msg'] ?? '短信发送失败',
            ];
        }

    }

    /**
     * 发送邮箱验证码
     *
     * @param  string  $email  邮箱
     * @param  string  $type  验证码类型
     * @return array 发送结果和验证码信息
     */
    public static function sendEmailCode(string $email, string $type = 'verify_code'): array
    {
        $codeExpire = get_system_setting('sms', 'expire', 600);
        $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');

        // 发送冷却 + 每日上限：已有未过冷却的码则拒绝重发
        if ($cooldown = self::checkSendCooldown($email)) {
            return $cooldown;
        }

        // 生成验证码
        $code = self::generateCode();
        $codeKey = self::CODE_PREFIX.$type.'_'.$email;

        // 发送验证码邮件
        try {
            $mail = new Email;
            $mail->isSMTP();

            if (! $mail->configured) {
                // 释放已抢占的冷却位：配置问题不应消耗用户冷却额度
                self::releaseSendCooldown($email);

                return [
                    'code' => 0,
                    'msg' => '邮件服务未配置',
                ];
            }

            $mail->addAddress($email);
            $mail->setSubject($siteName.'验证码');

            // 创建邮件内容
            $content = "您的验证码是: {$code}，有效期".($codeExpire / 60).'分钟。';
            if ($type === 'register') {
                $content = "感谢您注册我们的服务，您的验证码是: {$code}，有效期".($codeExpire / 60).'分钟。';
            } elseif ($type === 'bind') {
                $content = "您正在绑定邮箱，验证码是: {$code}，有效期".($codeExpire / 60).'分钟。';
            } elseif ($type === 'reset') {
                $content = "您正在重置密码，验证码是: {$code}，有效期".($codeExpire / 60).'分钟。';
            }

            $mail->Body = $content;

            // 先写缓存后发送：发送失败则回滚缓存，避免占用冷却/计数却没真正发出
            Cache::store('runtime')->put($codeKey, $code, $codeExpire);

            $mail->send();

            // 发送成功，重置失败计数并记录发送时间
            self::resetFailCount($email, $type);
            self::markSent($email);

            return [
                'code' => 1,
                'data' => null,
            ];
        } catch (Throwable $e) {
            // 发送失败，回滚验证码缓存 + 释放冷却占位（允许立即重试）
            Cache::store('runtime')->forget($codeKey);
            self::releaseSendCooldown($email);

            // 记录异常
            app(ApiExceptions::class)->logException($e);

            return [
                'code' => 0,
                'msg' => '邮件发送失败',
            ];
        }
    }

    /**
     * 验证邮箱验证码
     *
     * @param  string  $email  邮箱
     * @param  string  $code  验证码
     * @param  string  $type  验证码类型
     * @param  bool  $autoDelete  是否自动删除验证码
     */
    public static function verifyEmailCode(string $email, string $code, string $type = 'verify_code', bool $autoDelete = true): bool
    {
        return self::verify($email, $code, $type, $autoDelete);
    }

    /**
     * 验证手机验证码
     *
     * @param  string  $mobile  手机号
     * @param  string  $code  验证码
     * @param  string  $type  验证码类型
     * @param  bool  $autoDelete  是否自动删除验证码
     */
    public static function verifySmsCode(string $mobile, string $code, string $type = 'verify_code', bool $autoDelete = true): bool
    {
        return self::verify($mobile, $code, $type, $autoDelete);
    }

    /**
     * 统一的验证码校验：按目标维度累计失败次数，超阈值作废验证码 + 短时锁定。
     *
     * @param  string  $target  邮箱或手机号
     */
    protected static function verify(string $target, string $code, string $type, bool $autoDelete): bool
    {
        $cacheKey = self::CODE_PREFIX.$type.'_'.$target;
        $failKey = self::FAIL_PREFIX.$type.'_'.$target;

        $savedCode = Cache::store('runtime')->get($cacheKey);

        // 无有效验证码（未发送 / 已过期 / 已被失败次数作废）一律失败，且不再累计
        if (! $savedCode) {
            return false;
        }

        // 使用 hash_equals 防时序侧信道；$code 来自用户输入需转字符串
        if (hash_equals((string) $savedCode, (string) $code)) {
            if ($autoDelete) {
                Cache::store('runtime')->forget($cacheKey);
                Cache::store('runtime')->forget($failKey);
            }

            return true;
        }

        // 校验失败累计；达到阈值作废该验证码，攻击者无法继续猜测
        $codeExpire = (int) (get_system_setting('sms', 'expire') ?? 600);
        Cache::store('runtime')->add($failKey, 0, $codeExpire);
        $attempts = (int) Cache::store('runtime')->increment($failKey);

        if ($attempts >= self::MAX_FAIL_ATTEMPTS) {
            Cache::store('runtime')->forget($cacheKey);
            Cache::store('runtime')->forget($failKey);
        }

        return false;
    }

    /**
     * 发送冷却 + 每日上限检查。
     *
     * @param  string  $target  邮箱或手机号
     * @return array|null 命中限制时返回错误数组，否则 null
     */
    protected static function checkSendCooldown(string $target): ?array
    {
        $cooldownKey = self::COOLDOWN_PREFIX.$target;
        $dailyKey = self::DAILY_PREFIX.$target.'_'.date('Ymd');

        // 原子占位冷却：Cache::add（SETNX）失败 = 冷却期内已有请求占位，
        // 防并发同一 target 在 has 判断 + put 写入之间的窗口击穿冷却重复发送
        if (! Cache::store('runtime')->add($cooldownKey, 1, self::SEND_COOLDOWN_SECONDS)) {
            return [
                'code' => 0,
                'msg' => '验证码发送过于频繁，请稍后再试',
            ];
        }

        // 每日上限（冷却已串行化同一 target，此处计数无并发竞争）
        if ((int) Cache::store('runtime')->get($dailyKey, 0) >= self::DAILY_SEND_LIMIT) {
            // 释放刚抢占的冷却位：今日额度用尽不应再额外卡 60s
            self::releaseSendCooldown($target);

            return [
                'code' => 0,
                'msg' => '今日验证码发送次数已达上限',
            ];
        }

        return null;
    }

    /**
     * 标记一次成功发送：写入冷却 + 累计当日发送次数。
     *
     * @param  string  $target  邮箱或手机号
     */
    protected static function markSent(string $target): void
    {
        $cooldownKey = self::COOLDOWN_PREFIX.$target;
        $dailyKey = self::DAILY_PREFIX.$target.'_'.date('Ymd');

        Cache::store('runtime')->put($cooldownKey, 1, self::SEND_COOLDOWN_SECONDS);

        // 当日计数：TTL 到当日 23:59:59，跨天自动重置
        Cache::store('runtime')->add($dailyKey, 0, now()->endOfDay());
        Cache::store('runtime')->increment($dailyKey);
    }

    /**
     * 释放发送冷却占位：发送失败时回滚 checkSendCooldown 抢占的冷却，允许立即重试。
     *
     * @param  string  $target  邮箱或手机号
     */
    protected static function releaseSendCooldown(string $target): void
    {
        Cache::store('runtime')->forget(self::COOLDOWN_PREFIX.$target);
    }

    /**
     * 重置失败计数（发送新码或校验成功时）。
     *
     * @param  string  $target  邮箱或手机号
     */
    protected static function resetFailCount(string $target, string $type): void
    {
        Cache::store('runtime')->forget(self::FAIL_PREFIX.$type.'_'.$target);
    }

    /**
     * 生成随机验证码（使用 random_int 保证密码学强度）
     */
    protected static function generateCode(): string
    {
        $length = 6;
        $max = 9;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, $max);
        }

        return $code;
    }
}
