<?php

declare(strict_types=1);

namespace App\Services\Order\Api;

use App\Models\Order;
use App\Traits\ApiResponse;

class Api
{
    use ApiResponse;

    /**
     * 获取产品
     */
    public function getProducts(string $source = '', string $brand = '', string $code = ''): array
    {
        $api = $this->getSourceApi($source);

        return $this->handleResult($api->getProducts($brand, $code));
    }

    /**
     * 获取订单
     */
    public function getOrders(string $source = '', int $page = 1, int $pageSize = 100, $status = 'active'): array
    {
        $api = $this->getSourceApi($source);
        $this->checkMethodExists($api, 'getOrders');

        return $this->handleResult($api->getOrders($page, $pageSize, $status));
    }

    /**
     * 购买SSL
     */
    public function new(array $data): array
    {
        $source = $data['source'] ?? '';
        $api = $this->getSourceApi($source);

        return $this->handleResult($api->new($data));
    }

    /**
     * 续订SSL
     */
    public function renew(array $data): array
    {
        $source = $data['source'] ?? '';
        $api = $this->getSourceApi($source);

        return $this->handleResult($api->renew($data));
    }

    /**
     * 重新颁发
     */
    public function reissue(array $data): array
    {
        $source = $data['source'] ?? '';
        $api = $this->getSourceApi($source);

        return $this->handleResult($api->reissue($data));
    }

    /**
     * 获取订单信息
     */
    public function get(int $orderId): array
    {
        $order = $this->findOrder($orderId, requireApiId: true);
        $api = $this->getSourceApi($order->product->source ?? '');

        return $this->handleResult($api->get($order->latestCert->api_id, $order->latestCert->toArray()));
    }

    /**
     * 取消订单
     */
    public function cancel(int $orderId): array
    {
        $order = $this->findOrder($orderId, requireApiId: true);
        $api = $this->getSourceApi($order->product->source ?? '');

        return $this->handleResult($api->cancel($order->latestCert->api_id, $order->latestCert->toArray()));
    }

    /**
     * 重新验证
     */
    public function revalidate(int $orderId): array
    {
        $order = $this->findOrder($orderId, requireApiId: true);
        $api = $this->getSourceApi($order->product->source ?? '');

        return $this->handleResult($api->revalidate($order->latestCert->api_id, $order->latestCert->toArray()));
    }

    /**
     * 修改验证方法
     */
    public function updateDCV(int $orderId, string $method): array
    {
        $order = $this->findOrder($orderId, requireApiId: true);
        $api = $this->getSourceApi($order->product->source ?? '');

        return $this->handleResult($api->updateDCV($order->latestCert->api_id, $method, $order->latestCert->toArray()));
    }

    /**
     * 上传文档到上游
     */
    public function uploadDocument(int $orderId, array $data): array
    {
        $order = $this->findOrder($orderId);
        $api = $this->getSourceApi($order->product->source ?? '');
        // uploadDocument 是可选能力（部分 CA 不支持传文档），不进 OrderSourceApiInterface；
        // 运行时探测 source 是否实现，未实现给友好错误而非 fatal。
        $this->checkMethodExists($api, 'uploadDocument');

        // 与其他操作方法统一：上游标识 api_id 作为独立首参传给 source（不埋进 $data）。
        // 返回 source 原始结果（caller 按 $result['code'] 判定），不经 handleResult。
        /** @phpstan-ignore method.notFound */
        return $api->uploadDocument($order->latestCert->api_id, $data);
    }

    /**
     * 设置产品来源
     */
    private function getSourceApi(string $source): OrderSourceApiInterface
    {
        ! $source && $this->error('产品配置错误');

        $class = __NAMESPACE__.'\\'.strtolower($source).'\\Api';
        if (! class_exists($class)) {
            $this->error('产品配置错误');
        }

        return new $class;
    }

    /**
     * 检查方法是否存在
     */
    private function checkMethodExists(object $instance, string $method): void
    {
        if (! method_exists($instance, $method)) {
            $this->error('不支持此方法');
        }
    }

    /**
     * 根据API ID查找订单
     */
    private function findOrder(int $orderId, bool $requireApiId = false): Order
    {
        $order = Order::with(['product', 'latestCert'])
            ->whereHas('user')
            ->whereHas('product')
            ->whereHas('latestCert')
            ->find($orderId);

        if (! $order) {
            $this->error('未找到对应的订单');
        }

        if ($requireApiId && ! $order->latestCert->api_id) {
            $this->error('订单尚未提交，无法执行此操作');
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
