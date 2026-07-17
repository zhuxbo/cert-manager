<?php

namespace Plugins\CloudDeploy\Controllers\Admin;

use App\Http\Controllers\Admin\BaseController;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\CloudDeploy\Deployers\Registry;
use Plugins\CloudDeploy\Models\CloudDeployAccess;
use Plugins\CloudDeploy\Models\CloudDeployLog;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Requests\AccessStoreRequest;
use Plugins\CloudDeploy\Requests\AccessUpdateRequest;
use Plugins\CloudDeploy\Requests\DeployRequest;
use Plugins\CloudDeploy\Requests\TargetStoreRequest;
use Plugins\CloudDeploy\Requests\TargetUpdateRequest;
use Plugins\CloudDeploy\Services\DeployService;
use Plugins\CloudDeploy\Services\TargetMutationService;

class CloudDeployController extends BaseController
{
    public function providers(): void
    {
        $this->success(app(Registry::class)->catalog());
    }

    public function accesses(Request $request): void
    {
        $query = CloudDeployAccess::query()
            ->select(['id', 'user_id', 'name', 'provider', 'created_at']); // 脱敏：不含 credentials

        if ($request->filled('user_id')) {
            $query->where('cloud_deploy_accesses.user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('username')) {
            // 云凭证「用户」维度：admin 跨用户按用户名筛选（accesses 表只有 user_id、无 username 列），
            // 经 whereExists 关联 users，不 raw join（避免 select 白名单外带列 + 列名歧义）
            $username = (string) $request->input('username');
            $query->whereExists(function ($q) use ($username) {
                $q->select(DB::raw(1))->from('users')
                    ->whereColumn('users.id', 'cloud_deploy_accesses.user_id')
                    ->where('users.username', 'like', "%$username%");
            });
        }
        if ($request->filled('name')) {
            $query->where('name', 'like', '%'.$request->input('name').'%');
        }
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }
        if ($request->filled('created_at_start')) {
            $query->where('created_at', '>=', $request->input('created_at_start'));
        }
        if ($request->filled('created_at_end')) {
            $query->where('created_at', '<=', $request->input('created_at_end'));
        }

        $this->respondPaginated($request, $query);
    }

    public function storeAccess(AccessStoreRequest $request): void
    {
        $validated = $request->validated();
        $userId = (int) ($validated['user_id'] ?? 0);
        if ($userId <= 0) {
            $this->error('请选择用户');
        }

        $exists = User::withoutGlobalScopes()->whereKey($userId)->exists();
        if (! $exists) {
            $this->error('用户不存在');
        }

        $validated['user_id'] = $userId;
        $access = CloudDeployAccess::create($validated);
        if (! $access->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    public function showAccess(int $id): void
    {
        $access = CloudDeployAccess::withoutGlobalScopes()
            ->select(['id', 'user_id', 'name', 'provider', 'created_at'])
            ->find($id);
        if (! $access) {
            $this->error('凭证不存在');
        }

        $items = collect([$access]);
        $this->attachUsernames($items);

        $this->success($access->toArray());
    }

    public function updateAccess(AccessUpdateRequest $request, int $id): void
    {
        $access = CloudDeployAccess::withoutGlobalScopes()->find($id);
        if (! $access) {
            $this->error('凭证不存在');
        }

        $validated = $request->validated();
        if (isset($validated['provider']) && $validated['provider'] !== $access->provider) {
            $this->error('云平台不可修改');
        }
        if (isset($validated['user_id']) && (int) $validated['user_id'] !== (int) $access->user_id) {
            $this->error('用户不可修改');
        }

        unset($validated['provider'], $validated['user_id']);
        if (empty($validated['credentials'])) {
            unset($validated['credentials']);
        }

        $access->fill($validated);
        $access->save();

        $this->success();
    }

    public function destroyAccess(int $id): void
    {
        $access = CloudDeployAccess::withoutGlobalScopes()->find($id);
        if (! $access) {
            $this->error('凭证不存在');
        }

        $inUse = CloudDeployTarget::withoutGlobalScopes()
            ->where('access_id', $id)->exists();
        if ($inUse) {
            $this->error('该凭证仍被部署目标引用，请先删除相关目标');
        }

        $access->delete();
        $this->success();
    }

    public function targets(Request $request): void
    {
        $query = CloudDeployTarget::query();

        if ($request->filled('user_id')) {
            // 显式带表前缀避免与 whereHas 子查询的潜在列名歧义
            $query->where('cloud_deploy_targets.user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('order_id')) {
            $query->where('order_id', (int) $request->input('order_id'));
        }
        if ($request->filled('product')) {
            $query->where('product', $request->input('product'));
        }
        if ($request->filled('provider')) {
            // targets 表无 provider 列，经 access 关系筛选
            $provider = (string) $request->input('provider');
            $query->whereHas('access', fn ($q) => $q->where('provider', $provider));
        }
        if ($request->has('enabled') && $request->input('enabled') !== '' && $request->input('enabled') !== null) {
            $query->where('enabled', filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('last_status')) {
            $status = (string) $request->input('last_status');
            if ($status === 'unpushed') {
                $query->whereNull('last_status'); // 未推送
            } else {
                $query->where('last_status', $status);
            }
        }
        if ($request->filled('last_deployed_at_start')) {
            $query->where('last_deployed_at', '>=', $request->input('last_deployed_at_start'));
        }
        if ($request->filled('last_deployed_at_end')) {
            $query->where('last_deployed_at', '<=', $request->input('last_deployed_at_end'));
        }
        if ($request->filled('created_at_start')) {
            $query->where('cloud_deploy_targets.created_at', '>=', $request->input('created_at_start'));
        }
        if ($request->filled('created_at_end')) {
            $query->where('cloud_deploy_targets.created_at', '<=', $request->input('created_at_end'));
        }
        if ($request->filled('username')) {
            $username = (string) $request->input('username');
            $query->whereExists(function ($q) use ($username) {
                $q->select(DB::raw(1))->from('users')
                    ->whereColumn('users.id', 'cloud_deploy_targets.user_id')
                    ->where('users.username', 'like', "%$username%");
            });
        }
        if ($request->filled('keyword')) {
            // 域名 = 证书 common_name，经相关子查询（依赖 order() 关系），不 raw join 防 1052
            $keyword = (string) $request->input('keyword');
            $query->whereHas('order.latestCert', fn ($q) => $q->where('common_name', 'like', "%$keyword%"));
        }
        if ($request->filled('quickSearch')) {
            $kw = (string) $request->input('quickSearch');
            $query->where(function (Builder $outer) use ($kw) {
                $outer->where('cloud_deploy_targets.order_id', $kw) // 订单号精确
                    ->orWhereHas('order.latestCert', fn ($q) => $q->where('common_name', 'like', "%$kw%")) // 域名
                    ->orWhereHas('access', fn ($q) => $q->where('name', 'like', "%$kw%")) // 凭证名
                    ->orWhereExists(function ($q) use ($kw) { // 用户名
                        $q->select(DB::raw(1))->from('users')
                            ->whereColumn('users.id', 'cloud_deploy_targets.user_id')
                            ->where('users.username', 'like', "%$kw%");
                    });
            });
        }

        // targets 不走通用 respondPaginated：admin 跨用户视图须逐行脱敏 config + 拍平 provider
        $currentPage = (int) $request->input('currentPage', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $total = $query->count();
        $items = $query->with('access:id,provider')
            ->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)->limit($pageSize)->get();

        $registry = app(Registry::class);
        $items->transform(function (CloudDeployTarget $target) use ($registry) {
            $target->setAttribute('config', $this->redactConfig($registry, $target));
            // 顶层 provider 拍平：target 表无 provider 列，前端列绑 row.provider，经已 eager-load 的 access 取
            $target->setAttribute('provider', $target->access?->provider);

            return $target;
        });
        // 用户名拍平（「用户」列显示用户名）：targets 走本内联路径、不经 respondPaginated，须显式调一次
        $this->attachUsernames($items);

        $this->success([
            'items' => $items, 'total' => $total,
            'pageSize' => $pageSize, 'currentPage' => $currentPage,
        ]);
    }

    public function storeTarget(TargetStoreRequest $request): void
    {
        $validated = $request->validated();
        $orderUserId = Order::withoutGlobalScopes()
            ->whereKey((int) $validated['order_id'])
            ->value('user_id');

        if ($orderUserId === null) {
            $this->error('订单不存在');
        }

        $service = app(TargetMutationService::class);
        $validated = $service->prepareForCreate($validated, (int) $orderUserId);
        $target = $service->create($validated);
        if (! $target->exists) {
            $this->error('添加失败');
        }

        $this->success();
    }

    public function showTarget(int $id): void
    {
        $target = CloudDeployTarget::withoutGlobalScopes()
            ->with('access:id,provider')
            ->find($id);
        if (! $target) {
            $this->error('目标不存在');
        }

        $target->setAttribute('provider', $target->access?->provider);
        $items = collect([$target]);
        $this->attachUsernames($items);

        $this->success($target->toArray());
    }

    public function updateTarget(TargetUpdateRequest $request, int $id): void
    {
        $target = CloudDeployTarget::withoutGlobalScopes()->find($id);
        if (! $target) {
            $this->error('目标不存在');
        }

        $service = app(TargetMutationService::class);
        $validated = $service->prepareForUpdate($target, $request->validated(), null);
        $service->update($target, $validated);

        $this->success();
    }

    public function destroyTarget(int $id): void
    {
        $target = CloudDeployTarget::withoutGlobalScopes()->find($id);
        if (! $target) {
            $this->error('目标不存在');
        }

        $target->delete();
        $this->success();
    }

    public function logs(Request $request): void
    {
        $query = CloudDeployLog::query();

        if ($request->filled('user_id')) {
            $query->where('cloud_deploy_logs.user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('order_id')) {
            $query->where('order_id', (int) $request->input('order_id'));
        }
        if ($request->filled('target_id')) {
            $query->where('target_id', (int) $request->input('target_id'));
        }
        if ($request->filled('provider')) {
            $query->where('provider', $request->input('provider'));
        }
        if ($request->filled('product')) {
            $query->where('product', $request->input('product'));
        }
        if ($request->filled('trigger')) {
            $query->where('trigger', $request->input('trigger'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('is_final') && $request->input('is_final') !== '' && $request->input('is_final') !== null) {
            $query->where('is_final', filter_var($request->input('is_final'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('keyword')) {
            $keyword = (string) $request->input('keyword');
            $query->where('resource_summary', 'like', "%$keyword%"); // 域名快照（仅 domain 型 deployer 非空）
        }
        if ($request->filled('username')) {
            // 独立用户名筛选（与 targets() 对称；logs 无 username 列，whereExists join users）
            $username = (string) $request->input('username');
            $query->whereExists(function ($q) use ($username) {
                $q->select(DB::raw(1))->from('users')
                    ->whereColumn('users.id', 'cloud_deploy_logs.user_id')
                    ->where('users.username', 'like', "%$username%");
            });
        }
        if ($request->filled('created_at_start')) {
            $query->where('cloud_deploy_logs.created_at', '>=', $request->input('created_at_start'));
        }
        if ($request->filled('created_at_end')) {
            $query->where('cloud_deploy_logs.created_at', '<=', $request->input('created_at_end'));
        }
        if ($request->filled('quickSearch')) {
            $kw = (string) $request->input('quickSearch');
            $query->where(function (Builder $outer) use ($kw) {
                $outer->where('order_id', $kw) // 订单号精确
                    ->orWhere('resource_summary', 'like', "%$kw%") // 域名快照
                    ->orWhere('access_name', 'like', "%$kw%") // 凭证名快照
                    ->orWhereExists(function ($q) use ($kw) { // 用户名（join users）
                        $q->select(DB::raw(1))->from('users')
                            ->whereColumn('users.id', 'cloud_deploy_logs.user_id')
                            ->where('users.username', 'like', "%$kw%");
                    });
            });
        }

        $this->respondPaginated($request, $query);
    }

    public function deploy(DeployRequest $request): void
    {
        $validated = $request->validated();
        // admin 入口：系统设置页走 target_ids 跨用户直查；订单详情页可用 order_id + user_id 收敛到单订单。
        // 默认 force=true —— 手动点推=显式重推意图，绕 CloudDeployJob 幂等短路（与 user pushAll 一致）。
        $dispatched = app(DeployService::class)->deploy(
            isset($validated['order_id']) ? (int) $validated['order_id'] : null,
            $validated['target_ids'] ?? [],
            (bool) ($validated['force'] ?? true),
            true,
            isset($validated['user_id']) ? (int) $validated['user_id'] : null,
        );

        $this->success(['dispatched' => $dispatched]);
    }

    private function respondPaginated(Request $request, $query): void
    {
        $currentPage = (int) $request->input('currentPage', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $total = $query->count();
        $items = $query->orderBy('id', 'desc')
            ->offset(($currentPage - 1) * $pageSize)->limit($pageSize)->get();

        $this->attachUsernames($items);

        $this->success([
            'items' => $items, 'total' => $total,
            'pageSize' => $pageSize, 'currentPage' => $currentPage,
        ]);
    }

    /**
     * admin 三个列表都展示「用户」列为用户名：targets/logs/accesses 表都只存 user_id、
     * 模型无 user() 关系，此处单次批量 whereIn 查 username 拍平为顶层 username 字段，
     * 不引入 N+1。User 有全局 UserScope，admin 跨用户须 withoutGlobalScopes。
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $items
     */
    private function attachUsernames(Collection $items): void
    {
        $userIds = $items->pluck('user_id')->filter()->unique()->all();
        if ($userIds === []) {
            return;
        }
        $names = User::withoutGlobalScopes()
            ->whereIn('id', $userIds)->pluck('username', 'id');
        $items->each(fn ($row) => $row->setAttribute('username', $names[$row->getAttribute('user_id')] ?? null));
    }

    /**
     * admin 跨用户视图脱敏 target.config：
     * - 已注册 (provider,product)：按 configSchema 把 secret=true 的键打码，保留非 secret 键（domain 等资源列）。
     * - 未注册（陈旧/改名/脏数据）：fail-safe 打码除已知安全键 domain 外的全部键，不调 resolveDeployer（否则抛
     *   InvalidArgumentException 致整个跨用户列表 500）、不抛。
     *
     * @return array<array-key,mixed>
     */
    private function redactConfig(Registry $registry, CloudDeployTarget $target): array
    {
        $config = (array) $target->config;
        if ($config === []) {
            return $config;
        }

        // getAttribute 返回 mixed + instanceof 窄化：绕过 larastan「belongsTo 必非 null」的乐观推断，
        // 保留 access 悬空（凭证被删、target 留存）→ provider '' → 走下方 fail-safe 的健壮性。
        $access = $target->getAttribute('access');
        $provider = $access instanceof CloudDeployAccess ? $access->provider : '';

        if ($provider !== '' && $registry->hasDeployer($provider, $target->product)) {
            foreach ($registry->resolveDeployer($provider, $target->product)->configSchema() as $field) {
                if (($field['secret'] ?? false) === true && array_key_exists($field['key'], $config)) {
                    $config[$field['key']] = '******';
                }
            }

            return $config;
        }

        // fail-safe：未注册组合，除 domain 外全部打码
        foreach (array_keys($config) as $k) {
            if ($k !== 'domain') {
                $config[$k] = '******';
            }
        }

        return $config;
    }
}
