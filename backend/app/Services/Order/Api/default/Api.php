<?php

declare(strict_types=1);

namespace App\Services\Order\Api\default;

use App\Services\Order\Api\OrderSourceApiInterface;
use App\Utils\SnowFlake;

class Api implements OrderSourceApiInterface
{
    private Sdk $sdk;

    public function __construct()
    {
        $this->sdk = new Sdk;
    }

    /**
     * 获取产品
     */
    public function getProducts(string $brand = '', string $code = ''): array
    {
        return $this->sdk->getProducts($brand, $code);
    }

    /**
     * 获取订单
     */
    public function getOrders(int $page = 1, int $pageSize = 100, $status = 'active'): array
    {
        return $this->sdk->getOrders($page, $pageSize, $status);
    }

    /**
     * 购买证书（支持 SSL、SMIME、CodeSign）
     */
    public function new(array $data): array
    {
        $productType = $data['product_type'] ?? 'ssl';

        $params = match ($productType) {
            'smime' => $this->getNewSmimeParams($data),
            'codesign', 'docsign' => $this->getNewCodesignParams($data),
            default => $this->getNewSslParams($data),
        };

        $result = $this->sdk->new($params);

        // SMIME、CodeSign、DocSign 不需要 DCV 验证
        if (in_array($productType, ['smime', 'codesign', 'docsign'])) {
            return $this->getResultWithoutDcv($result);
        }

        return $this->getResult($result);
    }

    /**
     * 续费证书（支持 SSL、SMIME、CodeSign）
     */
    public function renew(array $data): array
    {
        $productType = $data['product_type'] ?? 'ssl';

        $params = match ($productType) {
            'smime' => $this->getNewSmimeParams($data),
            'codesign', 'docsign' => $this->getNewCodesignParams($data),
            default => $this->getNewSslParams($data),
        };
        $params['order_id'] = $data['last_api_id'];

        $result = $this->sdk->renew($params);

        // SMIME、CodeSign、DocSign 不需要 DCV 验证
        if (in_array($productType, ['smime', 'codesign', 'docsign'])) {
            return $this->getResultWithoutDcv($result);
        }

        return $this->getResult($result);
    }

    /**
     * 重新颁发（支持 SSL、SMIME、CodeSign）
     */
    public function reissue(array $data): array
    {
        $productType = $data['product_type'] ?? 'ssl';

        $params['refer_id'] = $data['refer_id'];
        $params['order_id'] = $data['last_api_id'];
        $params['csr'] = $data['csr'];

        if ($productType === 'ssl') {
            // SSL 需要 domains、validation_method
            $params['domains'] = $data['alternative_names'];
            $params['validation_method'] = $data['dcv']['method'];

            if (isset($data['unique_value'])) {
                $params['unique_value'] = $data['unique_value'] ?: $this->generateUniqueValue();
            }
        }

        if ($productType === 'smime') {
            // SMIME 需要 email
            $params['email'] = $data['email'] ?? '';
        }

        $result = $this->sdk->reissue($params);

        // SMIME 和 CodeSign 不需要 DCV 验证
        if ($productType !== 'ssl') {
            return $this->getResultWithoutDcv($result);
        }

        return $this->getResult($result);
    }

    /**
     * 整理购买SSL参数
     */
    protected function getNewSslParams(array $data): array
    {
        $params['refer_id'] = $data['refer_id'];
        $params['plus'] = $data['plus'];
        $params['product_code'] = $data['product_api_id'];
        $params['period'] = $data['period'];
        $params['csr'] = $data['csr'];
        $params['domains'] = $data['alternative_names'];
        $params['validation_method'] = $data['dcv']['method'];

        if (isset($data['unique_value'])) {
            $params['unique_value'] = $data['unique_value'] ?: $this->generateUniqueValue();
        }

        $params['contact'] = $data['contact'];

        if (isset($data['organization'])) {
            $params['organization'] = $data['organization'];
        }

        return $params;
    }

    /**
     * 整理购买 SMIME 证书参数
     * SMIME 不需要 CSR、domains、validation_method
     */
    protected function getNewSmimeParams(array $data): array
    {
        $params['refer_id'] = $data['refer_id'];
        $params['product_code'] = $data['product_api_id'];
        $params['period'] = $data['period'];
        $params['contact'] = $data['contact'];
        $params['csr'] = $data['csr'];
        $params['email'] = $data['email'];

        if (isset($data['organization'])) {
            $params['organization'] = $data['organization'];
        }

        return $params;
    }

    /**
     * 整理购买 CodeSign 证书参数
     * CodeSign 需要 CSR，但不需要 domains、validation_method
     */
    protected function getNewCodesignParams(array $data): array
    {
        $params['refer_id'] = $data['refer_id'];
        $params['product_code'] = $data['product_api_id'];
        $params['period'] = $data['period'];
        $params['csr'] = $data['csr'];
        $params['contact'] = $data['contact'];

        if (isset($data['organization'])) {
            $params['organization'] = $data['organization'];
        }

        return $params;
    }

    /**
     * 封装返回结果（SSL 证书）
     */
    protected function getResult(array $result): array
    {
        $apiId = $result['data']['order_id'] ?? '';

        if ($result['code'] === 1 && $apiId) {
            return [
                'data' => [
                    'api_id' => $apiId,
                    'cert_apply_status' => $result['data']['cert_apply_status'] ?? 0,
                    'dcv' => $result['data']['dcv'],
                    'validation' => $result['data']['validation'],
                ],
                'code' => 1,
            ];
        }

        return $result;
    }

    /**
     * 封装返回结果（不需要 DCV 验证）
     * 用于 SMIME 和 CodeSign 证书
     */
    protected function getResultWithoutDcv(array $result): array
    {
        $apiId = $result['data']['order_id'] ?? '';

        if ($result['code'] === 1 && $apiId) {
            return [
                'data' => [
                    'api_id' => $apiId,
                    'cert_apply_status' => $result['data']['cert_apply_status'] ?? 0,
                ],
                'code' => 1,
            ];
        }

        return $result;
    }

    /**
     * 获取订单信息
     */
    public function get(string|int $apiId, array $cert = []): array
    {
        return $this->sdk->get($apiId);
    }

    /**
     * 取消订单
     */
    public function cancel(string|int $apiId, array $cert = []): array
    {
        return $this->sdk->cancel($apiId);
    }

    /**
     * 重新验证
     */
    public function revalidate(string|int $apiId, array $cert = []): array
    {
        return $this->sdk->revalidate($apiId);
    }

    /**
     * 修改验证方法
     */
    public function updateDCV(string|int $apiId, string $method, array $cert = []): array
    {
        return $this->sdk->updateDCV($apiId, $method);
    }

    /**
     * 上传文档到上游
     */
    public function uploadDocument(string|int $apiId, array $data): array
    {
        return $this->sdk->uploadDocument($apiId, $data);
    }

    /**
     * 生成唯一值 Unique Value
     */
    protected function generateUniqueValue(): string
    {
        return 'cn'.SnowFlake::generateParticle();
    }
}
