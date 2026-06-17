<?php

namespace App\Http\Controllers\User;

use App\Bootstrap\ApiExceptions;
use App\Http\Traits\PaymentConfigTrait;
use App\Models\Fund;
use App\Models\User;
use App\Services\Payment\PaymentGateway;
use Exception;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class TopUpController extends BaseController
{
    use PaymentConfigTrait;

    /**
     * @throws Throwable
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 支付宝支付
     *
     * @throws Throwable
     */
    public function alipay(): void
    {
        $this->getPayConfig('alipay');

        $amount = request()->input('amount', 1);

        if (! is_numeric($amount) || bccomp((string) $amount, '0.01', 2) <= 0) {
            $this->error('请输入正确的金额');
        }

        // user 行锁串行化同一用户的并发/双击 addfunds，避免重复待支付记录
        $userId = $this->guard->id();
        $fund = DB::transaction(function () use ($userId, $amount) {
            User::where('id', $userId)->lockForUpdate()->firstOrFail();

            $existing = Fund::where('created_at', '>=', now()->subMinutes(10))
                ->where([
                    'user_id' => $userId,
                    'amount' => $amount,
                    'type' => 'addfunds',
                    'pay_method' => 'alipay',
                    'status' => 0,
                ])
                ->first();

            if ($existing) {
                return $existing;
            }

            return Fund::create([
                'user_id' => $userId,
                'amount' => $amount,
                'type' => 'addfunds',
                'pay_method' => 'alipay',
                'status' => 0,
                'ip' => request()->ip(),
            ]);
        });

        $reused = $fund->wasRecentlyCreated === false;

        if ($reused) {
            try {
                $order = $this->pay()->alipay()->query(['out_trade_no' => $fund->id]);
            } catch (Throwable $e) {
                app(ApiExceptions::class)->logException($e);
                $this->clearAlipayCache();
                $this->error('发起支付失败，请联系管理员');
            }

            if ($order['trade_status'] === 'TRADE_SUCCESS') {
                $this->addfundsSuccessful((string) $fund->id, $order['trade_no']);
                $this->error('重复相同金额充值，请刷新页面后重试');
            }
        }

        $order = [
            'out_trade_no' => $fund->id,
            'total_amount' => $amount,
            'subject' => '用户名：'.$this->guard->user()->username,
        ];

        try {
            $result = $this->pay()->alipay()->scan($order);
        } catch (Throwable $e) {
            app(ApiExceptions::class)->logException($e);
            $this->clearAlipayCache();
            $this->error('发起支付失败，请联系管理员');
        }

        $resultArray = $result->toArray();
        $resultArray['fundId'] = $fund->id;

        $this->success($resultArray);
    }

    /**
     * 支付宝支付回调
     *
     * @throws Throwable
     */
    public function alipayNotify(): ResponseInterface
    {
        $this->getPayConfig('alipay');
        $result = $this->pay()->alipay()->callback();

        // 不 catch：本地入账失败时让异常冒泡使响应非 200，支付宝按策略重试，
        // 避免"已扣款 + 未入账"的资金错乱
        if ($result['trade_status'] === 'TRADE_SUCCESS') {
            DB::transaction(function () use ($result) {
                $fund = Fund::transitionToSuccessful(
                    (string) $result['out_trade_no'],
                    (string) $result['total_amount'],
                    'addfunds',
                    'alipay',
                    (string) $result['trade_no'],
                );

                $this->ensureCallbackAccounted(
                    $fund,
                    (string) $result['out_trade_no'],
                    (string) $result['total_amount'],
                    'alipay',
                    (string) $result['trade_no'],
                );
            });
        }

        return $this->pay()->alipay()->success();
    }

    /**
     * 微信支付
     *
     * @throws Throwable
     */
    public function wechat(): void
    {
        $this->getPayConfig('wechat');

        $amount = request()->input('amount', 1);

        if (! is_numeric($amount) || bccomp((string) $amount, '0.01', 2) <= 0) {
            $this->error('请输入正确的金额');
        }

        // user 行锁串行化同一用户的并发/双击 addfunds，避免重复待支付记录
        $userId = $this->guard->id();
        $fund = DB::transaction(function () use ($userId, $amount) {
            User::where('id', $userId)->lockForUpdate()->firstOrFail();

            $existing = Fund::where('created_at', '>=', now()->subMinutes(10))
                ->where([
                    'user_id' => $userId,
                    'amount' => $amount,
                    'type' => 'addfunds',
                    'pay_method' => 'wechat',
                    'status' => 0,
                ])
                ->first();

            if ($existing) {
                return $existing;
            }

            return Fund::create([
                'user_id' => $userId,
                'amount' => $amount,
                'type' => 'addfunds',
                'pay_method' => 'wechat',
                'status' => 0,
                'ip' => request()->ip(),
            ]);
        });

        if ($fund->wasRecentlyCreated === false) {
            try {
                $order = $this->pay()->wechat()->query(array_merge(['out_trade_no' => (string) $fund->id], $this->wechatSerial()));
            } catch (Throwable $e) {
                app(ApiExceptions::class)->logException($e);
                $this->clearWechatCache();
                $this->error('发起支付失败，请联系管理员');
            }

            if ($order['trade_state'] === 'SUCCESS') {
                $this->addfundsSuccessful((string) $fund->id, $order['transaction_id']);
                $this->error('重复相同金额充值，请刷新页面后重试');
            }
        }

        $order = [
            'out_trade_no' => (string) $fund->id,
            'description' => '用户名：'.$this->guard->user()->username,
            'amount' => [
                'total' => (int) bcmul($amount, '100', 0),
            ],
        ];

        try {
            $result = $this->pay()->wechat()->scan(array_merge($order, $this->wechatSerial()));
        } catch (Throwable $e) {
            app(ApiExceptions::class)->logException($e);
            $this->clearWechatCache();
            $this->error('发起支付失败，请联系管理员');
        }

        $resultArray = $result->toArray();
        $resultArray['fundId'] = $fund->id;

        $this->success($resultArray);
    }

    /**
     * 微信支付回调
     *
     * @throws Throwable
     */
    public function wechatNotify(): ResponseInterface
    {
        $this->getPayConfig('wechat');
        $result = $this->pay()->wechat()->callback();

        $paymentData = (object) $result['resource']['ciphertext'];

        // 记录调试信息
        if (empty((array) $paymentData)) {
            app(ApiExceptions::class)->logException(new Exception('微信支付回调数据结构异常: '.json_encode($result, JSON_UNESCAPED_UNICODE)));

            return $this->pay()->wechat()->success();
        }

        if (isset($paymentData->trade_state) && $paymentData->trade_state === 'SUCCESS') {
            // 处理金额数据，可能是对象或数组
            $amountTotal = null;
            if (isset($paymentData->amount)) {
                if (is_object($paymentData->amount) && isset($paymentData->amount->total)) {
                    $amountTotal = $paymentData->amount->total;
                } elseif (is_array($paymentData->amount) && isset($paymentData->amount['total'])) {
                    $amountTotal = $paymentData->amount['total'];
                }
            }

            // 必填字段缺失：放弃处理但仍 ACK 给微信，避免回调风暴
            if (! isset($paymentData->out_trade_no) || ! $amountTotal || ! isset($paymentData->transaction_id)) {
                return $this->pay()->wechat()->success();
            }

            // 不 catch：入账失败时让异常冒泡使响应非 200，微信按策略重试，避免脏账
            DB::transaction(function () use ($paymentData, $amountTotal) {
                $expectedAmount = bcdiv((string) $amountTotal, '100', 2);

                $fund = Fund::transitionToSuccessful(
                    (string) $paymentData->out_trade_no,
                    $expectedAmount,
                    'addfunds',
                    'wechat',
                    (string) $paymentData->transaction_id,
                );

                $this->ensureCallbackAccounted(
                    $fund,
                    (string) $paymentData->out_trade_no,
                    $expectedAmount,
                    'wechat',
                    (string) $paymentData->transaction_id,
                );
            });
        }

        return $this->pay()->wechat()->success();
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
            'user_id' => $this->guard->id(),
            'type' => 'addfunds',
            'status' => 0, // processing
        ])->first();

        if (! $fund) {
            $this->success(['message' => 'successful']);
        }

        if ($fund->pay_method === 'alipay') {
            $this->getPayConfig('alipay');
            $order = $this->pay()->alipay()->query(['out_trade_no' => $fund->id]);
            if ($order['trade_status'] === 'TRADE_SUCCESS' || $order['trade_status'] === 'TRADE_FINISHED') {
                $pay_sn = $order['trade_no'];
            }
        }

        if ($fund->pay_method === 'wechat') {
            $this->getPayConfig('wechat');
            $order = $this->pay()->wechat()->query(array_merge(['out_trade_no' => $fund->id], $this->wechatSerial()));
            if ($order['trade_state'] === 'SUCCESS') {
                $pay_sn = $order['transaction_id'];
            }
        }

        // 如果支付序列号存在则充值成功
        if (isset($pay_sn)) {
            $this->addfundsSuccessful($id, $pay_sn);
            $this->success(['message' => 'successful']);
        }

        $this->success();
    }

    /**
     * 获取银行账户信息
     */
    public function getBankAccount(): void
    {
        $bankAccount = get_system_setting('bankAccount');

        if (! $bankAccount) {
            $this->error('没有找到银行账户信息');
        }

        $this->success($bankAccount);
    }

    /**
     * 充值成功（用户 check 路径，本地 fund 字段 best-effort 校验；
     * 真正的金额/支付方式校验在回调 alipayNotify/wechatNotify）
     *
     * @throws Throwable
     */
    protected function addfundsSuccessful(string $id, int|string $pay_sn): void
    {
        try {
            DB::transaction(function () use ($id, $pay_sn) {
                $fund = Fund::where([
                    'id' => $id,
                    'user_id' => $this->guard->id(),
                    'type' => 'addfunds',
                    'status' => 0, // processing
                ])->first();

                if (! $fund) {
                    // 已被并发 check/callback 处理过，不是错误
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
        }
    }

    /**
     * 支付平台回调必须落到账；只有同一支付单已被处理过时才允许 ACK。
     */
    private function ensureCallbackAccounted(
        ?Fund $fund,
        string $id,
        string $expectedAmount,
        string $expectedPayMethod,
        string $paySn
    ): void {
        if ($fund !== null) {
            return;
        }

        // 重复回调场景：首个回调已把 fund 入账，后续相同支付单应直接 ACK。
        $alreadyAccounted = Fund::where('id', $id)
            ->where('amount', $expectedAmount)
            ->whereIn('type', ['addfunds', 'refunds'])
            ->where('pay_method', $expectedPayMethod)
            ->where('pay_sn', $paySn)
            ->whereIn('status', [1, 2])
            ->exists();

        if ($alreadyAccounted) {
            return;
        }

        throw new Exception('支付回调未入账：本地资金记录不存在或金额/支付方式不匹配');
    }

    private function pay(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }
}
