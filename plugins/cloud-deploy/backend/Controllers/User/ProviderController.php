<?php

namespace Plugins\CloudDeploy\Controllers\User;

use App\Http\Controllers\User\BaseController;
use Plugins\CloudDeploy\Deployers\Registry;

class ProviderController extends BaseController
{
    public function index(): void
    {
        // catalog: { providers: 嵌套 schema } —— 同时驱动前端 schema-driven 表单与后端 schema 校验
        $this->success(app(Registry::class)->catalog());
    }
}
