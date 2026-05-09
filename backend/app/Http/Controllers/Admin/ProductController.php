<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Product\CostRequest;
use App\Http\Requests\Product\ExportRequest;
use App\Http\Requests\Product\GetIdsRequest;
use App\Http\Requests\Product\ImportRequest;
use App\Http\Requests\Product\IndexRequest;
use App\Http\Requests\Product\StoreRequest;
use App\Http\Requests\Product\UpdateRequest;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\UserLevel;
use App\Services\Order\Action;
use App\Traits\ExcelHelperTrait;
use Exception;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends BaseController
{
    use ExcelHelperTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取产品列表
     *
     * @throws Exception
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Product::query();

        // 添加搜索条件
        if (! empty($validated['quickSearch'])) {
            $query->where(function ($query) use ($validated) {
                $query->where('name', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('code', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('remark', 'like', "%{$validated['quickSearch']}%");
            });
        }
        if (! empty($validated['brand'])) {
            // Product saving 钩子统一把 brand 存为小写；过滤入参也小写化，
            // 兼容大小写敏感的 driver（mysql 大小写敏感（binary collation），mysql utf8mb4_unicode_ci 不敏感）。
            $query->where('brand', strtolower($validated['brand']));
        }
        if (! empty($validated['product_type'])) {
            $types = (array) $validated['product_type'];
            $query->whereIn('product_type', $types);
        }
        if (! empty($validated['encryption_standard'])) {
            $query->where('encryption_standard', $validated['encryption_standard']);
        }
        if (! empty($validated['validation_type'])) {
            $query->where('validation_type', $validated['validation_type']);
        }
        if (! empty($validated['name_type'])) {
            $query->where(function ($query) use ($validated) {
                $query->whereJsonContains('common_name_types', $validated['name_type'])
                    ->orWhereJsonContains('alternative_name_types', $validated['name_type']);
            });
        }
        if (! empty($validated['domains']) && $validated['domains'] === 'single') {
            $query->whereJsonLength('alternative_name_types', 0);
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $total = $query->count();
        $items = $query->orderBy('weight')
            ->orderBy('id')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        // 附加价格信息：基础级别（custom=0）下的价格
        $level_codes = UserLevel::where('custom', 0)
            ->orderBy('weight')
            ->pluck('code')
            ->toArray();
        $items->each(function ($item) use ($level_codes) {
            $item->setAttribute('prices', ProductPrice::getProductPrice($item->id, $level_codes));
        });

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    /**
     * 添加产品
     */
    public function store(StoreRequest $request): void
    {
        $product = Product::create($request->validated());

        if (! $product->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取产品资料
     */
    public function show($id): void
    {
        $product = Product::find($id);
        if (! $product) {
            $this->error('产品不存在');
        }

        $this->success($product->toArray());
    }

    /**
     * 批量获取产品资料
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $products = Product::whereIn('id', $ids)->get();
        if ($products->isEmpty()) {
            $this->error('产品不存在');
        }

        $this->success($products->toArray());
    }

    /**
     * 更新产品资料
     */
    public function update(UpdateRequest $request, $id): void
    {
        $product = Product::find($id);
        if (! $product) {
            $this->error('产品不存在');
        }

        $product->fill($request->validated());
        $product->save();

        $this->success();
    }

    /**
     * 删除产品
     */
    public function destroy($id): void
    {
        $product = Product::find($id);
        if (! $product) {
            $this->error('产品不存在');
        }

        $product->delete();
        $this->success();
    }

    /**
     * 批量删除产品
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $products = Product::whereIn('id', $ids)->get();
        if ($products->isEmpty()) {
            $this->error('产品不存在');
        }

        Product::destroy($ids);
        $this->success();
    }

    /**
     * 导入产品
     */
    public function import(ImportRequest $request): void
    {
        $validated = $request->validated();
        $source = $validated['source'] ?? '';
        $brand = $validated['brand'] ?? '';
        $apiId = $validated['apiId'] ?? '';
        // new: 新增, update: 更新, all: 全部
        $type = $validated['type'] ?? 'new';

        app(Action::class)->importProduct($source, $brand, $apiId, $type);
    }

    /**
     * 获取产品成本信息
     */
    public function getCost(int $id): void
    {
        $product = Product::select(['periods', 'alternative_name_types', 'cost'])->find($id);
        if (! $product) {
            $this->error('产品不存在');
        }

        $this->success($product->toArray());
    }

    /**
     * 更新产品成本信息
     */
    public function updateCost(CostRequest $request, int $id): void
    {
        $product = Product::find($id);
        if (! $product) {
            $this->error('产品不存在');
        }

        // 获取验证后的数据
        $validated = $request->validated();

        // 更新产品的 cost 字段
        $product->cost = $validated['cost'];
        $product->save();

        $this->success();
    }

    /**
     * 获取来源列表
     */
    public function getSourceList(): void
    {
        $sources = get_system_setting('ca', 'sources');
        $result = [];

        // 判断是 key => value 数组还是 序列化数组
        if (is_array($sources)) {
            foreach ($sources as $key => $value) {
                if (is_int($key)) {
                    // 如果是数字索引，使用值作为key和label
                    $result[] = [
                        'value' => strtolower($value),
                        'label' => ucfirst(strtolower($value)),
                    ];
                } else {
                    // 如果是关联数组，使用key作为value，value作为label
                    $result[] = [
                        'value' => strtolower($key),
                        'label' => $value,
                    ];
                }
            }
        } else {
            // 默认值
            $result = [
                [
                    'value' => 'default',
                    'label' => 'Default',
                ],
            ];
        }

        $this->success($result);
    }

    /**
     * 导出产品价格列表
     */
    public function export(ExportRequest $request): StreamedResponse
    {
        $validated = $request->validated();

        // 设置默认值
        $brands = $validated['brands'] ?? null;
        $levelCustom = $validated['levelCustom'] ?? 1; // 默认定制
        $levelCodes = $validated['levelCodes'] ?? null; // 默认全部级别

        // 构建查询
        $query = Product::select([
            'products.name as product_name',
            'products.brand',
            'products.weight as product_weight',
            'user_levels.name as level_name',
            'user_levels.weight as level_weight',
            'product_prices.period',
            'product_prices.price',
            'product_prices.alternative_standard_price',
            'product_prices.alternative_wildcard_price',
        ])
            ->join('product_prices', 'products.id', '=', 'product_prices.product_id')
            ->join('user_levels', 'product_prices.level_code', '=', 'user_levels.code')
            ->where('products.status', 1)
            ->where('user_levels.custom', $levelCustom);

        // 应用筛选条件
        if (! empty($brands)) {
            $query->whereIn('products.brand', $brands);
        }

        if (! empty($levelCodes)) {
            $query->whereIn('user_levels.code', $levelCodes);
        }

        // 过滤出至少有一个价格大于0的记录
        $query->where(function ($query) {
            $query->where('product_prices.price', '>', 0)
                ->orWhere('product_prices.alternative_standard_price', '>', 0)
                ->orWhere('product_prices.alternative_wildcard_price', '>', 0);
        });

        // 排序：先按级别，再按产品，最后按周期
        $query->orderBy('user_levels.weight')
            ->orderBy('user_levels.name')
            ->orderBy('products.weight')
            ->orderBy('products.name')
            ->orderBy('product_prices.period');

        $data = $query->get();

        return $this->generateExcel($data);
    }

    /**
     * 生成 Excel 文件
     */
    private function generateExcel($data)
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('产品价格列表');

        $headers = ['会员级别', '产品名称', '周期', '价格', '附加标准域名价格', '附加通配符域名价格'];
        $sheet->fromArray($headers, null, 'A1');
        $headerRange = 'A1:F1';
        $endColumn = 'F';

        // 设置表头样式
        $this->setHeaderStyle($sheet, $headerRange);

        // 写入数据和处理合并
        $row = 2;
        $mergeRanges = [];
        $lastLevelName = null;
        $lastProductName = null;
        $levelStartRow = 2;
        $productStartRow = 2;

        foreach ($data as $item) {
            // 检查级别是否变化
            if ($lastLevelName !== null && $item->level_name !== $lastLevelName) {
                // 处理上一个级别的合并 - 只有多行才合并
                if ($levelStartRow < $row - 1) {
                    $mergeRanges[] = "A$levelStartRow:A".($row - 1);
                }
                // 处理上一个产品的合并 - 只有多行才合并
                if ($productStartRow < $row - 1) {
                    $mergeRanges[] = "B$productStartRow:B".($row - 1);
                }

                $levelStartRow = $row;
                $productStartRow = $row;
                $lastProductName = null;
            }

            // 检查产品是否变化（在同一级别内）
            if ($lastProductName !== null && $item->product_name !== $lastProductName) {
                // 处理上一个产品的合并 - 只有多行才合并
                if ($productStartRow < $row - 1) {
                    $mergeRanges[] = "B$productStartRow:B".($row - 1);
                }
                $productStartRow = $row;
            }

            // 更新当前值
            $lastLevelName = $item->level_name;
            $lastProductName = $item->product_name;

            // 写入数据
            $periodText = $this->formatPeriod($item->period);
            $values = [
                $item->level_name,
                $item->product_name,
                $periodText,
                $this->formatPrice($item->price),
                $this->formatPrice($item->alternative_standard_price),
                $this->formatPrice($item->alternative_wildcard_price),
            ];

            $sheet->fromArray($values, null, "A$row");
            $row++;
        }

        // 处理最后的合并
        if ($lastLevelName !== null && $levelStartRow < $row - 1) {
            $mergeRanges[] = "A$levelStartRow:A".($row - 1);
        }
        if ($lastProductName !== null && $productStartRow < $row - 1) {
            $mergeRanges[] = "B$productStartRow:B".($row - 1);
        }

        // 执行单元格合并
        $this->mergeCells($sheet, $mergeRanges);

        // 设置数据区域样式
        $dataRange = "A2:$endColumn".($row - 1);
        $this->setDataStyle($sheet, $dataRange);

        // 自动调整列宽
        $this->autoSizeColumns($sheet, 'A', $endColumn);

        // 生成文件名和返回下载响应
        $filename = '产品价格列表_'.date('Y-m-d_H-i-s').'.xlsx';

        return $this->createDownloadResponse($spreadsheet, $filename);
    }
}
