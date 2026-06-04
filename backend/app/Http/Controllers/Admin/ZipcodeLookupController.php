<?php

namespace App\Http\Controllers\Admin;

use App\Services\ZipcodeLookup\ZipcodeLookup;
use Illuminate\Http\Request;

class ZipcodeLookupController extends BaseController
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
