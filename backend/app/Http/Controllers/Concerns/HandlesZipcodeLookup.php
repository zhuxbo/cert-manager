<?php

namespace App\Http\Controllers\Concerns;

use App\Services\ZipcodeLookup\ZipcodeLookup;
use Illuminate\Http\Request;

/**
 * 邮编查询控制器共享逻辑（Admin / User 两端逐字一致）
 *
 * 使用方须 extends BaseController（提供 ApiResponse trait 的 success/error）
 */
trait HandlesZipcodeLookup
{
    public function lookup(Request $request, ZipcodeLookup $service): void
    {
        $params = $request->validate([
            'regionname' => 'required|string|max:100',
            'name' => 'nullable|string|max:200',
        ]);

        $result = $service->find($params['regionname'], $params['name'] ?? null);

        if ($result === null) {
            $this->error('未匹配到邮编');
        }

        $this->success($result);
    }
}
