<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Fund\GetIdsRequest;
use App\Http\Requests\Fund\IndexRequest;
use App\Http\Requests\Fund\StoreRequest;
use App\Http\Requests\Fund\UpdateRequest;
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
        if (! empty($validated['quickSearch'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('id', 'like', "%{$validated['quickSearch']}%")
                    ->orWhere('remark', 'like', "%{$validated['quickSearch']}%")
                    ->orWhereHas('user', function ($userQuery) use ($validated) {
                        $userQuery->where('username', 'like', "%{$validated['quickSearch']}%");
                    });
            });
        }
        if (! empty($validated['id'])) {
            $query->where('id', $validated['id']);
        }
        if (! empty($validated['username'])) {
            $query->whereHas('user', function ($userQuery) use ($validated) {
                $userQuery->where('username', $validated['username']);
            });
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

        $total = $query->count();
        $items = $query->with([
            'user' => function ($query) {
                $query->select(['id', 'username']);
            },
        ])
            ->select([
                'id', 'user_id', 'amount', 'type', 'pay_method', 'pay_sn', 'status', 'remark', 'created_at',
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
     * 添加资金记录（仅 type ∈ {addfunds, deduct}，refunds/reverse 走 UPDATE 同行接口）。
     */
    public function store(StoreRequest $request): void
    {
        $fund = DB::transaction(fn () => Fund::create($request->validated()));

        if (! $fund->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    /**
     * 获取资金记录
     */
    public function show(int $id): void
    {
        $fund = Fund::find($id);
        if (! $fund) {
            $this->error('资金记录不存在');
        }

        $this->success($fund->toArray());
    }

    /**
     * 批量获取资金记录
     */
    public function batchShow(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        $funds = Fund::whereIn('id', $ids)->get();
        if ($funds->isEmpty()) {
            $this->error('资金记录不存在');
        }

        $this->success($funds->toArray());
    }

    /**
     * 更新资金记录
     */
    public function update(UpdateRequest $request, int $id): void
    {
        DB::transaction(function () use ($request, $id) {
            $fund = Fund::where('id', $id)->lockForUpdate()->first();
            if (! $fund) {
                $this->error('资金记录不存在');
            }

            $fund->fill($request->validated());
            $fund->save();
        });

        $this->success();
    }

    /**
     * 删除资金记录（lockForUpdate + 锁内重读 status，防止并发回调入账后被误删）
     */
    public function destroy(int $id): void
    {
        DB::transaction(function () use ($id) {
            $fund = Fund::where('id', $id)->lockForUpdate()->first();
            if (! $fund) {
                $this->error('资金记录不存在');
            }

            if ($fund->status !== 0) {
                $this->error('只能删除处理中的记录');
            }
            if (strtotime($fund->created_at) > strtotime('-2 hours')) {
                $this->error('处理中订单 2 小时内不允许删除');
            }

            $fund->delete();
        });

        $this->success();
    }

    /**
     * 批量删除资金记录（同 destroy 校验语义，失败的 id 跳过）
     */
    public function batchDestroy(GetIdsRequest $request): void
    {
        $ids = $request->validated('ids');

        DB::transaction(function () use ($ids) {
            $funds = Fund::whereIn('id', $ids)->lockForUpdate()->get();
            if ($funds->isEmpty()) {
                $this->error('资金记录不存在');
            }

            foreach ($funds as $fund) {
                if ($fund->status !== 0) {
                    continue;
                }
                if (strtotime($fund->created_at) > strtotime('-2 hours')) {
                    continue;
                }
                $fund->delete();
            }
        });

        $this->success();
    }

    /**
     * 退款（lockForUpdate + 锁内 status 二次校验，防止并发双退）
     *
     * @throws Throwable
     */
    public function refunds(int $id): void
    {
        DB::transaction(function () use ($id) {
            $fund = Fund::where(['id' => $id, 'type' => 'addfunds'])->lockForUpdate()->first();
            $fund || $this->error('充值记录不存在');

            if ($fund->status === 0) {
                $this->error('资金处理中');
            }

            if ($fund->status === 2) {
                $this->error('资金已退');
            }

            $fund->type = 'refunds';
            $fund->status = 2;
            $fund->save();
        });

        $this->success();
    }

    /**
     * 退回（同 refunds 锁语义）
     *
     * @throws Throwable
     */
    public function reverse(int $id): void
    {
        DB::transaction(function () use ($id) {
            $fund = Fund::where(['id' => $id, 'type' => 'deduct'])->lockForUpdate()->first();
            $fund || $this->error('扣款记录不存在');

            if ($fund->status === 0) {
                $this->error('扣款处理中');
            }

            if ($fund->status === 2) {
                $this->error('扣款已退');
            }

            $fund->type = 'reverse';
            $fund->status = 2;
            $fund->save();
        });

        $this->success();
    }

    /**
     * 检查充值状态
     *
     * @throws Throwable
     */
    public function check(int $id): void
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
            $this->addfundsSuccessful((string) $id, $pay_sn);
            $this->success();
        }

        $this->error('未查询到支付信息');
    }

    /**
     * 充值成功（admin 主动 check 用本地 fund 字段做 best-effort 校验，
     * 真正的金额/支付方式校验在 User\TopUpController 的回调路径）。
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
        } catch (Throwable) {
            // 与原逻辑一致：admin check 入口异常时静默
        }
    }
}
