<?php

declare(strict_types=1);

namespace App\Services\Order\Api\default;

use App\Bootstrap\ApiExceptions;
use App\Models\CaLog;
use App\Services\LogBuffer;
use App\Utils\LogScrubber;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Sdk
{
    /**
     * 获取产品
     */
    public function getProducts(string $brand = '', string $code = ''): array
    {
        return $this->call('get-products', ['brand' => $brand, 'code' => $code], 'get');
    }

    /**
     * 获取订单
     */
    public function getOrders(int $page = 1, int $pageSize = 100, $status = 'active'): array
    {
        return $this->call('get-orders', ['page' => $page, 'page_size' => $pageSize, 'status' => $status], 'get');
    }

    /**
     * 申请证书
     */
    public function new(array $params): array
    {
        return $this->call('new', $params);
    }

    /**
     * 续费证书
     */
    public function renew(array $params): array
    {
        return $this->call('renew', $params);
    }

    /**
     * 重新颁发
     */
    public function reissue(array $params): array
    {
        return $this->call('reissue', $params);
    }

    /**
     * 取消证书
     */
    public function cancel(string|int $apiId): array
    {
        return $this->call('cancel', ['order_id' => $apiId]);
    }

    /**
     * 重新验证
     */
    public function revalidate(string|int $apiId): array
    {
        return $this->call('revalidate', ['order_id' => $apiId]);
    }

    /**
     * 修改验证方法
     */
    public function updateDCV(string|int $apiId, string $method): array
    {
        return $this->call('update-dcv', ['order_id' => $apiId, 'method' => $method]);
    }

    /**
     * 获取订单信息
     */
    public function get(string|int $apiId): array
    {
        return $this->call('get', ['order_id' => $apiId], 'get');
    }

    /**
     * 上传文档（使用 JSON 发送，避免 base64 被 URL-encoded 二次膨胀）
     */
    public function uploadDocument(array $data): array
    {
        return $this->call('upload-document', $data, 'json');
    }

    /**
     * 提交接口请求
     */
    protected function call(string $uri, array $data = [], $method = 'post'): array
    {
        $apiUrl = rtrim(get_system_setting('ca', 'url'), '/');
        $apiToken = get_system_setting('ca', 'token');

        if (! $apiUrl || ! $apiToken) {
            return ['code' => 0, 'msg' => 'Api url or token is not set'];
        }

        $url = $apiUrl.'/'.$uri;

        $client = new Client;
        try {
            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$apiToken,
                ],
                'http_errors' => false,
            ];
            if ($method === 'get') {
                $options['query'] = $data;
            } elseif ($method === 'json') {
                $method = 'post';
                $options['json'] = $data;
            } else {
                $options['form_params'] = $data;
            }
            $response = $client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            app(ApiExceptions::class)->logException($e);

            return ['code' => 0, 'msg' => 'Request failed: '.$e->getMessage()];
        }

        $result = json_decode($response->getBody()->getContents(), true);

        $httpStatusCode = $response->getStatusCode();

        LogBuffer::add(CaLog::class, [
            'url' => $apiUrl,
            'api' => $uri,
            'params' => LogScrubber::scrub($data),
            'response' => LogScrubber::scrubResponse($result),
            'status_code' => $httpStatusCode,
            'status' => intval($result['code'] ?? 0) === 1 ? 1 : 0,
        ]);

        // Http 状态码 200 为成功
        if ($httpStatusCode == 200) {
            if (! isset($result['code'])) {
                return ['code' => 0, 'msg' => 'No return code'];
            }

            // cancel 时，如果订单已取消，则返回成功
            if ($uri === 'cancel' && isset($result['msg']) && $result['msg'] == '订单已取消') {
                return ['code' => 1];
            }

            // new，renew，reissue 时，如果错误信息中包含 Refer id，尝试通过refer_id 获取订单号
            if ($result['code'] === 0 && in_array($uri, ['new', 'renew', 'reissue']) && str_contains($result['msg'] ?? '', 'Refer id')) {
                $getApiIdResult = $this->getOrderIdByReferId($data['refer_id']);

                if ($getApiIdResult['code'] === 1 && $getApiIdResult['data']['order_id']) {
                    $getOrderResult = $this->get($getApiIdResult['data']['order_id']);

                    if ($getOrderResult['code'] === 1) {
                        return [
                            'data' => [
                                'order_id' => $getApiIdResult['data']['order_id'],
                                'cert_apply_status' => $getOrderResult['data']['cert_apply_status'] ?? 0,
                                'dcv' => $getOrderResult['data']['dcv'] ?? null,
                                'validation' => $getOrderResult['data']['validation'] ?? null,
                            ],
                            'code' => 1,
                        ];
                    }
                }
            }

            // 错误信息为余额不足时，返回系统内部错误
            if ($result['code'] === 0 && str_contains($result['msg'], '余额不足')) {
                $result['msg'] = '系统内部错误，请联系管理员';
            }

            if ($result['code'] != 1) {
                return ['code' => 0, 'msg' => $result['msg'] ?? 'Unknown error', 'errors' => $result['errors'] ?? null];
            }
        } else {
            return ['code' => 0, 'msg' => 'Http status code '.$httpStatusCode];
        }

        return $result;
    }

    /**
     * 根据 refId 获取订单 ID
     */
    protected function getOrderIdByReferId(string $referId): array
    {
        return $this->call('get-order-id-by-refer-id', ['refer_id' => $referId], 'get');
    }
}
