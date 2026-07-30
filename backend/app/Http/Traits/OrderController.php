<?php

namespace App\Http\Traits;

use App\Http\Requests\Order\GetIdsRequest;
use App\Models\DeployToken;
use App\Models\DomainValidationRecord;
use App\Models\Order;
use App\Models\Product;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property Action $action
 */
trait OrderController
{
    /**
     * 支付订单
     *
     * @throws Throwable
     */
    public function pay(int $id): void
    {
        $commit = request()->boolean('commit', true);
        $issueVerify = request()->boolean('issue_verify', true);
        $this->action->pay($id, $commit, $issueVerify);
    }

    /**
     * 提交订单
     *
     * @throws Throwable
     */
    public function commit(int $id): void
    {
        $this->action->commit($id);
    }

    /**
     * 重新验证
     */
    public function revalidate(int $id): void
    {
        // 重置域名验证记录，重新开始验证计时
        DomainValidationRecord::where('order_id', $id)->delete();

        // 清除委托写入标记，强制重新处理
        $this->clearAutoTxtWrittenMarks($id);

        $this->action->revalidate($id);
    }

    /**
     * 更新 DCV
     */
    public function updateDCV(int $id): void
    {
        // 重置域名验证记录，重新开始验证计时
        DomainValidationRecord::where('order_id', $id)->delete();

        // 清除委托写入标记，切换验证方法时强制重新处理
        $this->clearAutoTxtWrittenMarks($id);

        $method = request()->string('method', '')->trim();
        $this->action->updateDCV($id, $method);
    }

    /**
     * 同步订单
     */
    public function sync(int $id): void
    {
        $this->action->sync($id);
    }

    /**
     * 提交取消订单
     *
     * @throws Throwable
     */
    public function commitCancel(int $id): void
    {
        $this->action->commitCancel($id);
    }

    /**
     * 撤销取消订单
     *
     * @throws Throwable
     */
    public function revokeCancel(int $id): void
    {
        $this->action->revokeCancel($id);
    }

    /**
     * 标记订单为已续费（renewed 终态，到期前 30 天内可用）
     *
     * @throws Throwable
     */
    public function markRenewed(int $id): void
    {
        $this->action->markRenewed($id);
    }

    /**
     * 备注订单
     */
    public function remark(int $id): void
    {
        $remark = request()->string('remark')->trim()->limit(255);
        $this->action->remark($id, $remark);
    }

    /**
     * 下载证书
     */
    public function download(): void
    {
        $ids = request()->input('ids');
        $type = request()->input('type', 'all');
        $this->action->download($ids, $type);
    }

    /**
     * 下载验证文件
     */
    public function downloadValidateFile(int $id): void
    {
        $this->action->downloadValidateFile($id);
    }

    /**
     * 发送激活邮件
     */
    public function sendActive(int $id): void
    {
        $email = (string) request()->string('email', '')->trim();
        $order = Order::with(['user:id,email', 'product:id,product_type', 'latestCert:id,order_id,status'])->find($id);
        if (! $order || $order->latestCert?->status !== 'active') {
            $this->error('订单或用户不存在');
        }
        if (! in_array($order->product->product_type, [Product::TYPE_SSL, Product::TYPE_SMIME], true)) {
            $this->error('代码签名和文档签名不发送签发通知');
        }

        $targetEmail = $email ?: $order->user->email;
        if (! $targetEmail) { // @phpstan-ignore booleanNot.alwaysFalse
            $this->error('邮箱为空');
        }

        app(NotificationCenter::class)->dispatch(new NotificationIntent(
            'cert_issued',
            'user',
            $order->user->id,
            [
                'order_id' => $order->id,
                'email' => $targetEmail,
            ]
        ));
        $this->success();
    }

    /**
     * 批量支付
     *
     * @throws Throwable
     */
    public function batchPay(GetIdsRequest $request): void
    {
        $validated = $request->validated();
        $commit = request()->boolean('commit', true);
        $issueVerify = request()->boolean('issue_verify', true);
        $this->action->pay($validated['ids'], $commit, $issueVerify);
    }

    /**
     * 批量提交
     */
    public function batchCommit(GetIdsRequest $request): void
    {
        $validated = $request->validated();
        $this->action->batchCommit($validated['ids']);
    }

    /**
     * 批量重新验证
     */
    public function batchRevalidate(GetIdsRequest $request): void
    {
        $validated = $request->validated();

        // 重置域名验证记录，重新开始验证计时
        DomainValidationRecord::whereIn('order_id', $validated['ids'])->delete();

        // 清除委托写入标记，强制重新处理
        foreach ($validated['ids'] as $id) {
            $this->clearAutoTxtWrittenMarks($id);
        }

        $this->action->batchRevalidate($validated['ids']);
    }

    /**
     * 批量同步
     */
    public function batchSync(GetIdsRequest $request): void
    {
        $validated = $request->validated();
        $this->action->batchSync($validated['ids']);
    }

    /**
     * 批量提交取消
     *
     * @throws Throwable
     */
    public function batchCommitCancel(GetIdsRequest $request): void
    {
        $validated = $request->validated();
        $this->action->batchCommitCancel($validated['ids']);
    }

    /**
     * 批量撤销取消
     *
     * @throws Throwable
     */
    public function batchRevokeCancel(GetIdsRequest $request): void
    {
        $validated = $request->validated();
        $this->action->batchRevokeCancel($validated['ids']);
    }

    /**
     * 更新订单自动续费/重签设置
     */
    public function updateAutoSettings(int $id): void
    {
        $validated = request()->validate([
            'auto_renew' => 'nullable',
            'auto_reissue' => 'nullable',
        ]);

        $order = Order::find($id);
        if (! $order) {
            $this->error('订单不存在');
        }

        // "global" 字符串表示使用全局设置，转换为 null
        if (array_key_exists('auto_renew', $validated)) {
            $order->auto_renew = $validated['auto_renew'] === 'global' ? null : $validated['auto_renew'];
        }
        if (array_key_exists('auto_reissue', $validated)) {
            $order->auto_reissue = $validated['auto_reissue'] === 'global' ? null : $validated['auto_reissue'];
        }

        $order->save();

        $this->success([
            'auto_renew' => $order->auto_renew,
            'auto_reissue' => $order->auto_reissue,
        ]);
    }

    /**
     * 获取部署命令
     */
    public function deployCommands(): void
    {
        $orderIds = request()->input('order_ids', '');

        if (empty($orderIds) || ! preg_match('/^\d+(,\d+)*$/', $orderIds)) {
            $this->error('参数格式错误');
        }

        // 通过订单获取 user_id，用于查找该用户的 deploy token
        // User 端：Order 受 UserScope 保护，只能查到自己的订单
        // Admin 端：无 UserScope，可查任意订单，通过 user_id 定位对应用户的 token
        $firstId = (int) explode(',', $orderIds)[0];
        $order = Order::with('latestCert')->find($firstId);
        if (! $order) {
            $this->error('订单不存在');
        }
        $userId = $order->user_id;

        // 查询用户的 deploy token，不存在则自动生成
        $deployToken = DeployToken::where('user_id', $userId)->first();
        if (! $deployToken) {
            $deployToken = DeployToken::create([
                'user_id' => $userId,
                'token' => Str::random(32),
            ]);
        }
        $token = $deployToken->token;

        // site.url 未配置时返回 null：(string) 强转与下行 releaseDomain 对齐（消 null 弃用告警，防未来补 strict_types 时 rtrim(null) 引爆）
        $siteUrl = rtrim((string) get_system_setting('site', 'url'), '/');
        $deployUrl = "$siteUrl/api/deploy";
        $releaseDomain = rtrim((string) get_system_setting('site', 'releaseDomain'), '/');

        if ($releaseDomain) {
            $releaseUrl = "https://$releaseDomain";
        } else {
            $releaseDomain = parse_url($siteUrl, PHP_URL_HOST);
            $port = parse_url($siteUrl, PHP_URL_PORT);
            if ($port) {
                $releaseDomain .= ":$port";
            }
            $releaseUrl = "$siteUrl/release";
        }

        // URL 拉取仅支持单订单（用于 certimate 等通过 URL 获取证书的场景）
        $singleOrderId = ! str_contains($orderIds, ',') ? $orderIds : null;
        $commonName = $singleOrderId ? $order->latestCert->common_name : null;

        $this->success([
            'install' => [
                'linux' => "curl -fsSL $releaseUrl/sslctl/install.sh | sudo bash -s -- $releaseDomain",
                'windows' => "irm $releaseUrl/sslctl/install.ps1 -OutFile install.ps1; .\\install.ps1 -ReleaseHost $releaseDomain",
            ],
            'deploy' => "sslctl setup --url $deployUrl --token $token --order $orderIds",
            'iis_install' => [
                'windows' => "irm $releaseUrl/sslctlw/install.ps1 -OutFile install.ps1; .\\install.ps1 -ReleaseHost $releaseDomain",
            ],
            'iis_deploy' => "sslctlw setup --url $deployUrl --token $token --order $orderIds",
            'bt_install' => [
                'linux' => "curl -fsSL $releaseUrl/sslbt/install.sh | sudo bash -s -- $releaseDomain",
            ],
            'bt_deploy' => "$deployUrl?token=$token&order=$orderIds",
            'cert_url_id' => $singleOrderId
                ? "$deployUrl?token=$token&order=$singleOrderId&field=certificate"
                : null,
            'key_url_id' => $singleOrderId
                ? "$deployUrl?token=$token&order=$singleOrderId&field=private_key"
                : null,
            'cert_url_domain' => $commonName
                ? "$deployUrl?token=$token&order=$commonName&field=certificate"
                : null,
            'key_url_domain' => $commonName
                ? "$deployUrl?token=$token&order=$commonName&field=private_key"
                : null,
        ]);
    }

    /**
     * 清除订单的委托写入标记
     */
    protected function clearAutoTxtWrittenMarks(int $orderId): void
    {
        $order = Order::with('latestCert')->find($orderId);
        if (! $order) {
            return;
        }

        $cert = $order->latestCert;
        $validation = $cert->validation;

        if (empty($validation)) {
            return;
        }

        $hasChanges = false;
        foreach ($validation as &$item) {
            if (isset($item['auto_txt_written'])) {
                unset($item['auto_txt_written'], $item['auto_txt_written_at']);
                $hasChanges = true;
            }
        }
        unset($item);

        if ($hasChanges) {
            $cert->validation = $validation;
            $cert->save();
        }
    }
}
