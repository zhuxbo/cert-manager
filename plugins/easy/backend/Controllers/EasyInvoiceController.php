<?php

declare(strict_types=1);

namespace Plugins\Easy\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Plugins\Easy\Models\Agiso;
use Plugins\Invoice\Models\Invoice;
use Plugins\Invoice\Services\InvoiceQuotaService;

class EasyInvoiceController extends Controller
{
    public function ping(): void
    {
        $this->success();
    }

    public function quota(Request $request): void
    {
        $order = $this->getOrder($request->all());
        $this->success(InvoiceQuotaService::getQuota($order->user_id));
    }

    public function apply(Request $request): void
    {
        $params = $request->all();
        $order = $this->getOrder($params);

        $validator = Validator::make($params, [
            'amount' => 'required|numeric|min:0.01',
            'organization' => 'required|string|max:200',
            'taxation' => 'required|string|max:100',
            'remark' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            $this->error('参数错误', $validator->errors()->toArray());
        }

        DB::transaction(function () use ($order, $params) {
            $user = User::where('id', $order->user_id)->lockForUpdate()->first();
            if (! $user) {
                $this->error('用户不存在');
            }

            $quota = InvoiceQuotaService::getQuota($user->id);
            if (bccomp((string) $quota['quota'], (string) $params['amount'], 2) < 0) {
                $this->error("超过可开金额,当前可开 {$quota['quota']} 元");
            }

            Invoice::create([
                'user_id' => $user->id,
                'amount' => $params['amount'],
                'organization' => $params['organization'],
                'taxation' => $params['taxation'],
                'remark' => $params['remark'] ?? null,
                'email' => $user->email,
                'status' => 0,
            ]);
        });

        $this->success();
    }

    /**
     * 获取订单(简易开票认证：tid 必须是该 email 用户当年任一 Agiso 订单号)
     */
    protected function getOrder(array $params): Agiso
    {
        $year = (int) date('Y');
        $yearStart = "$year-01-01 00:00:00";
        $yearEnd = "$year-12-31 23:59:59";

        $order = Agiso::with('user')
            ->where('tid', $params['tid'] ?? '')
            ->whereBetween('created_at', [$yearStart, $yearEnd])
            ->whereHas('user', function ($q) use ($params) {
                $q->where('email', $params['email'] ?? '');
            })
            ->first();

        if (! $order) {
            $this->error('订单与邮箱不匹配或非本年度订单');
        }

        if (! $order->user) {
            $this->error('订单错误');
        }

        return $order;
    }
}
