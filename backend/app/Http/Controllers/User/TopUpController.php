<?php

namespace App\Http\Controllers\User;

use App\Bootstrap\ApiExceptions;
use App\Http\Traits\PaymentConfigTrait;
use App\Models\Fund;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Yansongda\Pay\Pay;

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

        // 同一用户并发/重试双击可能同时走到 Fund::create 分支生成多条待支付记录。
        // 用 user 行级锁串行化：同一用户同时只能有一条 addfunds 请求进入查/建逻辑。
        $userId = $this->guard->id();
        $fund = DB::transaction(function () use ($userId, $amount) {
            User::where('id', $userId)->lockForUpdate()->firstOrFail();

            $existing = Fund::where('created_at', '>=', now()->subMinutes(10))
                ->where([
                    'user_id' => $userId,
                    'amount' => $amount,
                    'type' => 'addfunds',
                    'pay_method' => 'alipay',
                    'status' => 0, // processing
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
                'status' => 0, // processing
                'ip' => request()->ip(),
            ]);
        });

        // 历史已存在 fund：查一次上游确认状态；新建 fund：直接走创建扫码流程
        $reused = $fund->wasRecentlyCreated === false;

        if ($reused) {
            try {
                $order = Pay::alipay()->query(['out_trade_no' => $fund->id]);
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
            $result = Pay::alipay()->scan($order);
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
        $result = Pay::alipay()->callback();

        // 支付成功
        if ($result['trade_status'] === 'TRADE_SUCCESS') {
            // 使用事务防止重复
            DB::beginTransaction();
            try {
                $fund = Fund::where([
                    'id' => $result['out_trade_no'],
                    'amount' => $result['total_amount'],
                    'type' => 'addfunds',
                    'pay_method' => 'alipay',
                    'status' => 0, // processing
                ])->lockForUpdate()->first();

                if ($fund) {
                    $fund->status = 1; // successful
                    $fund->pay_sn = $result['trade_no'];
                    $fund->save();

                    DB::commit();

                    return Pay::alipay()->success();
                } else {
                    // 检查是否已经处理过（状态为成功）
                    $existingFund = Fund::where([
                        'id' => $result['out_trade_no'],
                        'amount' => $result['total_amount'],
                        'type' => 'addfunds',
                        'pay_method' => 'alipay',
                        'status' => 1, // successful
                    ])->first();

                    DB::rollback();

                    if ($existingFund) {
                        return Pay::alipay()->success();
                    }
                }
            } catch (Throwable $e) {
                DB::rollback();
                app(ApiExceptions::class)->logException($e);
            }
        }

        return Pay::alipay()->success();
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

        // 同一用户并发/重试双击可能同时走到 Fund::create 分支生成多条待支付记录。
        // 用 user 行级锁串行化：同一用户同时只能有一条 addfunds 请求进入查/建逻辑。
        $userId = $this->guard->id();
        $fund = DB::transaction(function () use ($userId, $amount) {
            User::where('id', $userId)->lockForUpdate()->firstOrFail();

            $existing = Fund::where('created_at', '>=', now()->subMinutes(10))
                ->where([
                    'user_id' => $userId,
                    'amount' => $amount,
                    'type' => 'addfunds',
                    'pay_method' => 'wechat',
                    'status' => 0, // processing
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
                'status' => 0, // processing
                'ip' => request()->ip(),
            ]);
        });

        if ($fund->wasRecentlyCreated === false) {
            try {
                $order = Pay::wechat()->query(['out_trade_no' => (string) $fund->id]);
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
            $result = Pay::wechat()->scan($order);
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
        $result = Pay::wechat()->callback();

        $paymentData = (object) $result['resource']['ciphertext'];

        // 记录调试信息
        if (empty((array) $paymentData)) {
            app(ApiExceptions::class)->logException(new Exception('微信支付回调数据结构异常: '.json_encode($result, JSON_UNESCAPED_UNICODE)));

            return Pay::wechat()->success();
        }

        // 支付成功
        if (isset($paymentData->trade_state) && $paymentData->trade_state === 'SUCCESS') {
            // 使用事务防止重复
            DB::beginTransaction();
            try {
                // 处理金额数据，可能是对象或数组
                $amountTotal = null;
                if (isset($paymentData->amount)) {
                    if (is_object($paymentData->amount) && isset($paymentData->amount->total)) {
                        $amountTotal = $paymentData->amount->total;
                    } elseif (is_array($paymentData->amount) && isset($paymentData->amount['total'])) {
                        $amountTotal = $paymentData->amount['total'];
                    }
                }

                // 检查必要的字段是否存在
                if (! isset($paymentData->out_trade_no) || ! $amountTotal || ! isset($paymentData->transaction_id)) {
                    DB::rollback();

                    return Pay::wechat()->success();
                }

                $fund = Fund::where([
                    'id' => $paymentData->out_trade_no,
                    'amount' => bcdiv((string) $amountTotal, '100', 2),
                    'type' => 'addfunds',
                    'pay_method' => 'wechat',
                    'status' => 0, // processing
                ])->lockForUpdate()->first();

                if ($fund) {
                    $fund->status = 1; // successful
                    $fund->pay_sn = $paymentData->transaction_id;
                    $fund->save();
                    DB::commit();

                    return Pay::wechat()->success();
                } else {
                    // 检查是否已经处理过（状态为成功）
                    $existingFund = Fund::where([
                        'id' => $paymentData->out_trade_no,
                        'amount' => bcdiv((string) $amountTotal, '100', 2),
                        'type' => 'addfunds',
                        'pay_method' => 'wechat',
                        'status' => 1, // successful
                    ])->first();

                    DB::rollback();

                    if ($existingFund) {
                        return Pay::wechat()->success();
                    }
                }
            } catch (Throwable $e) {
                DB::rollback();
                app(ApiExceptions::class)->logException($e);

                return Pay::wechat()->success();
            }
        }

        // 如果走到这里说明支付状态不是SUCCESS或者没有找到对应的订单
        return Pay::wechat()->success();
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
            $order = Pay::alipay()->query(['out_trade_no' => $fund->id]);
            if ($order['trade_status'] === 'TRADE_SUCCESS' || $order['trade_status'] === 'TRADE_FINISHED') {
                $pay_sn = $order['trade_no'];
            }
        }

        if ($fund->pay_method === 'wechat') {
            $this->getPayConfig('wechat');
            $order = Pay::wechat()->query(['out_trade_no' => $fund->id]);
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
     * 充值成功 使用事务防止重复
     *
     * @throws Throwable
     */
    protected function addfundsSuccessful(string $id, int|string $pay_sn): void
    {
        DB::beginTransaction();
        try {
            $fund = Fund::where([
                'id' => $id,
                'user_id' => $this->guard->id(),
                'type' => 'addfunds',
                'status' => 0, // processing
            ])->lockForUpdate()->first();

            if ($fund) {
                $fund->status = 1; // successful
                $fund->pay_sn = $pay_sn;
                $fund->save();
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollback();
            app(ApiExceptions::class)->logException($e);
        }
    }
}
