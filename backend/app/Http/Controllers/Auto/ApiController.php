<?php

namespace App\Http\Controllers\Auto;

use App\Exceptions\ApiResponseException;
use App\Http\Controllers\Controller;
use App\Models\Cert;
use App\Models\Order;
use App\Services\Order\Action;
use Illuminate\Http\Request;
use Throwable;

class ApiController extends Controller
{
    /**
     * 通过 refer_id 获取证书信息
     * 不需要用户认证，使用 refer_id 作为凭据
     * refer_id 从 Authorization: Bearer <refer_id> 中获取
     *
     * @throws Throwable
     */
    public function update(Request $request): void
    {
        // 从 Authorization Header 中获取 refer_id
        $referId = $request->bearerToken();

        if (empty($referId) || strlen($referId) !== 32) {
            $this->error('refer_id 无效');
        }

        $params = $request->validate([
            'csr' => ['nullable', 'string'],
            'validation_method' => ['nullable', 'in:txt,file,admin,administrator,postmaster,webmaster,hostmaster'],
        ]);

        $order = $this->findOrder($referId);
        $cert = $order->latestCert;
        $orderId = $order->id;
        $reQuery = false;

        // 证书状态如果是 unpaid 则支付并提交
        // 如果参数有 csr 则替换 csr 并删除 private_key
        if ($cert->status === 'unpaid') {
            if (! empty($params['csr'])) {
                $cert->csr = $params['csr'];
                $cert->private_key = null;
                $cert->save();
            }

            $action = new Action;
            $action->pay($orderId);
            $reQuery = true;
        }

        // 证书状态如果是 pending 则提交
        if ($cert->status === 'pending') {
            $action = new Action;
            $action->commit($orderId);
            $reQuery = true;
        }

        // 证书状态如果是 active
        // 而且证书签发时间已超过 15 天 或者 订单到期时间小于 15 天 则发起重签或续费
        // if ($cert->status === 'active' && ($cert->issued_at?->lt(now()->subDays(15)) || $order->period_till?->lt(now()->addDays(15)))) {
        if ($cert->status === 'active') {
            $updateParams = [
                'order_id' => $orderId,
            ];

            if (empty($params['csr'])) {
                $updateParams['csr_generate'] = 1;
            } else {
                $updateParams['csr_generate'] = 0;
                $updateParams['csr'] = $params['csr'];
            }

            $updateParams['channel'] = 'api';
            $updateParams['domains'] = $cert->alternative_names;
            $updateParams['validation_method'] = $params['validation_method'] ?? 'txt';

            // 如果订单到期时间小于 15 天则续费，否则重签
            $action = new Action;
            if ($order->period_till?->lt(now()->addDays(15))) {
                try {
                    $updateParams['action'] = 'renew';
                    $action->renew($updateParams);
                } catch (ApiResponseException $e) {
                    $result = $e->getApiResponse();

                    if ($result['code'] === 0) {
                        $this->error($result['msg'], $result['errors'] ?? null);
                    }

                    $orderId = $result['data']['order_id'];
                }
            } else {
                $updateParams['action'] = 'reissue';
                $action->reissue($updateParams);
            }

            $reQuery = true;
        }

        if ($reQuery) {
            $order = Order::with('latestCert')->whereHas('latestCert')->where('id', $orderId)->first();

            if (! $order) {
                $this->error('订单不存在');
            }

            $cert = $order->latestCert;
        }

        $data = $this->getCertData($cert);

        $this->success($data);
    }

    /**
     * 通过 refer_id 获取 common_name
     * 不需要用户认证，使用 refer_id 作为凭据
     * refer_id 从 Authorization: Bearer <refer_id> 中获取
     */
    public function get(Request $request): void
    {
        // 从 Authorization Header 中获取 refer_id
        $referId = $request->bearerToken();

        if (empty($referId) || strlen($referId) !== 32) {
            $this->error('refer_id 无效');
        }

        $order = $this->findOrder($referId);
        $cert = $order->latestCert;

        $data = $this->getCertData($cert);

        $this->success($data);
    }

    /**
     * 通过 refer_id 查找订单
     */
    private function findOrder(string $referId): Order
    {
        // 先尝试通过 latestCert.refer_id 查询
        $order = Order::with('latestCert')
            ->whereHas('latestCert', function ($query) use ($referId) {
                $query->where('refer_id', $referId);
            })->first();

        // 如果没找到，则通过 Cert 表回落查询
        if (! $order) {
            $cert = Cert::where('refer_id', $referId)->first();

            if (! $cert) {
                $this->error('证书不存在');
            }

            $order = Order::with('latestCert')->whereHas('latestCert')->where('id', $cert->order_id)->first();

            if (! $order) {
                $this->error('订单不存在');
            }
        }

        // 续费会生成新订单：当 refer_id 指向已标记为 renewed 的证书时，跟随链路找到最新订单
        if ($order->latestCert->status === 'renewed') {
            $order = $this->findNewOrder($order->latestCert);
        }

        // 缓存查询结果到 request 中供中间件复用，避免重复查询
        request()->attributes->set('auto_order', $order);

        return $order;
    }

    /**
     * 通过证书ID循环查找最新的证书
     * 限制查询12次防止无限循环
     */
    private function findNewOrder(Cert $cert): Order
    {
        $maxIterations = 12;
        $iteration = 0;

        // 循环查找以当前证书为 last_cert_id 的新证书
        while ($iteration < $maxIterations) {
            $newCert = Cert::where('last_cert_id', $cert->id)->first();

            if (! $newCert) {
                // 如果找不到新证书，说明当前证书就是最新的
                break;
            }

            $cert = $newCert;
            $iteration++;
        }

        // 通过最新证书获取订单
        $order = Order::with('latestCert')->whereHas('latestCert')->where('id', $cert->order_id)->first();

        if (! $order) {
            $this->error('订单不存在');
        }

        return $order;
    }

    /**
     * 部署回调接口
     * 部署工具完成部署后调用此接口通知 Manager
     */
    public function callback(Request $request): void
    {
        // 从 Authorization Header 中获取 refer_id
        $referId = $request->bearerToken();

        if (empty($referId) || strlen($referId) !== 32) {
            $this->error('refer_id 无效');
        }

        $params = $request->validate([
            'domain' => ['required', 'string'],
            'status' => ['required', 'in:success,failure'],
            'deployed_at' => ['nullable', 'string'],
            'cert_expires_at' => ['nullable', 'string'],
            'cert_serial' => ['nullable', 'string'],
            'server_type' => ['nullable', 'string'],
            'message' => ['nullable', 'string'],
        ]);

        // 查找证书
        $cert = Cert::where('refer_id', $referId)->first();

        if (! $cert) {
            $this->error('证书不存在');
        }

        // 只有部署成功才记录时间
        if ($params['status'] === 'success') {
            $deployTime = now();

            // 如果传了 deployed_at，尝试解析
            if (! empty($params['deployed_at'])) {
                try {
                    $deployTime = \Carbon\Carbon::parse($params['deployed_at']);
                } catch (\Exception) {
                    // 解析失败使用当前时间
                }
            }

            $cert->auto_deploy_at = $deployTime;
            $cert->save();
        }

        $this->success([
            'domain' => $params['domain'],
            'status' => $params['status'],
            'recorded' => $params['status'] === 'success',
        ]);
    }

    /**
     * 统一返回数据
     */
    private function getCertData(Cert $cert): array
    {
        $data = [
            'refer_id' => $cert->refer_id,
            'common_name' => $cert->common_name,
            'cert' => $cert->cert,
            'intermediate_cert' => $cert->intermediate_cert,
            'private_key' => $cert->private_key,
            'status' => $cert->status,
            'expires_at' => $cert->expires_at?->toDateTimeString(),
        ];

        if (in_array($cert->dcv['method'], ['file', 'http', 'https']) && $cert->status === 'processing') {
            $data['file'] = [
                'path' => $cert->dcv['file']['path'],
                'content' => $cert->dcv['file']['content'],
            ];
        }

        return $data;
    }
}
