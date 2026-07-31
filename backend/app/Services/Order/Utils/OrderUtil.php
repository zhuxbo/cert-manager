<?php

declare(strict_types=1);

namespace App\Services\Order\Utils;

use App\Bootstrap\ApiExceptions;
use App\Models\ProductPrice;
use App\Models\Transaction;
use App\Traits\ApiResponseStatic;
use Exception;

class OrderUtil
{
    use ApiResponseStatic;

    /**
     * 从备用域名字符串中获取SAN数量
     * 计算费的时候根据产品参数设置 gift_root_domain
     * 校验域名最大最小数量时设置 gift_root_domain 为 0
     */
    public static function getSansFromDomains(string $domains, int $giftRootDomain = 0): array
    {
        if ($giftRootDomain) {
            $domains = DomainUtil::removeGiftDomain($domains);
        }

        $standardCount = 0;
        $wildcardCount = 0;

        // 拆分字符串并过滤空值
        foreach (array_filter(explode(',', $domains)) as $item) {
            if (str_starts_with($item, '*.')) {
                $wildcardCount++;
            } else {
                $standardCount++;
            }
        }

        return [
            'standard_count' => $standardCount,
            'wildcard_count' => $wildcardCount,
        ];
    }

    /**
     * 从验证数组中获取验证邮箱
     */
    public static function getEmail(string $domain, array $validation): string
    {
        $email = '';
        foreach ($validation as $v) {
            $email = $v['domain'] === $domain ? $v['email'] : '';
        }
        ! $email && self::error('Email not found');

        return $email;
    }

    /**
     * 从验证数组中获取验证方法
     */
    public static function getMethod(string $domain, array $validation): string
    {
        $method = '';
        foreach ($validation as $v) {
            if ($v['domain'] === $domain) {
                $method = $v['method'];
            }
        }
        if (! $method) {
            self::error('Method not found');
        }

        return $method;
    }

    /**
     * 从验证数组中获取域名验证状态
     */
    public static function getVerified(string $domain, array $validation): int
    {
        $verified = -1;
        foreach ($validation as $v) {
            if ($v['domain'] === $domain) {
                $verified = $v['verified'] ?? 0;
            }
        }
        if ($verified === -1) {
            self::error('Domain not found');
        }

        return $verified;
    }

    /**
     * 移除已验证的域名
     */
    public static function removeVerifiedDCV(array $validation): array
    {
        foreach ($validation as $k => $v) {
            if ($v['verified'] ?? 0) {
                unset($validation[$k]);
            }
        }

        return $validation;
    }

    /**
     * 批量修改验证方法时 仅允许修改未验证的域名
     */
    public static function filterUnverified(array $validation, array $currentValidation): array
    {
        $unverifiedDomains = [];
        foreach ($currentValidation as $value) {
            ($value['verified'] ?? 0) || $unverifiedDomains[] = $value['domain'];
        }
        $validationUnverified = [];
        foreach ($validation as $value) {
            if (in_array($value['domain'], $unverifiedDomains)) {
                $validationUnverified[] = $value;
            }
        }

        return $validationUnverified;
    }

    /**
     * 获取交易数据
     *
     * @param  array  $order  [id,user_id,product_id,period,purchased_standard_count,purchased_wildcard_count,
     *                        latestCert.action,latestCert.standard_count,latestCert.wildcard_count,
     *                        product.standard_min,product.wildcard_min,user.balance]
     */
    public static function getOrderTransaction(array $order, string $transactionType = 'order'): array
    {
        $product = FindUtil::Product($order['product_id'])->toArray();
        $latestCert = $order['latest_cert'];

        $remark = self::composeOrderRemark($order, $latestCert, $product);

        $productType = $product['product_type'] ?? 'ssl';

        $orderPurchasedStandardCount = (int) ($order['purchased_standard_count'] ?? 0);
        $orderPurchasedWildcardCount = (int) ($order['purchased_wildcard_count'] ?? 0);

        // transaction 记录本次实际购买的域名数量：
        // - SSL 用 cert.standard_count/wildcard_count 减去订单已购基线
        // - ACME 直接取 acme.purchased_*（无 Cert 且全量计费）
        // - SMIME / CodeSign / DocSign 无 SAN，固定 0
        if ($productType === 'acme') {
            $purchasedStandardCount = $orderPurchasedStandardCount;
            $purchasedWildcardCount = $orderPurchasedWildcardCount;
        } elseif ($productType === 'ssl') {
            $standardCount = (int) ($latestCert['standard_count'] ?? 0);
            $wildcardCount = (int) ($latestCert['wildcard_count'] ?? 0);
            $purchasedStandardCount = max($standardCount - $orderPurchasedStandardCount, 0);
            $purchasedWildcardCount = max($wildcardCount - $orderPurchasedWildcardCount, 0);
        } else {
            $purchasedStandardCount = 0;
            $purchasedWildcardCount = 0;
        }

        return [
            'user_id' => $order['user_id'],
            'type' => $transactionType,
            'transaction_id' => $order['id'],
            'amount' => '-'.$order['latest_cert']['amount'],
            'standard_count' => $purchasedStandardCount,
            'wildcard_count' => $purchasedWildcardCount,
            'remark' => $remark,
        ];
    }

    /**
     * 获取取消交易数据
     */
    public static function getCancelTransaction(array $order, string $transactionType = 'cancel'): array
    {
        $orderId = $order['id'];
        $userId = $order['user_id'];
        $amount = $order['amount'];

        // 查找当前用户该订单所有交易记录
        $prefix = str_contains($transactionType, 'acme_') ? 'acme_' : '';
        $transactions = Transaction::whereIn('type', [$prefix.'order', $prefix.'cancel'])
            ->where('transaction_id', $orderId)
            ->where('user_id', $userId)
            ->get();

        // 计算所有交易记录的金额
        $transactionAmount = '0.00';
        foreach ($transactions as $transaction) {
            $transactionAmount = bcadd($transactionAmount, $transaction->amount, 2);
        }

        // 交易记录订单金额为负数所以要取反
        $transactionAmount = strval(-floatval($transactionAmount));

        // 比较交易记录金额和订单金额 如果不等 以交易金额为准 并创建交易错误日志
        if (bccomp($transactionAmount, $amount, 2) !== 0) {
            $amount = $transactionAmount;
            app(ApiExceptions::class)->logException(new Exception('取消订单，交易金额与订单金额不符，以交易金额为准'));
        }

        return [
            'user_id' => $userId,
            'type' => $transactionType,
            'transaction_id' => $orderId,
            'amount' => $amount,
            'standard_count' => -$order['purchased_standard_count'],
            'wildcard_count' => -$order['purchased_wildcard_count'],
        ];
    }

    /**
     * 获取最新证书订购金额
     */
    public static function getLatestCertAmount(array $order, array $latestCert = [], array $product = []): string
    {
        [$minPrice, $purchasedStandardCount, $purchasedWildcardCount] =
            self::getTransactionParams($order, $latestCert, $product);

        $sanPrice = bcadd(
            bcmul((string) ($minPrice['alternative_standard_price'] ?? '0'), (string) $purchasedStandardCount, 2),
            bcmul((string) ($minPrice['alternative_wildcard_price'] ?? '0'), (string) $purchasedWildcardCount, 2),
            2
        );

        $amount = bcadd((string) ($minPrice['price'] ?? '0'), $sanPrice, 2);
        bccomp($amount, '999998', 2) > 0 && self::error('订单金额超出限制');

        return $amount;
    }

    /**
     * 转换数值时必须保持字符串的键（任意层级按键名豁免）
     *
     * 这些字段的取值语义就是「一段文本」，被转成数字后下游按 string 声明的形参会直接抛
     * TypeError：`csr='0'` 曾让 Deploy 的 POST /api/deploy 返回 **HTTP 400 + 裸 TypeError 文案**
     * （`CsrUtil::matchKey(): Argument #1 ($csr) must be of type string, int given`，还带内部路径），
     * 违反「业务失败一律 HTTP 200 + code=0」的契约（有意保留的非 200 出口只有并发忙的 503 与
     * `field=` PEM 直出两条，TypeError 不在其列），下游按网络错误无限重试。豁免后畸形取值
     * 照常走进签发链路、由 CSR 解析报出业务错误（200 + code=0），失败可见且可分类。
     */
    private const PRESERVE_STRING_KEYS = ['csr', 'private_key', 'registration_number'];

    /**
     * 转换数组中的数值字符串为数值
     */
    public static function convertNumericValues(array $array): array
    {
        $newArray = [];
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                // 如果是数组，递归调用
                $newArray[$key] = self::convertNumericValues($value);
            } elseif (in_array($key, self::PRESERVE_STRING_KEYS, true)) {
                $newArray[$key] = $value;
            } elseif (is_numeric($value) && (! is_string($value) || (! preg_match('/[eE]/', $value) && strlen($value) <= 15))) {
                $newArray[$key] = $value + 0;
            } else {
                // 非数字保持原样
                $newArray[$key] = $value;
            }
        }

        return $newArray;
    }

    /**
     * 生成订单备注
     */
    protected static function composeOrderRemark(array $order, array $latestCert = [], array $product = []): string
    {
        [$minPrice, $purchasedStandardCount, $purchasedWildcardCount, $standardMin, $wildcardMin, $action] =
            self::getTransactionParams($order, $latestCert, $product);

        $actionToCn = [
            'new' => '新购',
            'renew' => '续费',
            'reissue' => '增购',
        ];

        $remark = $actionToCn[$action] ?? '';

        $remark .= $standardMin && ! empty($minPrice['price']) && $minPrice['price'] !== '0.00'
            ? ' 标准域名'.$standardMin.'个'
            : '';

        $remark .= $wildcardMin && ! empty($minPrice['price']) && $minPrice['price'] !== '0.00'
            ? ' 通配符'.$wildcardMin.'个'
            : '';

        $remark .= ! empty($minPrice['price']) && $minPrice['price'] !== '0.00' ? $minPrice['price'].'元' : '';

        $standardSansPrice = bcmul((string) $purchasedStandardCount, (string) ($minPrice['alternative_standard_price'] ?? '0'), 2);

        $remark .= $purchasedStandardCount && ! empty($minPrice['alternative_standard_price']) && $minPrice['alternative_standard_price'] !== '0.00'
            ? ' 标准域名'.$purchasedStandardCount.'个'.$standardSansPrice.'元'
            : '';

        $wildcardSansPrice = bcmul((string) $purchasedWildcardCount, (string) ($minPrice['alternative_wildcard_price'] ?? '0'), 2);

        $remark .= $purchasedWildcardCount && ! empty($minPrice['alternative_wildcard_price']) && $minPrice['alternative_wildcard_price'] !== '0.00'
            ? ' 通配符'.$purchasedWildcardCount.'个'.$wildcardSansPrice.'元'
            : '';

        return trim($remark);
    }

    /**
     * 获取交易参数
     */
    protected static function getTransactionParams(array $order, array $latestCert = [], array $product = []): array
    {
        if (empty($order['latest_cert']) && empty($latestCert)) {
            $latestCert = FindUtil::Cert($order['latest_cert_id'])->toArray();
        } else {
            $latestCert = $latestCert ?: $order['latest_cert'];
        }

        if (empty($order['product']) && empty($product)) {
            $product = FindUtil::Product($order['product_id'])->toArray();
        } else {
            $product = $product ?: $order['product'];
        }

        $action = $latestCert['action'] ?? 'new';

        $productType = $product['product_type'] ?? 'ssl';

        $userId = (int) $order['user_id'];
        $productId = (int) $order['product_id'];
        $period = (int) $order['period'];
        $orderPurchasedStandardCount = (int) ($order['purchased_standard_count'] ?? 0);
        $orderPurchasedWildcardCount = (int) ($order['purchased_wildcard_count'] ?? 0);

        // SAN 数量来源按产品类型路由：
        // - SSL 来自 cert.standard_count / wildcard_count
        // - ACME 来自 acme.purchased_standard_count / purchased_wildcard_count（无 Cert 模型）
        //   ACME 为全量计费，不需减去已购基线，故把"已购"视为 0
        // - SMIME / CodeSign / DocSign 无 SAN，固定 0
        if ($productType === 'acme') {
            $standardCount = $orderPurchasedStandardCount;
            $wildcardCount = $orderPurchasedWildcardCount;
            $orderPurchasedStandardCount = 0;
            $orderPurchasedWildcardCount = 0;
        } elseif ($productType === 'ssl') {
            $standardCount = (int) ($latestCert['standard_count'] ?? 0);
            $wildcardCount = (int) ($latestCert['wildcard_count'] ?? 0);
        } else {
            $standardCount = 0;
            $wildcardCount = 0;
        }

        $standardMin = (int) $product['standard_min'];
        $wildcardMin = (int) $product['wildcard_min'];
        $totalMin = (int) $product['total_min'];

        $minPrice = self::getMinPrice($userId, $productId, $period);

        $purchasedStandardCount = max($standardCount - $orderPurchasedStandardCount, 0);
        $purchasedWildcardCount = max($wildcardCount - $orderPurchasedWildcardCount, 0);

        // 重新签发时都设置为0 只计算新增部分
        if ($action === 'reissue') {
            $standardMin = 0;
            $wildcardMin = 0;
            $totalMin = 0;
            $minPrice['price'] = '0';
        }

        if ($purchasedStandardCount + $purchasedWildcardCount < $totalMin) {
            $purchasedStandardCount = max($purchasedStandardCount, $totalMin - $purchasedWildcardCount);
        }

        $purchasedStandardCount = max($purchasedStandardCount - $standardMin, 0);
        $purchasedWildcardCount = max($purchasedWildcardCount - $wildcardMin, 0);

        return [
            $minPrice, $purchasedStandardCount, $purchasedWildcardCount, $standardMin, $wildcardMin, $action,
        ];
    }

    /**
     * 取 level_code + custom_level_code 两行 ProductPrice。
     *
     * getMinPrice 与 hasPriceConfigured 共用同一取行源，保证「守卫放行」与「估价取值」永远一致，
     * 不会出现「hasPriceConfigured 说有价、getMinPrice 却取到 0」的漂移。
     *
     * @return array{0: ?ProductPrice, 1: ?ProductPrice} [level 行, custom level 行]
     */
    private static function fetchPriceRows(int $userId, int $productId, int $period): array
    {
        $user = FindUtil::User($userId);

        return [
            ProductPrice::where([
                'level_code' => $user->level_code,
                'product_id' => $productId,
                'period' => $period,
            ])->first(),
            ProductPrice::where([
                'level_code' => $user->custom_level_code ?? '',
                'product_id' => $productId,
                'period' => $period,
            ])->first(),
        ];
    }

    /**
     * 价格行是否已配置（存在性守卫，A4 零价成单防线）。
     *
     * 行存在即已配置——含显式 price=0.00 的真免费产品（放行）；无任何行 = 缺价（拒绝）。
     * price 列 NOT NULL default 0，不存在「行在但价为 NULL」第三形态，故存在性二分健全。
     * 供 auto-renew 续费前置校验：缺价即跳过，杜绝 getMinPrice `?? '0'` 传导出的 0 元静默续费。
     */
    public static function hasPriceConfigured(int $userId, int $productId, int $period): bool
    {
        [$levelPrice, $customLevelPrice] = self::fetchPriceRows($userId, $productId, $period);

        return $levelPrice !== null || $customLevelPrice !== null;
    }

    /**
     * 获取最低价格
     */
    public static function getMinPrice(int $userId, int $productId, int $period): array
    {
        [$levelPrice, $customLevelPrice] = self::fetchPriceRows($userId, $productId, $period);

        $minPrice = [];

        $minPrice['price'] =
            self::calculateMinPrice(
                $levelPrice->price ?? null,
                $customLevelPrice->price ?? null
            ) ?? '0';
        $minPrice['alternative_standard_price'] =
            self::calculateMinPrice(
                $levelPrice->alternative_standard_price ?? null,
                $customLevelPrice->alternative_standard_price ?? null
            ) ?? '0';
        $minPrice['alternative_wildcard_price'] =
            self::calculateMinPrice(
                $levelPrice->alternative_wildcard_price ?? null,
                $customLevelPrice->alternative_wildcard_price ?? null
            ) ?? '0';

        return $minPrice;
    }

    /**
     * 计算最低价格
     */
    private static function calculateMinPrice(?string $price1, ?string $price2): ?string
    {
        if (isset($price1) && isset($price2)) {
            return bccomp($price1, $price2, 2) > 0 ? $price2 : $price1;
        }

        return $price1 ?? $price2;
    }
}
