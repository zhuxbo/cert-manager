<?php

namespace App\Services\Notification;

use App\Models\Product;

final class CertificateProductType
{
    /**
     * @var list<string>
     */
    private const SUPPORTED = [
        Product::TYPE_SSL,
        Product::TYPE_SMIME,
        Product::TYPE_CODESIGN,
        Product::TYPE_DOCSIGN,
    ];

    public static function normalize(mixed $productType): string
    {
        return is_string($productType) && in_array($productType, self::SUPPORTED, true)
            ? $productType
            : Product::TYPE_SSL;
    }

    public static function label(mixed $productType): string
    {
        return match (self::normalize($productType)) {
            Product::TYPE_SMIME => 'S/MIME',
            Product::TYPE_CODESIGN => '代码签名',
            Product::TYPE_DOCSIGN => '文档签名',
            default => 'SSL',
        };
    }
}
