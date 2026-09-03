<?php

declare(strict_types=1);

namespace App\Services\Order\Traits;

use App\Models\Callback;
use App\Models\Order;
use App\Utils\IpUtil;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

trait ActionCallbackTrait
{
    private const CALLBACK_CONNECT_TIMEOUT_SECONDS = 5;

    private const CALLBACK_REQUEST_ATTEMPTS = 2;

    private const CALLBACK_REQUEST_TIMEOUT_SECONDS = 15;

    /** @var array<int, int> */
    private const CALLBACK_RETRYABLE_HTTP_STATUSES = [429, 502, 503, 504];

    /** @var array<string, mixed> */
    private array $callbackResponseMetadata = [];

    /**
     * 检查 URL 是否指向私有/内网地址（SSRF 防护，白名单制）
     *
     * 仅公网 IP 放行；私网/loopback/link-local/CGNAT/多播等保留段
     * 与解析失败一律拒绝（fail-closed），段清单见 IpUtil。
     */
    private function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return true;
        }

        // IPv6 字面量 host 形如 [::1]
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return IpUtil::isPrivateOrReserved($host);
        }

        $ip = gethostbyname($host);
        // gethostbyname 解析失败时返回原始主机名
        if ($ip === $host || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return IpUtil::isPrivateOrReserved($ip);
    }

    public function callback(int $orderId): void
    {
        $order = Order::with(['latestCert'])
            ->whereHas('latestCert', function ($query) {
                $query->whereIn('status', ['active', 'cancelled', 'revoked']);
            })
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            $this->error('订单不存在');
        }

        $callback = Callback::where('user_id', $order->user_id)->where('status', 1)->first();

        if (! $callback) {
            $this->error('用户未启用回调');
        }

        // SSRF 防护：检查回调 URL 是否指向私有/内网地址
        if ($this->isPrivateUrl($callback->url)) {
            $this->error('回调地址不允许指向内网');
        }

        // 考虑回调提供订单数据
        // 显式超时（不靠框架默认值）：回推下游是纯通知，跑在 TaskJob 事务内持 task 行锁，
        // 最坏耗时仍小于 worker --timeout 60s；框架升级或全局 Http::macro 改默认也不会静默退回无界阻塞。
        $response = $this->postCallback($callback->url, [
            'id' => $orderId,
            'token' => $callback->token,
        ]);

        $httpCode = $response->status();

        // 只有 HTTP 200 才进入兼容性业务判定；其他 2xx 仍维持历史行为，按失败处理。
        if ($httpCode !== 200) {
            $this->error('Http Status '.$httpCode, $this->callbackResponseMetadata);
        }

        $body = $response->body();
        // 兼容无法约束响应格式的第三方：HTTP 200 默认成功；仅当 JSON code 明确为数字 0
        // 或布尔 false 时判业务失败。字符串 "0"、缺少 code 及其他值均不改变 HTTP 200 结果。
        $payload = $response->json();
        $hasCodeAndMessage = is_array($payload)
            && array_key_exists('code', $payload)
            && array_key_exists('msg', $payload);
        if ($hasCodeAndMessage) {
            $this->callbackResponseMetadata['remote_code'] = $payload['code'];
            $this->callbackResponseMetadata['remote_msg'] = $payload['msg'];
        } else {
            // 对方不遵循 code/msg 结构时，保留 HTTP 200 的原始响应供排障。
            $this->callbackResponseMetadata['remote_response'] = $body;
        }

        $remoteCode = is_array($payload) && array_key_exists('code', $payload)
            ? $payload['code']
            : null;
        $explicitFailure = $remoteCode === false
            || ((is_int($remoteCode) || is_float($remoteCode)) && (float) $remoteCode === 0.0);
        if ($explicitFailure) {
            $message = $hasCodeAndMessage && is_scalar($payload['msg'])
                ? (string) $payload['msg']
                : '';
            $message = $message !== '' ? '回调方返回失败：'.$message : '回调方返回失败';
            $this->error($message, $this->callbackResponseMetadata);
        }

        $this->success($this->callbackResponseMetadata);
    }

    private function postCallback(string $url, array $data): Response
    {
        $startedAt = hrtime(true);
        $attempts = 0;
        $this->callbackResponseMetadata = [];

        try {
            $response = Http::asForm()
                ->timeout(self::CALLBACK_REQUEST_TIMEOUT_SECONDS)
                ->connectTimeout(self::CALLBACK_CONNECT_TIMEOUT_SECONDS)
                // 初始 URL 已做公网校验；禁止跟随重定向，防止对方 30x 跳转到内网绕过 SSRF 防线。
                ->withoutRedirecting()
                ->beforeSending(function (Request $request) use (&$attempts) {
                    $attempts++;
                })
                // 回调运行于 TaskJob 的 60s 窗口内：两次各 15s + 1~3s 退避，最坏约 33s。
                // 只补发连接异常及明确的瞬态 HTTP 状态，400/401/404/500 等永久或不确定错误不重试。
                ->retry(
                    self::CALLBACK_REQUEST_ATTEMPTS,
                    static fn () => random_int(1000, 3000),
                    static fn (?Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException
                            && in_array($e->response->status(), self::CALLBACK_RETRYABLE_HTTP_STATUSES, true)),
                    throw: false,
                )
                ->post($url, $data);

            $this->callbackResponseMetadata = $this->buildCallbackResponseMetadata(
                $response,
                $attempts,
                $startedAt,
            );

            return $response;
        } catch (ConnectionException) {
            $this->callbackResponseMetadata = [
                'request_attempts' => $attempts,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
            ];
            $this->error('回调地址暂时无法连接', $this->callbackResponseMetadata);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCallbackResponseMetadata(Response $response, int $attempts, int $startedAt): array
    {
        $body = $response->body();
        $metadata = [
            'http_status' => $response->status(),
            'request_attempts' => $attempts,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
            'response_length' => strlen($body),
            'response_sha256' => hash('sha256', $body),
        ];

        $contentType = $response->header('Content-Type');
        if ($contentType !== '') {
            $metadata['content_type'] = mb_substr($contentType, 0, 100);
        }

        $retryAfter = $response->header('Retry-After');
        if ($retryAfter !== '') {
            $metadata['retry_after'] = mb_substr($retryAfter, 0, 100);
        }

        return $metadata;
    }

    private function elapsedMilliseconds(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 1);
    }
}
