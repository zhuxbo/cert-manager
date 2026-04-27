<?php

declare(strict_types=1);

namespace Plugins\Easy\Controllers;

use App\Bootstrap\ApiExceptions;
use App\Exceptions\ApiResponseException;
use App\Http\Controllers\Controller;
use App\Models\DeployToken;
use App\Models\Fund;
use App\Models\Product;
use App\Models\User;
use App\Services\Notification\DTOs\NotificationIntent;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use App\Utils\Random;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Plugins\Easy\Models\Agiso;
use Throwable;

class EasyController extends Controller
{
    /**
     * 检查订单状态
     */
    public function check(Request $request): void
    {
        $params = $request->all();

        if (empty($params['email'] ?? '')) {
            $this->init($params);
        }

        $this->getStep($params);

        $this->error('未知错误');
    }

    /**
     * 获取订单信息
     *
     * Easy 通过 tid+email 组合做业务层认证（查询 agiso 表，数据量有限），
     * Action 已改造为无参构造，用户隔离不依赖 UserScope
     */
    protected function getOrder(array $params): Agiso
    {
        $order = Agiso::with(['user', 'order', 'latestCert', 'product'])
            ->where('tid', $params['tid'] ?? '')
            ->whereHas('user', function ($query) use ($params) {
                $query->where('email', $params['email'] ?? '');
            })
            ->first();

        if (! $order) {
            $this->error('订单与邮箱不匹配');
        }

        if (! $order->order) {
            $this->error('订单错误');
        }

        if (! $order->latestCert) {
            $this->error('订单错误');
        }

        if (! $order->product) {
            $this->error('订单错误');
        }

        return $order;
    }

    /**
     * 初始化订单检查
     */
    protected function init(array $params): void
    {
        $order = Agiso::where('tid', $params['tid'])->first();

        if (! $order) {
            $this->error('订单不存在');
        }

        if ($order->created_at->timestamp < time() - 3600 * 24 * 90) {
            $this->error('订单支付已超过90天，简易申请关闭');
        }

        if ($order->count > 1) {
            $this->error('一次购买多件不支持简易申请');
        }

        $product = $this->getProduct($order);
        if (empty($product)) {
            $this->error('此产品不支持简易申请');
        }

        if ($order->recharged === 0) {
            $this->success([
                'step' => 0,
                'product' => [
                    'name' => $product['name'],
                    'is_wildcard' => $product['is_wildcard'],
                    'validation_methods' => $product['validation_methods'],
                ],
                'is_applied' => 0,
            ]);
        }

        if ($order->recharged === 1 && ! $order->order_id) {
            $this->error('订单已使用');
        }

        if ($order->recharged === 1 && empty($params['email'])) {
            $this->success([
                'step' => 0,
                'product' => ['name' => $product['name']],
                'is_applied' => 1,
            ]);
        }
    }

    /**
     * 获取当前步骤
     */
    public function getStep(array $params): void
    {
        $order = $this->getOrder($params);

        if (in_array($order->latestCert->status, ['processing', 'pending', 'unpaid'])) {
            $product = $this->getProduct($order);
            $this->success([
                'step' => 1,
                'product' => [
                    'ca' => $product['ca'],
                    'is_wildcard' => $product['is_wildcard'],
                    'validation_methods' => $product['validation_methods'],
                ],
                'validation' => $order->latestCert->validation[0] ?? [],
                'cert' => [
                    'dcv' => $order->latestCert->dcv,
                    'domains' => $order->latestCert->alternative_names,
                ],
                'status' => 'processing',
            ]);
        }

        if ($order->latestCert->status === 'approving') {
            $this->success([
                'step' => 2,
                'validation' => $order->latestCert->validation[0] ?? [],
                'cert' => [
                    'dcv' => $order->latestCert->dcv,
                    'domains' => $order->latestCert->alternative_names,
                ],
                'status' => 'approving',
            ]);
        }

        if ($order->latestCert->status === 'active') {
            $responseData = [
                'step' => 3,
                'validation' => $order->latestCert->validation[0] ?? [],
                'cert' => $order->latestCert->cert."\n".$order->latestCert->intermediate_cert,
                'key' => $order->latestCert->private_key,
                'status' => 'active',
            ];

            $easyAutoDeploy = get_system_setting('site', 'easyAutoDeploy');
            if ($easyAutoDeploy !== false && $easyAutoDeploy !== 'false') {
                $responseData['deploy'] = $this->generateDeployCommands($order);
            }

            $this->success($responseData);
        }

        $this->error('订单状态错误');
    }

    /**
     * 重新验证
     *
     * @throws Throwable
     */
    public function revalidate(Request $request): void
    {
        $params = $request->all();
        $order = $this->getOrder($params);
        $action = new Action;

        if ($order->latestCert->status === 'processing') {
            try {
                $action->revalidate($order->order_id);
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                if ($result['code'] === 0) {
                    $action->createTask($order->order_id, 'sync', 5);
                    $this->error($result['msg'], $result['errors'] ?? null);
                }
            }
        }

        if ($order->latestCert->status === 'pending') {
            try {
                $action->commit($order->order_id);
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                if ($result['code'] === 0) {
                    $this->error($result['msg'], $result['errors'] ?? null);
                }
            }
        }

        if ($order->latestCert->status === 'unpaid') {
            try {
                $action->pay($order->order_id);
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                if ($result['code'] === 0) {
                    $this->error($result['msg'], $result['errors'] ?? null);
                }
            }
        }

        if ($order->latestCert->status === 'approving') {
            $action->createTask($order->order_id, 'sync', 5);
            $this->getStep($params);
        }

        if ($order->latestCert->status === 'active') {
            $this->getStep($params);
        }

        $this->success();
    }

    /**
     * 同步订单状态
     */
    public function sync(Request $request): void
    {
        $params = $request->all();
        $order = $this->getOrder($params);

        (new Action)->sync($order->order_id, true);

        $this->getStep($params);

        $this->success();
    }

    /**
     * 申请证书
     *
     * @throws Throwable
     */
    public function apply(Request $request): void
    {
        $params = $request->all();

        $order = Agiso::where('tid', $params['tid'] ?? '')->first();
        if (! $order) {
            $this->error('订单不存在');
        }

        if ($order->recharged === 1 && ! $order->order_id) {
            $this->error('订单已使用');
        }

        if ($order->created_at->timestamp < time() - 3600 * 24 * 90) {
            $this->error('订单支付已超过 90 天，简易申请关闭');
        }

        if ($order->count > 1) {
            $this->error('一次购买多件不支持简易申请');
        }

        $product = $this->getProduct($order);
        if (empty($product)) {
            $this->error('此产品不支持简易申请');
        }

        $this->validateApplyParams($params);

        if ($order->user_id) {
            $user = User::where('id', $order->user_id)->first();
            if (! $user) {
                $this->error('订单关联用户错误');
            }

            if ($user->email !== $params['email']) {
                $this->error('订单与邮箱不匹配');
            } else {
                // 如果订单与邮箱匹配直接返回成功 让前端重新检查订单状态
                $this->success();
            }
        }

        // 通用来源规则：直接使用 pay_method 作为用户来源标识。
        $source = trim((string) $order->pay_method);

        $user = $this->getUserByEmail($params['email'], $source);

        // 使用事务 充值并创建订单
        DB::beginTransaction();
        try {
            // 行级锁防止并发重复充值
            $order = Agiso::where('tid', $params['tid'] ?? '')->lockForUpdate()->first();
            if (! $order || $order->recharged === 1) {
                DB::rollback();
                $this->error('订单已使用');
            }
            $isPlatinum = false;
            if ($user->level_code === 'platinum' && empty($user->custom_level_code)) {
                $isPlatinum = true;
            }

            // 充值策略：
            // 1. 实付部分（amount）全额充值，不受 price 限制
            // 2. 赠送部分（price - amount）仅 platinum 用户享有
            // 3. 当 amount >= price 时，赠送金额 <= 0，不产生赠送充值
            if (bccomp((string) $order->amount, '0.00', 2) > 0) {
                Fund::create([
                    'user_id' => $user->id,
                    'amount' => $order->amount,
                    'type' => 'addfunds',
                    'pay_method' => $order->pay_method,
                    'pay_sn' => $order->tid,
                    'status' => 1,
                ]);
            }

            // 赠送部分充值
            if ($isPlatinum) {
                $giftAmount = bcsub((string) $order->price, (string) $order->amount, 2);
                if (bccomp($giftAmount, '0.00', 2) > 0) {
                    Fund::create([
                        'user_id' => $user->id,
                        'amount' => $giftAmount,
                        'type' => 'addfunds',
                        'pay_method' => 'gift',
                        'pay_sn' => $order->tid,
                        'status' => 1,
                    ]);
                }
            }

            // 提交证书申请（Action::new() 始终通过 ApiResponseException 返回结果，不会正常 return）
            $orderId = null;
            try {
                (new Action)->new([
                    'user_id' => $user->id,
                    'domains' => $params['domain'],
                    'product_id' => $product['id'],
                    'period' => $product['period'],
                    'validation_method' => $params['validation_method'],
                    'action' => 'new',
                    'channel' => 'web',
                    'csr_generate' => 1,
                    'plus' => 1,
                ]);
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                if ($result['code'] === 1) {
                    $orderId = $result['data']['order_id'];
                } else {
                    DB::rollback();
                    $this->error($result['msg'] ?? '证书提交失败', $result['errors'] ?? null);
                }
            }

            // 支付并提交订单
            try {
                if ($orderId !== null) {
                    (new Action)->pay($orderId, true, true);
                }
            } catch (ApiResponseException $e) {
                $result = $e->getApiResponse();
                if ($result['code'] === 1) {
                    $orderId = $result['data']['order_id'];
                } else {
                    DB::rollback();
                    $this->error($result['msg'] ?? '证书支付失败', $result['errors'] ?? null);
                }
            }

            $order->update([
                'user_id' => $user->id,
                'order_id' => $orderId ?? null,
                'recharged' => 1,
            ]);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollback();
            throw $e;
        }

        $this->success();
    }

    /**
     * 更新验证方法
     */
    public function updateValidationMethod(Request $request): void
    {
        $params = $request->all();
        $order = $this->getOrder($params);

        $action = new Action;

        try {
            $action->updateDCV($order->order_id, $params['validation_method'] ?? 'cname');
            $this->success();
        } catch (ApiResponseException $e) {
            $result = $e->getApiResponse();
            // Action::updateDCV 成功时也会抛出 ApiResponseException，需要检查 code
            if ($result['code'] === 1) {
                $this->success();
            }
            // 防止订单信息与 CA 未同步
            $action->createTask($order->order_id, 'sync', 10);
            $this->error($result['msg'] ?? '更新失败', $result['errors'] ?? null);
        }
    }

    /**
     * 验证文件
     */
    public function validateFile(Request $request): void
    {
        $params = $request->all();
        $order = $this->getOrder($params);
        (new Action)->downloadValidateFile($order->order_id);
    }

    /**
     * 下载证书
     */
    public function download(Request $request): void
    {
        $params = $request->all();
        $order = $this->getOrder($params);
        (new Action)->download($order->order_id, $params['type'] ?? 'all');
    }

    /**
     * 获取产品配置
     */
    protected function getProduct(Agiso $order): array
    {
        if ($order->count > 1) {
            return [];
        }

        // 检查 product_code 是否存在
        if (empty($order->product_code)) {
            return [];
        }

        // 根据 product_code 查询产品
        $product = Product::where('code', $order->product_code)->first();
        if (! $product || $product->validation_type !== 'dv') {
            return [];
        }

        // Todo: Sectigo 暂时屏蔽 txt 解析，待 TXT 记录获取方法完善后放开
        if (strtolower($product->ca) === 'sectigo') {
            $product->validation_methods = array_filter($product->validation_methods, function ($method) {
                return $method !== 'txt';
            });
        }

        // 根据产品信息生成easy需要的数据结构
        return [
            'name' => $product->name,
            'ca' => $product->brand,
            'id' => $product->id,
            'period' => $order->period ?? 1,
            'is_wildcard' => $this->isWildcardSupported($product->common_name_types),
            'validation_methods' => $this->parseValidationMethods($product->validation_methods),
        ];
    }

    /**
     * 判断是否支持通配符
     */
    protected function isWildcardSupported(?array $commonNameTypes): int
    {
        if (empty($commonNameTypes) || ! is_array($commonNameTypes)) {
            return 0;
        }

        if (in_array('wildcard', $commonNameTypes)) {
            return 1;
        }

        return 0;
    }

    /**
     * 解析验证方法
     */
    protected function parseValidationMethods($validationMethods): array
    {
        if (empty($validationMethods) || ! is_array($validationMethods)) {
            return [];
        }

        // 按优先级排序的验证方法（与 admin/user 端一致）
        $orderedLabels = [
            'delegation' => '委托验证(自动续签)',
            'txt' => '解析验证 TXT',
            'cname' => '解析验证 CNAME',
            'file' => '文件验证 FILE',
            'http' => '文件验证 FILE',
        ];

        $methods = array_map('trim', $validationMethods);
        $result = [];
        foreach ($orderedLabels as $key => $label) {
            if (in_array($key, $methods)) {
                $result[$key] = $label;
            }
        }

        return $result;
    }

    /**
     * 根据邮箱获取或创建用户ID
     */
    protected function getUserByEmail(string $email, string $source = ''): User
    {
        $user = User::where('email', $email)->first();

        // 根据 sourceLevel 设置确定目标等级，默认 platinum
        $targetLevel = 'platinum';
        if ($source !== '') {
            $sourceLevel = get_system_setting('site', 'sourceLevel', []);
            $targetLevel = $this->resolveLevelFromSource($source, is_array($sourceLevel) ? $sourceLevel : []);
        }

        if (! $user) {
            $username = Random::build('alpha', 10);
            $password = Random::build('alnum', 10);

            // 创建用户
            $user = User::create([
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'level_code' => $targetLevel,
                'source' => $source,
                'status' => 1,
                'join_at' => now(),
                'join_ip' => request()->ip(),
            ]);

            // 发送注册通知邮件
            try {
                $siteName = get_system_setting('site', 'name', 'SSL证书管理系统');
                $siteDomain = get_system_setting('site', 'url') ?? $siteName;

                $intent = new NotificationIntent(
                    'user_created',
                    'user',
                    $user->id,
                    [
                        'username' => $username,
                        'password' => $password,
                        'site_name' => $siteName,
                        'site_url' => $siteDomain,
                        'email' => $email,
                    ],
                    ['mail']
                );

                app(NotificationCenter::class)->dispatch($intent);
            } catch (Throwable $e) {
                app(ApiExceptions::class)->logException($e);
            }
        } else {
            // 已有用户：内置等级保底提升到目标等级，自定义等级不动
            $levelOrder = ['standard' => 1, 'gold' => 2, 'platinum' => 3, 'crown' => 4, 'partner' => 5];

            if (isset($levelOrder[$user->level_code]) && isset($levelOrder[$targetLevel])
                && $levelOrder[$targetLevel] > $levelOrder[$user->level_code]) {
                $user->update(['level_code' => $targetLevel]);
            }
        }

        return $user;
    }

    /**
     * 根据来源获取等级；未配置或为空时默认 platinum。
     */
    protected function resolveLevelFromSource(string $source, array $sourceLevel): string
    {
        if (! isset($sourceLevel[$source])) {
            return 'platinum';
        }

        $level = trim((string) $sourceLevel[$source]);
        if ($level === '') {
            return 'platinum';
        }

        return $level;
    }

    /**
     * 验证申请参数
     */
    protected function validateApplyParams(array $params): void
    {
        $rules = [
            'domain' => 'required',
            'email' => 'required|email',
            'validation_method' => 'required|in:file,http,https,cname,txt,delegation',
        ];

        $messages = [
            'domain.required' => '域名不能为空',
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式错误',
            'validation_method.required' => '验证方法不能为空',
            'validation_method.in' => '验证方法错误',
        ];

        $validator = Validator::make($params, $rules, $messages);

        if ($validator->fails()) {
            $errors = $validator->errors()->all();
            $this->error(implode(' ', $errors));
        }
    }

    /**
     * 生成部署命令
     */
    protected function generateDeployCommands(Agiso $order): array
    {
        $deployToken = DeployToken::where('user_id', $order->user_id)->first();
        if (! $deployToken) {
            $deployToken = DeployToken::create([
                'user_id' => $order->user_id,
                'token' => Str::random(32),
            ]);
        }
        $token = $deployToken->token;

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
        $orderId = $order->order_id;

        return [
            'install' => [
                'linux' => "curl -fsSL $releaseUrl/sslctl/install.sh | sudo bash -s -- $releaseDomain",
                'windows' => "irm $releaseUrl/sslctl/install.ps1 -OutFile install.ps1; .\\install.ps1 -ReleaseHost $releaseDomain",
            ],
            'deploy' => "sslctl setup --url $deployUrl --token $token --order $orderId",
            'iis_install' => [
                'download' => "$releaseUrl/sslctlw/latest/sslctlw.exe",
                'windows' => "irm $releaseUrl/sslctlw/install.ps1 -OutFile install.ps1; .\\install.ps1 -ReleaseHost $releaseDomain",
            ],
            'iis_deploy' => "sslctlw setup --url $deployUrl --token $token --order $orderId",
            'bt_install' => [
                'linux' => "curl -fsSL $releaseUrl/sslbt/install.sh | sudo bash -s -- $releaseDomain",
            ],
            'bt_deploy' => "$deployUrl?token=$token&order=$orderId",
        ];
    }
}
