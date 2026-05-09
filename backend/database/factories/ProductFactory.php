<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'code' => 'test_'.fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'api_id' => fake()->uuid(),
            'source' => 'test',
            'product_type' => Product::TYPE_SSL,
            'brand' => 'Test Brand',
            'ca' => 'Test CA',
            'warranty_currency' => 'USD',
            // warranty 列在 migration 里是 unsignedInteger；MySQL 容忍 '10000.00' 字符串截断成 10000，
            // PG 严格类型抛 "invalid input syntax for type integer"。统一用整数。
            'warranty' => 10000,
            'server' => 1,
            'encryption_standard' => 'international',
            'encryption_alg' => ['RSA-2048', 'RSA-4096'],
            'signature_digest_alg' => ['SHA256'],
            'validation_type' => 'dv',
            'common_name_types' => ['standard'],
            'alternative_name_types' => ['standard', 'wildcard'],
            'validation_methods' => ['txt', 'http', 'email'],
            'periods' => [12, 24],
            'standard_min' => 0,
            'standard_max' => 100,
            'wildcard_min' => 0,
            'wildcard_max' => 100,
            'total_min' => 1,
            'total_max' => 100,
            'add_san' => 1,
            'replace_san' => 1,
            'reissue' => 1,
            'renew' => 1,
            'reuse_csr' => 1,
            'gift_root_domain' => 0,
            'refund_period' => 30,
            'remark' => '',
            'weight' => 0,
            'status' => 1,
        ];
    }
}
