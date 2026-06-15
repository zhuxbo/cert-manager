<?php

declare(strict_types=1);

namespace App\Services\Acme\Api\default;

use App\Models\CaLog;
use App\Services\LogBuffer;
use App\Utils\LogScrubber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class Sdk
{
    protected string $baseUrl;

    protected string $apiToken;

    public function __construct()
    {
        $acmeUrl = get_system_setting('ca', 'acme_url');
        if (! $acmeUrl) {
            // ca.url 形如 https://upstream/api/v2（v2 base），acme 复用同一配置 → 追加 /acme
            $caUrl = rtrim((string) get_system_setting('ca', 'url'), '/');
            $acmeUrl = $caUrl !== '' ? $caUrl.'/acme' : '';
        }
        $this->baseUrl = rtrim((string) $acmeUrl, '/');

        $this->apiToken = (string) (get_system_setting('ca', 'acme_token') ?: get_system_setting('ca', 'token'));
    }

    /**
     * 创建 ACME 订单
     */
    public function new(array $data): array
    {
        return $this->request('POST', 'new', $data);
    }

    /**
     * 查询 ACME 订单
     */
    public function get(string|int $id): array
    {
        return $this->request('GET', 'get', ['order_id' => $id]);
    }

    /**
     * 取消 ACME 订单
     */
    public function cancel(string|int $id): array
    {
        return $this->request('POST', 'cancel', ['order_id' => $id]);
    }

    /**
     * 同步 ACME 订单
     */
    public function sync(string|int $id): array
    {
        return $this->request('POST', 'sync', ['order_id' => $id]);
    }

    /**
     * 获取产品列表
     */
    public function getProducts(string $brand = '', string $code = ''): array
    {
        $params = array_filter([
            'brand' => $brand,
            'code' => $code,
        ]);

        return $this->request('GET', 'get-products', $params);
    }

    /**
     * 发送 HTTP 请求
     *
     * 仅加日志写入（参考 Order Sdk 模式），不改业务逻辑（接口签名 / 返回值 / 错误处理保持原状）。
     */
    protected function request(string $method, string $uri, array $data = []): array
    {
        if (! $this->baseUrl || ! $this->apiToken) {
            return ['code' => 0, 'msg' => 'ACME API 未配置'];
        }

        $url = "$this->baseUrl/$uri";
        $startTime = microtime(true);
        $httpStatusCode = 0;
        $result = [];
        $caughtException = null;

        try {
            $request = Http::withToken($this->apiToken)
                ->timeout(30)
                ->acceptJson();

            $response = match (strtoupper($method)) {
                'GET' => $request->get($url, $data),
                'POST' => $request->asJson()->post($url, $data),
                default => $request->asJson()->send($method, $url, ['json' => $data]),
            };

            $httpStatusCode = $response->status();
            $responseData = $response->json() ?? [];

            if ($response->successful()) {
                if (! isset($responseData['code'])) {
                    $result = ['code' => 0, 'msg' => '上游返回格式错误'];
                } else {
                    $result = $responseData;
                }
            } else {
                $result = [
                    'code' => 0,
                    'msg' => $responseData['msg'] ?? 'HTTP '.$response->status(),
                    'errors' => $responseData['errors'] ?? [],
                ];
            }
        } catch (ConnectionException $e) {
            $caughtException = $e;
            $result = ['code' => 0, 'msg' => '上游连接失败'];
        }

        // 写入 ca_logs（参考 Order Sdk 模式 + correlation_id 由 LogBuffer 自动注入）
        LogBuffer::add(CaLog::class, [
            'url' => $this->baseUrl,
            'api' => $uri,
            'params' => LogScrubber::scrub($data),
            'response' => LogScrubber::scrubResponse($result),
            'status_code' => $httpStatusCode,
            'status' => intval($result['code'] ?? 0) === 1 ? 1 : 0,
            'duration' => round(microtime(true) - $startTime, 3),
        ]);

        return $result;
    }
}
