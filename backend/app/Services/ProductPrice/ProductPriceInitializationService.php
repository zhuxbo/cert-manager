<?php

declare(strict_types=1);

namespace App\Services\ProductPrice;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\UserLevel;
use App\Services\Product\ProductCostNormalizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class ProductPriceInitializationService
{
    private const int TOKEN_TTL_MINUTES = 10;

    private const int INSERT_CHUNK_SIZE = 500;

    private const string MAX_PRICE = '999998.00';

    public function __construct(
        private readonly ProductCostNormalizer $costNormalizer,
        private readonly ProductPricePreviewToken $previewToken,
        private readonly ProductPriceMutationLock $mutationLock,
    ) {}

    public function preview(array $params, int $adminId): array
    {
        $normalizedParams = $this->normalizeParams($params);
        $levelCodes = $this->levelCodes($normalizedParams);
        $levels = $this->queryLevels($levelCodes, false);
        if ($levels->count() !== count($levelCodes)) {
            throw new InvalidArgumentException('会员级别不存在');
        }

        $products = $this->queryProducts(false);
        $productIds = $products->modelKeys();
        $prices = $this->queryPrices($levelCodes, $productIds, false);
        $built = $this->buildRows($products, $normalizedParams['levels'], $normalizedParams['precision']);
        $statistics = $this->statistics($built['targets'], $prices, $normalizedParams['force']);
        $warnings = $built['warnings'];
        $canExecute = $warnings === [];
        $token = null;

        if ($canExecute) {
            $token = $this->previewToken->issue(
                $adminId,
                $normalizedParams,
                $this->stateFingerprint($products, $levels, $prices),
                now()->addMinutes(self::TOKEN_TTL_MINUTES)->timestamp,
            );
        }

        return $this->response(
            preview: true,
            canExecute: $canExecute,
            executed: false,
            reason: $canExecute ? null : 'cost_validation',
            previewToken: $token,
            productCount: $products->count(),
            levelCount: $levels->count(),
            statistics: $statistics,
            syncedLevelCount: 0,
            warnings: $warnings,
        );
    }

    public function initialize(array $params, int $adminId, string $token): array
    {
        $normalizedParams = $this->normalizeParams($params);

        return $this->mutationLock->runWithLock(function () use ($normalizedParams, $adminId, $token): array {
            return DB::transaction(function () use ($normalizedParams, $adminId, $token): array {
                $levelCodes = $this->levelCodes($normalizedParams);
                $levels = $this->queryLevels($levelCodes, true);
                $products = $this->queryProducts(true);
                $productIds = $products->modelKeys();
                $prices = $this->queryPrices($levelCodes, $productIds, true);

                if ($levels->count() !== count($levelCodes)) {
                    return $this->staleResponse();
                }

                try {
                    $verified = $this->previewToken->verify($token, $adminId, $normalizedParams);
                } catch (InvalidArgumentException) {
                    return $this->staleResponse();
                }

                $fingerprint = $this->stateFingerprint($products, $levels, $prices);
                if (! hash_equals($verified['state_fingerprint'], $fingerprint)) {
                    return $this->staleResponse();
                }

                $built = $this->buildRows(
                    $products,
                    $normalizedParams['levels'],
                    $normalizedParams['precision'],
                );
                $statistics = $this->statistics($built['targets'], $prices, $normalizedParams['force']);
                if ($built['warnings'] !== []) {
                    return $this->response(
                        preview: false,
                        canExecute: false,
                        executed: false,
                        reason: 'cost_validation',
                        previewToken: null,
                        productCount: $products->count(),
                        levelCount: $levels->count(),
                        statistics: $this->zeroWriteStatistics($statistics),
                        syncedLevelCount: 0,
                        warnings: $built['warnings'],
                    );
                }

                $targetKeys = $this->rowKeySet($built['targets']);
                $existingKeys = $this->priceKeySet($prices);
                $preservedKeys = array_intersect_key($existingKeys, $targetKeys);

                if ($normalizedParams['force']) {
                    $deletedCount = ProductPrice::query()
                        ->whereIn('level_code', $levelCodes)
                        ->whereIn('product_id', $productIds)
                        ->delete();
                    $this->insertRows($built['rows']);
                    $afterPrices = $this->queryPrices($levelCodes, $productIds, false);
                    $afterTargetKeys = array_intersect_key($this->priceKeySet($afterPrices), $targetKeys);
                    $statistics = [
                        'target_count' => count($targetKeys),
                        'created_count' => 0,
                        'preserved_count' => 0,
                        'deleted_count' => $deletedCount,
                        'rebuilt_count' => count($afterTargetKeys),
                    ];
                } else {
                    $missingRows = array_values(array_filter(
                        $built['rows'],
                        fn (array $row): bool => ! isset($existingKeys[$this->rowKey($row)]),
                    ));
                    $this->insertRows($missingRows);
                    $afterPrices = $this->queryPrices($levelCodes, $productIds, false);
                    $afterTargetKeys = array_intersect_key($this->priceKeySet($afterPrices), $targetKeys);
                    $createdKeys = array_diff_key($afterTargetKeys, $preservedKeys);
                    $statistics = [
                        'target_count' => count($targetKeys),
                        'created_count' => count($createdKeys),
                        'preserved_count' => count($preservedKeys),
                        'deleted_count' => 0,
                        'rebuilt_count' => 0,
                    ];
                }

                if (count($afterTargetKeys) !== count($targetKeys)) {
                    throw new LogicException('产品价格目标唯一键覆盖不完整');
                }

                $syncedLevelCount = $normalizedParams['sync_cost_rates']
                    ? $this->syncLevelRates($levels, $normalizedParams['levels'])
                    : 0;
                $this->assertStatistics($statistics, $normalizedParams['force']);

                return $this->response(
                    preview: false,
                    canExecute: true,
                    executed: true,
                    reason: null,
                    previewToken: null,
                    productCount: $products->count(),
                    levelCount: $levels->count(),
                    statistics: $statistics,
                    syncedLevelCount: $syncedLevelCount,
                    warnings: [],
                );
            });
        });
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @param  list<array{code: string, cost_rate: string}>  $requestedLevels
     * @return array{targets: list<array{product_id: int, level_code: string, period: int}>, rows: list<array<string, int|string>>, warnings: list<array{product_id: int, product_name: string, period: int|null, field: string, message: string}>}
     */
    private function buildRows(EloquentCollection $products, array $requestedLevels, int $precision): array
    {
        $rows = [];
        $targets = [];
        $warnings = [];
        $timestamp = now()->toDateTimeString();

        foreach ($products as $product) {
            $periods = array_unique(array_map('intval', $product->periods ?? []));
            sort($periods, SORT_NUMERIC);
            $alternativeTypes = $product->alternative_name_types ?? [];
            foreach ($periods as $period) {
                foreach ($requestedLevels as $level) {
                    $targets[] = [
                        'product_id' => (int) $product->id,
                        'level_code' => $level['code'],
                        'period' => $period,
                    ];
                }
            }

            $validated = $this->costNormalizer->validateStored($product);
            foreach ($validated['warnings'] as $warning) {
                $warnings[] = $this->productWarning(
                    $product,
                    $warning['period'],
                    $warning['field'],
                    $warning['message'],
                );
            }
            if ($validated['warnings'] !== []) {
                continue;
            }

            foreach ($periods as $period) {
                $periodKey = (string) $period;
                foreach ($requestedLevels as $level) {
                    $row = [
                        'product_id' => (int) $product->id,
                        'level_code' => $level['code'],
                        'period' => $period,
                        'price' => '0.00',
                        'alternative_standard_price' => '0.00',
                        'alternative_wildcard_price' => '0.00',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                    $fields = ['price'];
                    if (in_array('standard', $alternativeTypes, true)) {
                        $fields[] = 'alternative_standard_price';
                    }
                    if (in_array('wildcard', $alternativeTypes, true)) {
                        $fields[] = 'alternative_wildcard_price';
                    }

                    $rowWarnings = [];
                    foreach ($fields as $field) {
                        $cost = $validated['cost'][$field][$periodKey];
                        $price = $this->roundPrice($cost, $level['cost_rate'], $precision);
                        $row[$field] = $price;

                        if ($cost !== '0' && bccomp($price, '0.00', 2) === 0) {
                            $rowWarnings[] = $this->productWarning(
                                $product,
                                $period,
                                $field,
                                '正成本按当前精度舍入后为 0.00',
                            );
                        }
                        if (bccomp($price, self::MAX_PRICE, 2) > 0) {
                            $rowWarnings[] = $this->productWarning(
                                $product,
                                $period,
                                $field,
                                '售价超过 999998.00 上限',
                            );
                        }
                    }

                    array_push($warnings, ...$rowWarnings);
                    $rows[] = $row;
                }
            }
        }

        return ['targets' => $targets, 'rows' => $rows, 'warnings' => $warnings];
    }

    private function roundPrice(string $cost, string $rate, int $precision): string
    {
        $workScale = max(6, $precision + 2);
        $raw = bcmul($cost, $rate, $workScale);
        $half = $precision === 0 ? '0.5' : '0.'.str_repeat('0', $precision).'5';
        $rounded = bcadd($raw, $half, $precision);
        [$integer, $fraction] = array_pad(explode('.', $rounded, 2), 2, '');

        return $integer.'.'.str_pad(substr($fraction, 0, $precision), 2, '0');
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @param  EloquentCollection<int, UserLevel>  $levels
     * @param  EloquentCollection<int, ProductPrice>  $prices
     */
    private function stateFingerprint(
        EloquentCollection $products,
        EloquentCollection $levels,
        EloquentCollection $prices,
    ): string {
        $canonicalProducts = $products->map(function (Product $product): array {
            $periods = array_map('intval', $product->periods ?? []);
            sort($periods, SORT_NUMERIC);
            $alternativeTypes = array_map('strval', $product->alternative_name_types ?? []);
            sort($alternativeTypes, SORT_STRING);

            return [
                'id' => (int) $product->id,
                'periods' => $periods,
                'alternative_name_types' => $alternativeTypes,
                'cost' => $product->getRawOriginal('cost'),
                'updated_at' => $product->getRawOriginal('updated_at'),
            ];
        })->sortBy('id')->values()->all();

        $canonicalLevels = $levels->map(fn (UserLevel $level): array => [
            'code' => (string) $level->code,
            'cost_rate' => (string) $level->getRawOriginal('cost_rate'),
            'updated_at' => $level->getRawOriginal('updated_at'),
        ])->sortBy('code')->values()->all();

        $canonicalPrices = $prices->map(fn (ProductPrice $price): array => [
            'id' => (int) $price->id,
            'product_id' => (int) $price->product_id,
            'level_code' => (string) $price->level_code,
            'period' => (int) $price->period,
            'price' => (string) $price->getRawOriginal('price'),
            'alternative_standard_price' => (string) $price->getRawOriginal('alternative_standard_price'),
            'alternative_wildcard_price' => (string) $price->getRawOriginal('alternative_wildcard_price'),
            'updated_at' => $price->getRawOriginal('updated_at'),
        ])->sortBy([
            ['product_id', 'asc'],
            ['level_code', 'asc'],
            ['period', 'asc'],
        ])->values()->all();

        return hash('sha256', json_encode([
            'products' => $canonicalProducts,
            'levels' => $canonicalLevels,
            'prices' => $canonicalPrices,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function normalizeParams(array $params): array
    {
        $levels = array_map(static fn (array $level): array => [
            'code' => (string) $level['code'],
            'cost_rate' => (string) $level['cost_rate'],
        ], $params['levels']);
        usort($levels, static fn (array $left, array $right): int => strcmp($left['code'], $right['code']));

        return [
            'levels' => $levels,
            'precision' => (int) $params['precision'],
            'force' => (bool) $params['force'],
            'sync_cost_rates' => (bool) $params['sync_cost_rates'],
        ];
    }

    private function levelCodes(array $params): array
    {
        return array_column($params['levels'], 'code');
    }

    /**
     * @return EloquentCollection<int, UserLevel>
     */
    private function queryLevels(array $codes, bool $lock): EloquentCollection
    {
        $query = UserLevel::query()->whereIn('code', $codes)->orderBy('code');

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    /**
     * @return EloquentCollection<int, Product>
     */
    private function queryProducts(bool $lock): EloquentCollection
    {
        $query = Product::query()->orderBy('id');

        if (! $lock) {
            return $query->where('status', 1)->get();
        }

        // 正式执行继续锁住完整产品集合，避免禁用状态在指纹校验和写入之间切换。
        return $query->lockForUpdate()
            ->get()
            ->filter(fn (Product $product): bool => $product->status === 1)
            ->values();
    }

    /**
     * @param  list<int>  $productIds
     * @return EloquentCollection<int, ProductPrice>
     */
    private function queryPrices(array $levelCodes, array $productIds, bool $lock): EloquentCollection
    {
        $query = ProductPrice::query()
            ->whereIn('level_code', $levelCodes)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderBy('level_code')
            ->orderBy('period');

        return $lock ? $query->lockForUpdate()->get() : $query->get();
    }

    /**
     * @param  EloquentCollection<int, ProductPrice>  $prices
     */
    private function statistics(array $rows, EloquentCollection $prices, bool $force): array
    {
        $existingKeys = $this->priceKeySet($prices);
        $preserved = count(array_filter(
            $rows,
            fn (array $row): bool => isset($existingKeys[$this->rowKey($row)]),
        ));

        return [
            'target_count' => count($rows),
            'created_count' => $force ? 0 : count($rows) - $preserved,
            'preserved_count' => $force ? 0 : $preserved,
            'deleted_count' => $force ? $prices->count() : 0,
            'rebuilt_count' => $force ? count($rows) : 0,
        ];
    }

    /**
     * @param  EloquentCollection<int, ProductPrice>  $prices
     */
    private function priceKeySet(EloquentCollection $prices): array
    {
        $keys = [];
        foreach ($prices as $price) {
            $keys[$this->rowKey([
                'product_id' => (int) $price->product_id,
                'level_code' => (string) $price->level_code,
                'period' => (int) $price->period,
            ])] = true;
        }

        return $keys;
    }

    private function rowKeySet(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[$this->rowKey($row)] = true;
        }

        return $keys;
    }

    private function rowKey(array $row): string
    {
        return $row['product_id'].'|'.$row['level_code'].'|'.$row['period'];
    }

    private function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            ProductPrice::query()->insert($chunk);
        }
    }

    /**
     * @param  EloquentCollection<int, UserLevel>  $levels
     */
    private function syncLevelRates(EloquentCollection $levels, array $requestedLevels): int
    {
        $requestedRates = [];
        foreach ($requestedLevels as $level) {
            $requestedRates[$level['code']] = $level['cost_rate'];
        }

        $updated = 0;
        foreach ($levels as $level) {
            $rate = $requestedRates[$level->code];
            if (bccomp((string) $level->getRawOriginal('cost_rate'), $rate, 4) === 0) {
                continue;
            }

            $updated += UserLevel::query()->whereKey($level->id)->update(['cost_rate' => $rate]);
        }

        return $updated;
    }

    private function assertStatistics(array $statistics, bool $force): void
    {
        if ($force) {
            if ($statistics['rebuilt_count'] !== $statistics['target_count']) {
                throw new LogicException('产品价格强制重建统计不一致');
            }

            return;
        }

        if ($statistics['target_count'] !== $statistics['created_count'] + $statistics['preserved_count']) {
            throw new LogicException('产品价格默认补齐统计不一致');
        }
    }

    private function staleResponse(): array
    {
        return $this->response(
            preview: false,
            canExecute: false,
            executed: false,
            reason: 'stale_preview',
            previewToken: null,
            productCount: 0,
            levelCount: 0,
            statistics: [
                'target_count' => 0,
                'created_count' => 0,
                'preserved_count' => 0,
                'deleted_count' => 0,
                'rebuilt_count' => 0,
            ],
            syncedLevelCount: 0,
            warnings: [],
        );
    }

    private function zeroWriteStatistics(array $statistics): array
    {
        return [
            'target_count' => $statistics['target_count'],
            'created_count' => 0,
            'preserved_count' => $statistics['preserved_count'],
            'deleted_count' => 0,
            'rebuilt_count' => 0,
        ];
    }

    private function response(
        bool $preview,
        bool $canExecute,
        bool $executed,
        ?string $reason,
        ?string $previewToken,
        int $productCount,
        int $levelCount,
        array $statistics,
        int $syncedLevelCount,
        array $warnings,
    ): array {
        return [
            'preview' => $preview,
            'can_execute' => $canExecute,
            'executed' => $executed,
            'reason' => $reason,
            'preview_token' => $previewToken,
            'product_count' => $productCount,
            'level_count' => $levelCount,
            ...$statistics,
            'synced_level_count' => $syncedLevelCount,
            'warnings' => $warnings,
        ];
    }

    private function productWarning(
        Product $product,
        ?int $period,
        string $field,
        string $message,
    ): array {
        return [
            'product_id' => (int) $product->id,
            'product_name' => (string) $product->name,
            'period' => $period,
            'field' => $field,
            'message' => $message,
        ];
    }
}
