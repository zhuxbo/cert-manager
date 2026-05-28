<?php

namespace App\Http\Controllers\Acme;

use App\Http\Controllers\Controller;
use App\Models\Acme;
use App\Models\ApiToken;
use App\Models\Product;
use App\Services\Acme\Action;
use App\Services\Order\Utils\OrderUtil;
use App\Traits\AcmeReferIdCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiController extends Controller
{
    use AcmeReferIdCheck;

    protected int $user_id;

    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;

        /** @var ApiToken $apiToken */
        $apiToken = Auth::guard('api')->user();
        $this->user_id = $apiToken->user_id;
    }

    /**
     * 创建 ACME 订单（一步到位：创建 + 支付 + 提交）
     *
     * 入参与上游 /api/acme/new 字段对齐：product_code / contact_email / plus / refer_id
     * + period（manager 提前增加，预留 Certum 多年期产品；未传默认 12）
     * 域名额度由 product.standard_max / wildcard_max 自动推断
     */
    public function new(): void
    {
        $this->request->validate([
            'product_code' => 'required|string|max:50',
            'period' => 'sometimes|integer',
            'plus' => 'nullable|integer|in:0,1',
            'contact_email' => 'required|email|max:254',
            'refer_id' => 'sometimes|string|max:64',
        ]);

        $product = Product::where('code', $this->request->input('product_code'))
            ->where('product_type', Product::TYPE_ACME)
            ->where('status', 1)
            ->first();

        if (! $product) {
            $this->error('Product not found');
        }

        $this->checkAcmeReferId((string) $this->request->input('refer_id', ''), $this->user_id);

        // period 不传则不注入键，让 Action::createOrder 走 product.periods[0] 兜底
        // 避免"控制器硬编码默认 12"+"产品只支持其他周期（如 [24]）"导致必报"无效的购买时长"
        $params = [
            'user_id' => $this->user_id,
            'product_id' => $product->id,
            'plus' => (int) $this->request->input('plus', 1),
            'contact_email' => $this->request->input('contact_email'),
            'refer_id' => $this->request->input('refer_id') ?: null,
            'channel' => 'api',
        ];
        if ($this->request->filled('period')) {
            $params['period'] = (int) $this->request->input('period');
        }

        app(Action::class)->newAndCommit($params);
    }

    /**
     * 获取订单详情（含 EAB + directory_url）
     */
    public function get(): void
    {
        $this->request->validate(['order_id' => 'required|integer|min:1']);

        $id = (int) $this->request->input('order_id');

        // 先同步上游最新状态（同时刷新 directory_url 缓存）
        app(Action::class)->sync($id, true);

        $acme = Acme::with('product')->find($id);

        if (! $acme) {
            $this->error('Order not found');
        }

        // 隐藏内部桥接/计费/审计字段，保留 refer_id（客户端关联键，API contract 一部分）
        $acme->makeVisible('eab_hmac')
            ->makeHidden(['user_id', 'plus', 'api_id', 'admin_remark', 'channel']);

        $data = $acme->toArray();
        $data['directory_url'] = app(Action::class)->syncDirectoryUrl($acme);

        $this->success($data);
    }

    /**
     * 取消订单 — 立即取消，不走延时任务
     */
    public function cancel(): void
    {
        $this->request->validate(['order_id' => 'required|integer|min:1']);

        app(Action::class)->cancelNow((int) $this->request->input('order_id'));
    }

    /**
     * 获取 ACME 产品列表
     */
    public function getProducts(): void
    {
        $brand = $this->request->input('brand', '');
        $code = $this->request->input('code', '');

        $where = [];
        // brand 统一小写匹配（saving 钩子小写化；兼容 case-sensitive driver）
        $brand && $where[] = ['brand', '=', strtolower((string) $brand)];
        $code && $where[] = ['code', 'like', '%'.$code.'%'];
        $where[] = ['status', '=', 1];
        $where[] = ['product_type', '=', Product::TYPE_ACME];

        $res = Product::where($where)->orderBy('weight', 'asc')->get();
        $res->makeHidden(['id', 'api_id', 'cost', 'status', 'created_at', 'updated_at']);

        $data = [];
        foreach ($res as $item) {
            $cost = [];
            $skipProduct = false;

            /** @var int $period */
            foreach ($item->periods as $period) {
                $minPrice = OrderUtil::getMinPrice($this->user_id, $item->id, (int) $period);

                if (empty($minPrice)) {
                    $skipProduct = true;
                    break;
                }

                $period = (string) $period;
                $cost['price'][$period] = $minPrice['price'];

                if (in_array('standard', $item->alternative_name_types)) {
                    $cost['alternative_standard_price'][$period] = $minPrice['alternative_standard_price'];
                }

                if (in_array('wildcard', $item->alternative_name_types)) {
                    $cost['alternative_wildcard_price'][$period] = $minPrice['alternative_wildcard_price'];
                }
            }

            if ($skipProduct) {
                continue;
            }

            $item = $item->toArray();
            $item['periods'] = array_map('intval', $item['periods']);
            $item['cost'] = $cost;
            $data[] = $item;
        }

        $this->success($data);
    }
}
