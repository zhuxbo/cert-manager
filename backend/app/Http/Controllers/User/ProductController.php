<?php

namespace App\Http\Controllers\User;

use App\Http\Requests\Product\ExportRequest;
use App\Http\Requests\Product\IndexRequest;
use App\Models\Product;
use App\Services\Order\Utils\OrderUtil;
use App\Traits\ExcelHelperTrait;
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
        } else {
            $query->where('product_type', '!=', Product::TYPE_ACME);
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

        $total = $query->where('status', 1)->count();
        $items = $query->select(['id', 'name', 'product_type', 'brand', 'ca', 'periods', 'encryption_standard', 'validation_type',
            'common_name_types', 'alternative_name_types', 'weight', 'remark', 'refund_period'])
            ->orderBy('weight', 'asc')
            ->orderBy('id', 'asc')
            ->where('status', 1)
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        if ($this->guard->user()->id ?? 0) {
            // 遍历查询结果并获取会员价格
            foreach ($items as $item) {
                // 返回所有周期价格
                $prices = [];
                foreach ($item->periods as $period) {
                    $prices[$period] = array_filter(OrderUtil::getMinPrice($this->guard->user()->id, $item->id, (int) $period));
                }
                $item->setAttribute('prices', $prices);
                // 保留最小周期的 price 字段，兼容列表默认显示
                $minPeriod = min($item->periods);
                $price = $prices[$minPeriod] ?? [];
                $price['period'] = $minPeriod;
                $item->setAttribute('price', $price);
            }
        }

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    /**
     * 获取产品资料
     */
    public function show($id): void
    {
        $product = Product::where('status', 1)->find($id);
        if (! $product) {
            $this->error('产品不存在');
        }

        if ($this->guard->user()->id ?? 0) {
            // 获取会员价格
            $price = [];
            foreach ($product->periods as $period) {
                $price[$period] = array_filter(OrderUtil::getMinPrice($this->guard->user()->id, $product->id, (int) $period));
            }
            $product->setAttribute('price', $price);
        }

        $product->makeHidden([
            'api_id',
            'source',
            'cost',
            'created_at',
            'updated_at',
        ]);

        $this->success($product->toArray());
    }

    /**
     * 导出产品价格列表
     */
    public function export(ExportRequest $request): StreamedResponse
    {
        $validated = $request->validated();

        // 获取当前用户的会员级别
        $user = $this->guard->user();
        if (! $user) {
            abort(401, '用户未登录');
        }

        // 设置默认值
        $brands = $validated['brands'] ?? null;
        $priceRate = $validated['priceRate'] ?? 1;

        // 构建查询
        $query = Product::select([
            'products.name as product_name',
            'products.brand',
            'products.weight as product_weight',
            'product_prices.period',
            'product_prices.price',
            'product_prices.alternative_standard_price',
            'product_prices.alternative_wildcard_price',
        ])
            ->join('product_prices', 'products.id', '=', 'product_prices.product_id')
            ->where('products.status', 1)
            ->where('product_prices.level_code', $user->level_code);

        // 应用筛选条件
        if (! empty($brands)) {
            $query->whereIn('products.brand', $brands);
        }

        // 过滤出至少有一个价格大于0的记录
        $query->where(function ($query) {
            $query->where('product_prices.price', '>', 0)
                ->orWhere('product_prices.alternative_standard_price', '>', 0)
                ->orWhere('product_prices.alternative_wildcard_price', '>', 0);
        });

        // 排序：先按产品，再按周期
        $query->orderBy('products.weight')
            ->orderBy('products.name')
            ->orderBy('product_prices.period');

        $data = $query->get();

        // 应用价格倍率
        $data = $data->map(function ($item) use ($priceRate) {
            $item->setAttribute('price_rate', $priceRate);

            return $item;
        });

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

        // 设置表头
        $headers = ['产品名称', '周期', '价格', '附加标准域名价格', '附加通配符域名价格'];
        $sheet->fromArray($headers, null, 'A1');
        $headerRange = 'A1:E1';
        $endColumn = 'E';

        // 设置表头样式
        $this->setHeaderStyle($sheet, $headerRange);

        // 写入数据和处理合并
        $row = 2;
        $mergeRanges = [];
        $lastProductName = null;
        $productStartRow = 2;

        foreach ($data as $item) {
            // 检查产品是否变化
            if ($lastProductName !== null && $item->product_name !== $lastProductName) {
                // 只有多行才合并
                if ($productStartRow < $row - 1) {
                    $mergeRanges[] = "A$productStartRow:A".($row - 1);
                }
                $productStartRow = $row;
            }

            $lastProductName = $item->product_name;

            // 写入数据
            $periodText = $this->formatPeriod($item->period);
            $priceRate = $item->price_rate ?? 1;

            $values = [
                $item->product_name,
                $periodText,
                $this->formatPrice($item->price * $priceRate),
                $this->formatPrice($item->alternative_standard_price * $priceRate),
                $this->formatPrice($item->alternative_wildcard_price * $priceRate),
            ];

            $sheet->fromArray($values, null, "A$row");
            $row++;
        }

        // 处理最后的合并 - 只有多行才合并
        if ($lastProductName !== null && $productStartRow < $row - 1) {
            $mergeRanges[] = "A$productStartRow:A".($row - 1);
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
