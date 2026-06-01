<?php

declare(strict_types=1);

namespace App\Http\Controllers\Callback;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\Action;
use Illuminate\Http\Request;

class CallbackController extends Controller
{
    public function index(Request $request, string $endpoint = 'default'): void
    {
        $config = get_system_setting('callback', $endpoint);

        if (! $config && $endpoint !== 'default') {
            $config = get_system_setting('callback', 'default');
        }

        if (! $config) {
            $this->error('Endpoint not configured');
        }

        // token 与 allowed_ips 均未配置则拒绝回调：防出厂默认双空（SettingSeeder 出厂 token=''/allowed_ips=''）
        // 裸奔被刷 sync / 探测 api_id 存在性。配置任一即按下方规则校验（允许「仅 IP 白名单」或「仅 token」）
        if (empty($config['token']) && empty($config['allowed_ips'])) {
            $this->error('回调未配置鉴权');
        }

        // IP 白名单校验
        if (! empty($config['allowed_ips'])) {
            $allowedIps = array_map('trim', explode(',', $config['allowed_ips']));
            if (! in_array($request->ip(), $allowedIps)) {
                $this->error('IP not allowed');
            }
        }

        // Token 校验
        if (! empty($config['token'])) {
            $token = $request->input('token')
                ?? $request->input('password')
                ?? $request->server('TOKEN')
                ?? '';

            if (! hash_equals($config['token'], $token)) {
                $this->error('Invalid token');
            }
        }

        // 获取订单 API ID
        $idField = $config['id_field'] ?: 'id';
        $apiId = $request->input($idField) ?? '';

        if ($apiId === '') {
            $this->error('Missing ID');
        }

        // 查询订单
        $query = Order::with('latestCert')
            ->whereHas('latestCert', fn ($q) => $q->where('api_id', $apiId));

        if (! empty($config['sources'])) {
            $sources = array_map('trim', explode(',', $config['sources']));
            $query->whereHas('product', fn ($q) => $q->whereIn('source', $sources));
        }

        $order = $query->first();

        if (! $order) {
            $this->error('Order not found');
        }

        if (in_array($order->latestCert->status, ['processing', 'active', 'approving'])) {
            app(Action::class)->createTask($order->id, 'sync');
        }

        $this->success();
    }
}
