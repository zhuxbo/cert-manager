<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\ProductPrice\GetIdsRequest;
use App\Http\Requests\ProductPrice\GetRequest;
use App\Http\Requests\ProductPrice\IndexRequest;
use App\Http\Requests\ProductPrice\InitializationRequest;
use App\Http\Requests\ProductPrice\SetRequest;
use App\Http\Requests\ProductPrice\StoreRequest;
use App\Http\Requests\ProductPrice\UpdateRequest;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\UserLevel;
use App\Services\ProductPrice\ProductPriceInitializationService;
use App\Services\ProductPrice\ProductPriceMutationLock;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ProductPriceController extends BaseController
{
    public function __construct(private readonly ProductPriceMutationLock $mutationLock)
    {
        parent::__construct();
    }

    public function initialization(
        InitializationRequest $request,
        ProductPriceInitializationService $service,
    ): void {
        $validated = $request->validated();
        $adminId = (int) $this->guard->id();
        $data = $validated['preview']
            ? $service->preview($validated, $adminId)
            : $service->initialize($validated, $adminId, $validated['preview_token']);

        $this->success($data);
    }

    /**
     * 获取产品价格列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = ProductPrice::query();

        // 添加搜索条件
        if (! empty($validated['product_id'])) {
            $query->where('product_id', $validated['product_id']);
        }
        if (! empty($validated['level_code'])) {
            $query->where('level_code', $validated['level_code']);
        }
        if (! empty($validated['period'])) {
            $query->where('period', $validated['period']);
        }

        $total = $query->count();
        $items = $query->with([
            'product' => function ($query) {
                $query->select(['id', 'name']);
            },
            'level' => function ($query) {
                $query->select(['code', 'name']);
            },
        ])
            ->select([
                'id', 'product_id', 'level_code', 'period', 'price', 'alternative_standard_price', 'alternative_wildcard_price', 'created_at',
            ])
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    /**
     * 添加产品价格
     */
    public function store(StoreRequest $request): void
    {
        $validated = $request->validated();
        $productPrice = $this->mutationLock->runWithLock(function () use ($validated) {
            $this->ensurePriceReferencesExist($validated['product_id'], $validated['level_code']);
            $this->ensureUniquePrice($validated['product_id'], $validated['level_code'], $validated['period']);

            return ProductPrice::create($validated);
        });

        if (! $productPrice->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取产品价格资料
     */
    public function show($id): void
    {
        $productPrice = ProductPrice::find($id);
        if (! $productPrice) {
            $this->error('产品价格不存在');
        }

        $this->success($productPrice->toArray());
    }

    /**
     * 批量获取产品价格资料
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $productPrices = ProductPrice::whereIn('id', $ids)->get();
        if ($productPrices->isEmpty()) {
            $this->error('产品价格不存在');
        }

        $this->success($productPrices->toArray());
    }

    /**
     * 更新产品价格资料
     */
    public function update(UpdateRequest $request, $id): void
    {
        $validated = $request->validated();
        $this->mutationLock->runWithLock(function () use ($validated, $id) {
            $productPrice = ProductPrice::find($id);
            if (! $productPrice) {
                $this->error('产品价格不存在');
            }

            $this->ensurePriceReferencesExist($validated['product_id'], $validated['level_code']);
            $this->ensureUniquePrice(
                $validated['product_id'],
                $validated['level_code'],
                $validated['period'],
                $productPrice->id
            );

            $productPrice->fill($validated);
            $productPrice->save();
        });

        $this->success();
    }

    /**
     * 删除产品价格
     */
    public function destroy($id): void
    {
        $this->mutationLock->runWithLock(function () use ($id) {
            $productPrice = ProductPrice::find($id);
            if (! $productPrice) {
                $this->error('产品价格不存在');
            }

            $productPrice->delete();
        });
        $this->success();
    }

    /**
     * 批量删除产品价格
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $this->mutationLock->runWithLock(function () use ($ids) {
            $uniqueIds = array_values(array_unique($ids));
            $productPrices = ProductPrice::whereIn('id', $uniqueIds)->get();
            if ($productPrices->count() !== count($uniqueIds)) {
                $this->error('产品价格不存在');
            }

            ProductPrice::destroy($uniqueIds);
        });
        $this->success();
    }

    /**
     * 获取会员级别的产品价格
     *
     * @throws Throwable
     */
    public function get(GetRequest $request): void
    {
        $validated = $request->validated();

        $product_price = ProductPrice::getProductPrice($validated['product_id'], $validated['level_codes']);

        $this->success($product_price);
    }

    /**
     * 设置产品价格
     *
     * @throws Throwable
     */
    public function set(SetRequest $request): void
    {
        $validated = $request->validated();

        $this->mutationLock->runWithLock(function () use ($validated) {
            if (! Product::whereKey($validated['product_id'])->exists()) {
                $this->error('产品不存在');
            }

            $levelCodes = array_keys($validated['product_price']);
            if (UserLevel::whereIn('code', $levelCodes)->count() !== count(array_unique($levelCodes))) {
                $this->error('用户级别不存在');
            }

            ProductPrice::setProductPrice($validated['product_id'], $validated['product_price']);
        });

        $this->success();
    }

    private function ensurePriceReferencesExist(int $productId, string $levelCode): void
    {
        if (! Product::whereKey($productId)->exists()) {
            $this->error('产品不存在');
        }
        if (! UserLevel::where('code', $levelCode)->exists()) {
            $this->error('用户级别不存在');
        }
    }

    private function ensureUniquePrice(int $productId, string $levelCode, int $period, ?int $exceptId = null): void
    {
        $query = ProductPrice::where('product_id', $productId)
            ->where('level_code', $levelCode)
            ->where('period', $period);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            $this->error('该产品、用户级别和周期的组合已经存在。');
        }
    }

    /**
     * 导出按会员级别的产品价格表，Excel格式
     */
    public function export(): void
    {
        $productPriceModel = new ProductPrice;
        $priceData = $productPriceModel
            ->with(['product', 'level'])
            ->whereHas('product', fn ($q) => $q->where('status', 1))
            ->orderBy('level_code')
            ->orderBy('product_id')
            ->get();

        // 创建新的 Spreadsheet 对象
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // 设置表头
        $sheet->setCellValue('A1', '会员级别');
        $sheet->setCellValue('B1', '产品名称');
        $sheet->setCellValue('C1', '周期');
        $sheet->setCellValue('D1', '价格');
        $sheet->setCellValue('E1', '附加标准域名价格');
        $sheet->setCellValue('F1', '附加通配符价格');

        // 初始化变量
        $row = 2;
        $startRowLevel = $row;
        $startRowProduct = $row;
        $prevLevel = null;
        $prevProduct = null;

        foreach ($priceData as $item) {
            $currentLevel = $item['level']['name'];
            $currentProduct = $item['product']['name'];

            // 检查会员级别是否变化
            if ($prevLevel !== null && $currentLevel !== $prevLevel) {
                // 合并之前的会员级别单元格
                $endRowLevel = $row - 1;
                if ($startRowLevel < $endRowLevel) {
                    $sheet->mergeCells('A'.$startRowLevel.':A'.$endRowLevel);
                    // 设置单元格居中
                    $sheet->getStyle('A'.$startRowLevel.':A'.$endRowLevel)
                        ->getAlignment()
                        ->setVertical(Alignment::VERTICAL_CENTER)
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
                $startRowLevel = $row; // 更新起始行

                // 合并之前的产品名称单元格
                $endRowProduct = $row - 1;
                if ($startRowProduct < $endRowProduct) {
                    $sheet->mergeCells('B'.$startRowProduct.':B'.$endRowProduct);
                    $sheet->getStyle('B'.$startRowProduct.':B'.$endRowProduct)
                        ->getAlignment()
                        ->setVertical(Alignment::VERTICAL_CENTER)
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
                $startRowProduct = $row; // 更新起始行
                $prevProduct = null; // 重置产品名称
            }

            // 检查产品名称是否变化
            if ($prevProduct !== null && $currentProduct !== $prevProduct) {
                // 合并之前的产品名称单元格
                $endRowProduct = $row - 1;
                if ($startRowProduct < $endRowProduct) {
                    $sheet->mergeCells('B'.$startRowProduct.':B'.$endRowProduct);
                    $sheet->getStyle('B'.$startRowProduct.':B'.$endRowProduct)
                        ->getAlignment()
                        ->setVertical(Alignment::VERTICAL_CENTER)
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
                $startRowProduct = $row; // 更新起始行
            }

            // 写入数据
            $sheet->setCellValue('A'.$row, $currentLevel);
            $sheet->setCellValue('B'.$row, $currentProduct);
            $sheet->setCellValue('C'.$row, $item['period']);
            $sheet->setCellValue('D'.$row, empty($item['price']) ? '' : $item['price']);
            $sheet->setCellValue('E'.$row, empty($item['alternative_standard_price']) ? '' : $item['alternative_standard_price']);
            $sheet->setCellValue('F'.$row, empty($item['alternative_wildcard_price']) ? '' : $item['alternative_wildcard_price']);

            $prevLevel = $currentLevel;
            $prevProduct = $currentProduct;
            $row++;
        }

        // 处理最后一组会员级别和产品名称的合并
        $endRowLevel = $row - 1;
        if ($startRowLevel < $endRowLevel) {
            $sheet->mergeCells('A'.$startRowLevel.':A'.$endRowLevel);
            $sheet->getStyle('A'.$startRowLevel.':A'.$endRowLevel)
                ->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $endRowProduct = $row - 1;
        if ($startRowProduct < $endRowProduct) {
            $sheet->mergeCells('B'.$startRowProduct.':B'.$endRowProduct);
            $sheet->getStyle('B'.$startRowProduct.':B'.$endRowProduct)
                ->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // 将 Excel 文件内容写入内存
        $writer = new Xlsx($spreadsheet);
        ob_start(); // 开启输出缓冲
        $writer->save('php://output');
        $excelOutput = ob_get_clean(); // 获取输出缓冲区内容

        // 设置 HTTP 响应头
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename='.urlencode('产品价格表.xlsx'));
        header('Access-Control-Expose-Headers: Content-Disposition');

        // 输出文件内容
        echo $excelOutput;
    }
}
