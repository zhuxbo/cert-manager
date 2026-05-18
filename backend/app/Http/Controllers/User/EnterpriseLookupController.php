<?php

namespace App\Http\Controllers\User;

use App\Services\EnterpriseLookup\LookupException;
use App\Services\EnterpriseLookup\LookupManager;
use Illuminate\Http\Request;

class EnterpriseLookupController extends BaseController
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
