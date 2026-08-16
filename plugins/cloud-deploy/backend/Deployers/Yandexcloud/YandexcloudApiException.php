<?php

namespace Plugins\CloudDeploy\Deployers\Yandexcloud;

use RuntimeException;

/** 只携带归一错误码和固定安全文案，不保留厂商原始响应。 */
class YandexcloudApiException extends RuntimeException
{
    private const MESSAGES = [
        'InvalidCredential' => '服务账号凭证无效',
        'DependencyMissing' => '缺少 PS256 JWT 运行依赖',
        'Unauthenticated' => 'Yandex Cloud 身份认证失败',
        'PermissionDenied' => 'Yandex Cloud 权限不足',
        'NotFound' => 'Yandex Cloud 资源不存在',
        'BadRequest' => 'Yandex Cloud 请求无效',
        'OperationError' => 'Yandex Cloud 异步任务失败',
        'OperationTimeout' => 'Yandex Cloud 异步任务等待超时',
        'InvalidResponse' => 'Yandex Cloud 响应无效',
        'HttpError' => 'Yandex Cloud 接口返回错误',
    ];

    public function __construct(private readonly string $errorCode, ?string $safeMessage = null)
    {
        parent::__construct('['.$errorCode.'] '.($safeMessage ?? self::MESSAGES[$errorCode] ?? 'Yandex Cloud 调用失败'));
    }

    public static function fromStatus(int|string $status): self
    {
        $code = match ((string) $status) {
            '3', '400' => 'BadRequest',
            '5', '404' => 'NotFound',
            '7', '403' => 'PermissionDenied',
            '16', '401' => 'Unauthenticated',
            default => 'HttpError',
        };

        return new self($code);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
