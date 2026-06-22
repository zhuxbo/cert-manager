<?php

declare(strict_types=1);

namespace App\Services\Acme\Api;

use App\Models\Acme;
use App\Traits\ApiResponse;

class Api
{
    use ApiResponse;

    /**
     * 下单
     *
     * source 是 manager 内部路由参数（决定走 default/Api 等哪个实现），独立于业务 data；
     * $data 直接发给上游 /api/v2/acme/new，字段集与上游 ApiController::new validate 对齐。
     */
    public function new(array $data, string $source): array
    {
        $api = $this->getSourceApi($source);

        return $this->handleResult($api->new($data));
    }

    /**
     * 查询/同步订单信息
     */
    public function get(int $orderId): array
    {
        $order = $this->findOrder($orderId);
        $api = $this->getSourceApi($order->product->source ?? '');

        return $this->handleResult($api->get($order->api_id, $order->toArray()));
    }

    /**
     * 取消订单
     */
    public function cancel(int $orderId): array
    {
        $order = $this->findOrder($orderId);
        $api = $this->getSourceApi($order->product->source ?? '');

        return $this->handleResult($api->cancel($order->api_id, $order->toArray()));
    }

    /**
     * 获取产品列表
     */
    public function getProducts(string $source, string $brand = '', string $code = ''): array
    {
        $api = $this->getSourceApi($source);

        return $this->handleResult($api->getProducts($brand, $code));
    }

    /**
     * 获取来源 API 实例
     */
    private function getSourceApi(string $source): AcmeSourceApiInterface
    {
        ! $source && $this->error('产品配置错误');

        $class = __NAMESPACE__.'\\'.strtolower($source).'\\Api';
        if (! class_exists($class)) {
            $this->error('产品配置错误');
        }

        return new $class;
    }

    /**
     * 查找 ACME 订单
     */
    private function findOrder(int $orderId): Acme
    {
        $order = Acme::with(['product'])
            ->whereHas('user')
            ->whereHas('product')
            ->find($orderId);

        if (! $order) {
            $this->error('未找到对应的订单');
        }

        return $order;
    }

    /**
     * 统一处理返回结果
     */
    private function handleResult(array $result): array
    {
        if ($result['code'] !== 1) {
            $errors = config('app.debug') ? ($result['errors'] ?? null) : null;
            $this->error($result['msg'] ?? 'CA 接口调用失败', $errors);
        }

        return $result;
    }
}
