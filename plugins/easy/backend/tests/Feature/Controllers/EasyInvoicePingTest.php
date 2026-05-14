<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns success when invoice plugin installed', function () {
    // 此测试运行时 Plugins\Invoice\Models\Invoice 类必然存在（monorepo 内同源）
    $this->getJson('/api/easy/invoice/ping')
        ->assertOk()
        ->assertJson(['code' => 1]);
});
