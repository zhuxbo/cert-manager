<?php

namespace Plugins\CloudDeploy\Controllers\User;

use App\Http\Controllers\User\BaseController;
use App\Models\Scopes\UserScope;
use Plugins\CloudDeploy\Models\CloudDeployTarget;
use Plugins\CloudDeploy\Requests\DeployRequest;
use Plugins\CloudDeploy\Services\DeployService;

class DeployController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        if ($this->guard->id()) {
            // 给 CloudDeployTarget 注册本地 UserScope；DeployService(crossUser=false) 复用之收敛本人。
            // Order 的 UserScope 另由 api.user 中间件进程级注册（DeployService order_id 预检依赖之）。
            CloudDeployTarget::addGlobalScope(new UserScope($this->guard->id()));
        }
    }

    public function deploy(DeployRequest $request): void
    {
        $validated = $request->validated();
        $dispatched = app(DeployService::class)->deploy(
            isset($validated['order_id']) ? (int) $validated['order_id'] : null,
            $validated['target_ids'] ?? [],
            (bool) ($validated['force'] ?? false),
            false,
        );

        $this->success(['dispatched' => $dispatched]);
    }
}
