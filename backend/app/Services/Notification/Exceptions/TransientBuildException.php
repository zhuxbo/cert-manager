<?php

declare(strict_types=1);

namespace App\Services\Notification\Exceptions;

use RuntimeException;

/**
 * 通知 build 阶段的瞬态失败标记异常（磁盘满 / inode 耗尽 / 临时 IO 异常）。
 *
 * NotificationJob build catch 用 instanceof 判档（类型安全，不做异常消息字符串匹配）：
 * 瞬态类未达 tries 上限走 release 错峰重试（恢复后重跑 build 自愈），末轮才落 FAILED 记录；
 * 其余（数据/校验错）为永久类、直接落 FAILED 记录不重试。
 *
 * 约定：任何生成附件/临时文件的 Builder，其 IO 失败一律抛此异常
 * （含 ZipArchive open()/close() 返回值检查），详见 skills/backend/notification.md。
 */
class TransientBuildException extends RuntimeException {}
