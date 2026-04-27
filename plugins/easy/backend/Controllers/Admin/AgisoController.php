<?php

namespace Plugins\Easy\Controllers\Admin;

use App\Http\Controllers\Admin\BaseController;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Utils\SnowFlake;
use Plugins\Easy\Models\Agiso;
use Plugins\Easy\Requests\AgisoGetIdsRequest;
use Plugins\Easy\Requests\AgisoIndexRequest;
use Plugins\Easy\Requests\AgisoStoreRequest;

class AgisoController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function products(): void
    {
        $products = Product::where('validation_type', 'dv')
            ->where('total_max', 1)
            ->where('status', 1)
            ->where(function ($query) {
                $query->whereNull('product_type')
                    ->orWhere('product_type', Product::TYPE_SSL);
            })
            ->select(['id', 'name', 'code', 'periods', 'common_name_types'])
            ->get();

        $this->success($products->toArray());
    }

    public function store(AgisoStoreRequest $request): void
    {
        $validated = $request->validated();
        $productCode = $validated['product_code'];
        $period = (int) $validated['period'];
        $amount = (float) ($validated['amount'] ?? 0);
        $payMethod = $validated['pay_method'] ?? 'other';
        $count = (int) ($validated['count'] ?? 1);

        $product = Product::where('code', $productCode)
            ->where('validation_type', 'dv')
            ->where('total_max', 1)
            ->where('status', 1)
            ->where(function ($query) {
                $query->whereNull('product_type')
                    ->orWhere('product_type', Product::TYPE_SSL);
            })
            ->first();

        if (! $product) {
            $this->error('产品不存在或不符合条件');
        }

        if (! in_array($period, $product->periods)) {
            $this->error('周期不可用');
        }

        // 查询产品 platinum 价格作为系统价值（与回调逻辑一致）
        $productPrice = ProductPrice::where('product_id', $product->id)
            ->where('level_code', 'platinum')
            ->where('period', $period)
            ->first();

        $price = $productPrice ? (string) $productPrice->price : '0.00';

        $now = now();
        $rows = [];
        $tids = [];
        for ($i = 0; $i < $count; $i++) {
            $tid = 'E'.SnowFlake::generateParticle();
            $tids[] = $tid;
            $rows[] = [
                'pay_method' => $payMethod,
                'tid' => $tid,
                'product_code' => $product->code,
                'period' => $period,
                'price' => $price,
                'amount' => (string) $amount,
                'count' => 1,
                'recharged' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        Agiso::insert($rows);

        $siteUrl = rtrim(get_system_setting('site', 'url') ?? '', '/');

        $items = array_map(fn ($tid) => [
            'tid' => $tid,
            'easy_url' => "$siteUrl/easy/$tid",
            'recharge_url' => "$siteUrl/tid/$tid",
        ], $tids);

        $this->success(['items' => $items]);
    }

    public function index(AgisoIndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Agiso::query();

        if (! empty($validated['quickSearch'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('tid', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('pay_method', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('product_code', 'like', "%{$validated['quickSearch']}%")
                    ->orWhereHas('user', function ($userQuery) use ($validated) {
                        $userQuery->where('username', 'like', "%{$validated['quickSearch']}%");
                    });
            });
        }
        if (! empty($validated['pay_method'])) {
            $query->where('pay_method', $validated['pay_method']);
        }
        if (! empty($validated['order_id'])) {
            $query->where('order_id', 'like', "%{$validated['order_id']}%");
        }
        if (! empty($validated['product_code'])) {
            $query->where('product_code', 'like', "%{$validated['product_code']}%");
        }
        if (! empty($validated['tid'])) {
            $query->where('tid', 'like', "%{$validated['tid']}%");
        }
        if (! empty($validated['username'])) {
            $query->whereHas('user', function ($userQuery) use ($validated) {
                $userQuery->where('username', $validated['username']);
            });
        }
        if (isset($validated['period'])) {
            $query->where('period', $validated['period']);
        }
        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }
        if (isset($validated['recharged'])) {
            $query->where('recharged', $validated['recharged']);
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }

        $total = $query->count();
        $items = $query->with([
            'user' => function ($query) {
                $query->select(['id', 'username']);
            },
        ])
            ->select([
                'id', 'pay_method', 'tid', 'type', 'price', 'count', 'product_code', 'period',
                'amount', 'user_id', 'order_id', 'recharged', 'created_at',
            ])
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $items->each(function ($item) {
            if (! $item->user) {
                $item->setRelation('user', null);
            }
            if (! $item->order) {
                $item->setRelation('order', null);
            }
            if (! $item->product) {
                $item->setRelation('product', null);
            }
        });

        $this->success([
            'items' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
        ]);
    }

    public function show(int $id): void
    {
        $agiso = Agiso::find($id);

        if (! $agiso) {
            $this->error('阿奇索记录不存在');
        }

        $relations = [];

        if ($agiso->user_id) {
            $relations['user'] = function ($query) {
                $query->select(['id', 'username', 'email']);
            };
        }

        if (! empty($relations)) {
            $agiso->load($relations);
        }

        $this->success($agiso->toArray());
    }

    public function destroy(int $id): void
    {
        $agiso = Agiso::find($id);
        if (! $agiso) {
            $this->error('阿奇索记录不存在');
        }

        if ($agiso->recharged === 1) {
            $this->error('已充值的记录不能删除');
        }

        $agiso->delete();
        $this->success();
    }

    public function batchDestroy(AgisoGetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $agisos = Agiso::whereIn('id', $ids)->get();
        if ($agisos->isEmpty()) {
            $this->error('阿奇索记录不存在');
        }

        if ($agisos->where('recharged', 1)->count() > 0) {
            $this->error('存在已充值的记录，不能删除');
        }

        Agiso::destroy($ids);
        $this->success();
    }
}
