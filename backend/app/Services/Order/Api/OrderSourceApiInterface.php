<?php

declare(strict_types=1);

namespace App\Services\Order\Api;

interface OrderSourceApiInterface
{
    public function getProducts(string $brand = '', string $code = ''): array;

    public function getOrders(int $page = 1, int $pageSize = 100, string $status = 'active'): array;

    public function new(array $data): array;

    public function renew(array $data): array;

    public function reissue(array $data): array;

    public function get(string|int $apiId, array $cert = []): array;

    public function cancel(string|int $apiId, array $cert = []): array;

    public function revalidate(string|int $apiId, array $cert = []): array;

    public function updateDCV(string|int $apiId, string $method, array $cert = []): array;

    // 注意：uploadDocument 不在本接口内——它是可选能力（部分 CA 不支持传文档），
    // 由各 source 按需实现，工厂侧用 checkMethodExists 运行时探测后调用。
}
