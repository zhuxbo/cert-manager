<?php

namespace App\Bootstrap;

use App\Exceptions\ApiResponseException;
use App\Exceptions\MutationBusyException;
use App\Exceptions\ProductPriceMutationBusyException;
use App\Exceptions\ProductPriceMutationLockException;
use App\Models\ErrorLog;
use App\Services\LogBuffer;
use App\Utils\LogScrubber;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ApiExceptions
{
    use DetectsConcurrencyErrors;

    /**
     * 不需要记录日志的异常类型
     */
    protected array $dontLogExceptions = [
        AuthenticationException::class,
        ThrottleRequestsException::class,
        NotFoundHttpException::class,
        ValidationException::class,
        ApiResponseException::class,
        MethodNotAllowedHttpException::class,
        // 订单级互斥抢锁失败是高频「忙」信号，不记入 error_logs（与 TaskJob 死锁自愈不 report 的降噪一致）
        MutationBusyException::class,
        ProductPriceMutationBusyException::class,
    ];

    /**
     * 处理异常
     */
    public function handle(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $e) {
            // 非 HttpResponseException 路径（ValidationException / AuthenticationException 等）
            // 走此 callback，统一不转义中文（\uXXXX → UTF-8）便于日志 / 文档 try-it 可读；
            // 客户端 JSON 解析两者等价。用 |= 叠加而非直接覆盖，保留 response 已有 encodingOptions。
            // 注：ApiResponseException(extends HttpResponseException) 不经此 callback，
            // 其编码在该异常构造时直接设定（见 App\Exceptions\ApiResponseException）。
            $response = $this->handleApiException($e);

            return $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE);
        });

        $exceptions->reportable(function (Throwable $e) {
            $this->logException($e);
        });
    }

    /**
     * 将异常记录到日志
     */
    public function logException(Throwable $e): void
    {
        if (! $this->shouldNotLog($e)) {
            if (app()->runningInConsole()) {
                $method = 'CLI';
                $url = implode(' ', $_SERVER['argv'] ?? ['unknown']);
                $ip = '127.0.0.1';
                $module = 'Console';
                $action = $_SERVER['argv'][1] ?? null;
            } else {
                $request = Request::instance();
                $method = $request->method();
                // 先脱敏（?token=/?access_token= 等凭据串传）再截断，避免截断切坏脱敏后的 URL
                $url = LogScrubber::scrubUrl($request->fullUrl());
                $ip = $request->ip();
                [$module, $action] = $this->routeContext($request);
            }

            if (strlen($url) > 2000) {
                $url = substr($url, 0, 1997).'...';
            }

            // 截断过长的错误信息，防止数据库字段溢出
            $message = $e->getMessage();
            if (strlen($message) > 1000) {
                $message = substr($message, 0, 997).'...';
            }

            LogBuffer::add(ErrorLog::class, [
                'module' => $module,
                'action' => $action,
                'method' => $method,
                'url' => $url,
                'exception' => class_basename($e),
                'message' => $message,
                'trace' => LogScrubber::scrubResponse($e->getTrace()),
                'status_code' => $this->getExceptionStatusCode($e),
                'ip' => $ip,
            ]);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function routeContext(\Illuminate\Http\Request $request): array
    {
        $controller = $request->route()?->getAction('controller');
        if (! is_string($controller) || $controller === '') {
            return [null, null];
        }

        [$class, $action] = array_pad(explode('@', $controller, 2), 2, null);
        $module = str_replace('Controller', '', class_basename($class));

        return [$module !== '' ? $module : null, $action];
    }

    /**
     * 判断是否不记录日志
     */
    protected function shouldNotLog(Throwable $e): bool
    {
        foreach ($this->dontLogExceptions as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取异常状态码
     */
    protected function getExceptionStatusCode(Throwable $e): int
    {
        return match (true) {
            $e instanceof AuthenticationException => 401,
            $e instanceof NotFoundHttpException => 404,
            $e instanceof MethodNotAllowedHttpException => 405,
            $e instanceof ThrottleRequestsException => 429,
            // 验证异常 状态码 200
            $e instanceof ValidationException => 200,
            $e instanceof HttpException => $e->getStatusCode(),
            // 订单级互斥抢锁失败（同一订单 commit/cancel 正在执行）→ 503，建议客户端重试
            $e instanceof MutationBusyException => 503,
            $e instanceof ProductPriceMutationBusyException, $e instanceof ProductPriceMutationLockException => 503,
            // 数据库并发冲突（MySQL deadlock / lock wait timeout）→ 503，让客户端/前端重试
            $this->causedByConcurrencyError($e) => 503,
            // 数据库唯一约束违反（funds.pay_method+pay_sn 重复 / transactions.type+transaction_id 重复）
            // → 409 Conflict，与 503 区分：409 是请求本身重复无需重试，503 才是建议重试
            $this->causedByDuplicateKey($e) !== null => 409,
            default => 400,
        };
    }

    /**
     * 检测 DB 唯一约束违反并返回业务消息；非唯一冲突返回 null。
     *
     * MySQL SQLSTATE 23000 + errno 1062 表示唯一约束冲突。
     * 应用层钩子 exists 校验作为前端速失败保留，本翻译是 DB 兜底（并发漏失场景）。
     */
    protected function causedByDuplicateKey(Throwable $e): ?string
    {
        // QueryException 继承自 PDOException，单一检查即可覆盖两类。
        if (! $e instanceof \PDOException) {
            return null;
        }

        $sqlState = (string) $e->getCode();
        $message = $e->getMessage();
        $errCode = null;
        if ($e instanceof QueryException) {
            $errCode = $e->errorInfo[1] ?? null;
        }

        $isUniqueViolation = match (true) {
            $sqlState === '23000' && (int) $errCode === 1062 => true,
            // 兜底：不带 errcode 的 PDOException 用消息匹配
            str_contains($message, 'Duplicate entry') => true,
            default => false,
        };

        if (! $isUniqueViolation) {
            return null;
        }

        // MySQL 错误消息含约束名，按命名匹配业务消息
        // refer_id 应用层（Order resolveReferId / ACME checkAcmeReferId）通过 SELECT-then-INSERT 防重，
        // 极端并发下两个 SELECT 同时返回不存在 → DB unique 兜底拦截，本翻译保证消息与应用层 / 上游 V2 一致
        // （Order certs_refer_id_unique 与 ACME acmes_refer_id_unique 竞态都译为同一文案）
        return match (true) {
            str_contains($message, 'funds_pay_method_pay_sn_unique') => '支付编号重复请勿重复支付',
            str_contains($message, 'transactions_dedup_unique') => '交易记录已存在',
            str_contains($message, 'certs_refer_id_unique') => 'Refer id already exists',
            str_contains($message, 'acmes_refer_id_unique') => 'Refer id already exists',
            default => '数据已存在',
        };
    }

    /**
     * 处理 API 异常
     * 美化错误响应格式
     * 统一错误信息展示规则
     * 判断是否调试模式
     */
    protected function handleApiException(Throwable $e): JsonResponse
    {
        if ($e instanceof ApiResponseException) {
            return new JsonResponse($e->getApiResponse());
        }

        $status = $this->getExceptionStatusCode($e);
        $debug = config('app.debug', false);

        if ($e instanceof ValidationException) {
            $response = [
                'code' => 0,
                'msg' => '提交数据验证失败',
                'errors' => $e->errors(),
            ];

            return new JsonResponse($response, $status);
        }

        // DB 唯一约束违反：返回业务消息（后端原始 SQL 错误只在 debug / error_log 可见）
        $duplicateKeyMessage = $this->causedByDuplicateKey($e);

        $message = match (true) {
            $e instanceof AuthenticationException => $e->getMessage() ?: '未登录或登录已过期',
            $e instanceof NotFoundHttpException => $e->getMessage() ?: '请求的资源不存在',
            $e instanceof MethodNotAllowedHttpException => $e->getMessage() ?: '请求方法不允许',
            $e instanceof ThrottleRequestsException => $e->getMessage() ?: '请求过于频繁，请稍后再试',
            $e instanceof HttpException => $e->getMessage() ?: '服务器错误',
            // 订单级互斥抢锁失败——精确文案（比通用「系统繁忙」更明确：是同一订单在处理中）
            $e instanceof MutationBusyException => '该订单正在处理中，请稍后重试',
            $e instanceof ProductPriceMutationBusyException => '产品价格正在变更，请稍后重试',
            $e instanceof ProductPriceMutationLockException => '产品价格变更失败，请稍后重试',
            // MySQL deadlock / lock wait timeout 等并发冲突——用户友好提示
            // 后端原始异常（如 "Lock wait timeout exceeded"）只在 debug 模式或日志中可见
            $this->causedByConcurrencyError($e) => '系统繁忙，请稍后重试',
            $duplicateKeyMessage !== null => $duplicateKeyMessage,
            default => $debug ? $e->getMessage() : '服务器错误',
        };

        $response = [
            'code' => 0,
            'msg' => $message,
        ];

        if ($debug && ! ($e instanceof HttpException)) {
            $response['errors']['exception_type'] = get_class($e);
            $response['errors']['exception_trace'] = $e->getTrace();
        }

        return new JsonResponse($response, $status);
    }
}
