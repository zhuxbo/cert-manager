<?php

namespace App\Http\Controllers\Deploy;

use App\Http\Controllers\Controller;
use App\Models\Acme;
use App\Models\Product;
use App\Services\Acme\Action;
use App\Traits\AcmeReferIdCheck;
use Illuminate\Http\Request;

class AcmeController extends Controller
{
    use AcmeReferIdCheck;

    /**
     * 创建 ACME 订单（一步到位：创建 + 支付 + 提交）
     *
     * 入参与上游 /api/v2/acme/new 字段对齐：product_code / contact_email / plus / refer_id
     * + period（manager 提前增加，预留 Certum 多年期产品；未传则取产品默认周期 product.periods[0]）
     */
    public function new(Request $request): void
    {
        $request->validate([
            'product_code' => 'required|string|max:50',
            'period' => 'sometimes|integer',
            'plus' => 'nullable|integer|in:0,1',
            'contact_email' => 'required|email|max:254',
            'refer_id' => 'sometimes|string|max:64',
        ]);

        $userId = (int) $request->attributes->get('authenticated_user_id');

        if (! $userId) {
            $this->error('Unauthorized');
        }

        $product = Product::where('code', $request->input('product_code'))
            ->where('product_type', Product::TYPE_ACME)
            ->where('status', 1)
            ->first();

        if (! $product) {
            $this->error('Product not found');
        }

        $this->checkAcmeReferId((string) $request->input('refer_id', ''), $userId);

        // period 不传则不注入键，让 Action::createOrder 走 product.periods[0] 兜底
        $params = [
            'user_id' => $userId,
            'product_id' => $product->id,
            // 显式传 plus=null 时 input 第二参默认值不生效（key 已存在），用 ?? 兜底文档默认 1
            'plus' => (int) ($request->input('plus') ?? 1),
            'contact_email' => $request->input('contact_email'),
            'refer_id' => $request->filled('refer_id') ? $request->input('refer_id') : null,
            'channel' => 'deploy',
        ];
        if ($request->filled('period')) {
            $params['period'] = (int) $request->input('period');
        }

        app(Action::class)->newAndCommit($params);
    }

    /**
     * 查询订单详情（含 EAB）
     *
     * deploy_tokens.user_id 为 non-nullable，DB 约束保证非空，
     * DeployAuthenticate 始终注册 UserScope，Acme::find 自动过滤当前用户
     */
    public function get(int $id): void
    {
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
}
