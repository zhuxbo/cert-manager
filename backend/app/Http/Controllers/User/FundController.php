<?php

namespace App\Http\Controllers\User;

use App\Bootstrap\ApiExceptions;
use App\Http\Requests\Fund\IndexRequest;
use App\Http\Traits\PaymentConfigTrait;
use App\Models\Fund;
use App\Services\Payment\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 资金管理
 */
class FundController extends BaseController
{
    use PaymentConfigTrait;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取资金列表
     */
    public function index(IndexRequest $request): void
    {
        $validated = $request->validated();
        $currentPage = (int) ($validated['currentPage'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 10);

        $query = Fund::query();

        // 添加搜索条件
        if (! empty($validated['id'])) {
            $query->where('id', $validated['id']);
        }
        if (! empty($validated['amount'])) {
            if (isset($validated['amount'][0]) && isset($validated['amount'][1])) {
                $query->whereBetween('amount', $validated['amount']);
            } elseif (isset($validated['amount'][0])) {
                $query->where('amount', '>=', $validated['amount'][0]);
            } elseif (isset($validated['amount'][1])) {
                $query->where('amount', '<=', $validated['amount'][1]);
            }
        }
        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }
        if (! empty($validated['pay_method'])) {
            $query->where('pay_method', $validated['pay_method']);
        }
        if (! empty($validated['pay_sn'])) {
            $query->where('pay_sn', $validated['pay_sn']);
        }
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['created_at'])) {
            $query->whereBetween('created_at', $validated['created_at']);
        }

        $query->where('status', '!=', 0);
        $total = $query->count();
        $items = $query->select([
            'id', 'amount', 'type', 'pay_method', 'pay_sn', 'status', 'remark', 'created_at',
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
     * 检查充值状态
     *
     * @throws Throwable
     */
    public function check(string $id): void
    {
        $fund = Fund::where([
            'id' => $id,
            'type' => 'addfunds',
            'status' => 0, // processing
        ])->first();

        if (! $fund) {
            $this->error('invalid fund id');
        }

        if ($fund->pay_method === 'alipay') {
            $this->getPayConfig('alipay');
            $order = app(PaymentGateway::class)->alipay()->query(['out_trade_no' => $fund->id]);
            if ($order['trade_status'] === 'TRADE_SUCCESS' || $order['trade_status'] === 'TRADE_FINISHED') {
                $pay_sn = $order['trade_no'];
            }
        }

        if ($fund->pay_method === 'wechat') {
            $this->getPayConfig('wechat');
            $order = app(PaymentGateway::class)->wechat()->query(array_merge(['out_trade_no' => $fund->id], $this->wechatSerial()));
            if ($order['trade_state'] === 'SUCCESS') {
                $pay_sn = $order['transaction_id'];
            }
        }

        // 如果支付序列号存在则充值成功
        if (isset($pay_sn)) {
            $this->addfundsSuccessful($id, $pay_sn);
            $this->success();
        }

        $this->error('未查询到支付信息');
    }

    /**
     * 充值成功（用户主动 check 走本地 fund 字段 best-effort 校验，
     * 真正的金额/支付方式校验在 User\TopUpController 的回调路径）
     *
     * @throws Throwable
     */
    protected function addfundsSuccessful(string $id, int|string $pay_sn): void
    {
        try {
            DB::transaction(function () use ($id, $pay_sn) {
                $fund = Fund::where([
                    'id' => $id,
                    'type' => 'addfunds',
                    'status' => 0, // processing
                ])->first();

                if (! $fund) {
                    return;
                }

                Fund::transitionToSuccessful(
                    (string) $id,
                    (string) $fund->amount,
                    'addfunds',
                    (string) $fund->pay_method,
                    (string) $pay_sn,
                );
            });
        } catch (Throwable $e) {
            app(ApiExceptions::class)->logException($e);
            $this->error('充值失败：'.$e->getMessage());
        }
    }
}
