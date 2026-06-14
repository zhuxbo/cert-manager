<?php

namespace App\Http\Controllers\Concerns;

use App\Services\EnterpriseLookup\LookupException;
use App\Services\EnterpriseLookup\LookupManager;
use Illuminate\Http\Request;

/**
 * 工商查询控制器共享逻辑（Admin / User 两端逐字一致）
 *
 * 使用方须 extends BaseController（提供 ApiResponse trait 的 success/error）
 */
trait HandlesEnterpriseLookup
{
    public function status(LookupManager $manager): void
    {
        $this->success(['enabled' => $manager->enabled()]);
    }

    public function lookup(Request $request, LookupManager $manager): void
    {
        $name = $request->validate(['name' => 'required|string|max:200'])['name'];

        if (! $manager->enabled()) {
            $this->error('工商查询未启用');
        }

        try {
            $data = $manager->driver()->lookup($name);
        } catch (LookupException $e) {
            $this->error($e->getMessage());
        }

        $this->success($data);
    }
}
