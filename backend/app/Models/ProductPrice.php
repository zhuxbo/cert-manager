<?php

namespace App\Models;

use DB;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class ProductPrice extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'level_code',
        'period',
        'price',
        'alternative_standard_price',
        'alternative_wildcard_price',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'period' => 'integer',
        'price' => 'decimal:2',
        'alternative_standard_price' => 'decimal:2',
        'alternative_wildcard_price' => 'decimal:2',
    ];

    /**
     * 获取产品价格
     *
     * @throws Exception
     */
    public static function getProductPrice(int $product_id, array $level_codes): array
    {
        $product = Product::find($product_id);
        if (! $product) {
            throw new Exception('Product not found');
        }

        $price_types = ['price'];
        if (in_array('standard', $product->alternative_name_types ?? [])) {
            $price_types[] = 'alternative_standard_price';
        }
        if (in_array('wildcard', $product->alternative_name_types ?? [])) {
            $price_types[] = 'alternative_wildcard_price';
        }

        $query = self::where('product_id', $product_id);
        if (! empty($level_codes)) {
            $query->whereIn('level_code', $level_codes);
        }

        $product_prices = $query->get();
        $product_price = [];

        foreach ($product_prices as $price) {
            foreach ($price_types as $price_type) {
                if (isset($price->level_code) && isset($price->period)) {
                    $product_price[$price->level_code][$price_type][$price->period] = $price[$price_type];
                }
            }
        }

        // 以产品属性为准，补充缺失的价格
        $price = [];
        foreach ($level_codes as $level_code) {
            foreach ($price_types as $price_type) {
                foreach ($product->periods ?? [] as $period) {
                    $price[$level_code][$price_type][$period] = $product_price[$level_code][$price_type][$period] ?? 0;
                }
            }
        }

        return $price;
    }

    /**
     * 设置产品价格
     *
     * 调用方必须通过 ProductPriceMutationLock 持有全局价格变更锁。
     *
     * @throws Throwable
     */
    public static function setProductPrice(int $product_id, array $product_price): void
    {
        $product = Product::find($product_id);
        if (! $product) {
            throw new Exception('Product not found');
        }

        DB::beginTransaction();
        try {
            foreach ($product_price as $level_code => $price_types) {
                $prices = [];
                foreach ($price_types as $price_type => $periods) {
                    foreach ($periods as $period => $price) {
                        $prices[$period][$price_type] = number_format($price, 2, '.', '');
                    }
                }

                foreach ($prices as $period => $price) {
                    $price['alternative_standard_price'] = ($price['alternative_standard_price'] ?? 0) ?: 0;
                    $price['alternative_wildcard_price'] = ($price['alternative_wildcard_price'] ?? 0) ?: 0;

                    self::updateOrCreate(
                        [
                            'product_id' => $product_id,
                            'level_code' => $level_code,
                            'period' => $period,
                        ],
                        $price
                    );
                }
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(UserLevel::class, 'level_code', 'code');
    }
}
