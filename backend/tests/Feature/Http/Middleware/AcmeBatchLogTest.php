<?php

use App\Models\Acme;
use App\Models\ApiLog;
use App\Models\Product;
use App\Models\User;
use App\Models\UserLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Traits\ActsAsUser;

uses(ActsAsUser::class);
uses(RefreshDatabase::class);

test('user 端 /api/acme/batch 日志归 UserLog 且 user_id 非空（修 batch 日志 user_id 为空）', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['product_type' => Product::TYPE_ACME, 'source' => 'default']);
    $acme = Acme::factory()->create(['user_id' => $user->id, 'product_id' => $product->id]);

    $this->actingAsUser($user)
        ->getJson("/api/acme/batch?ids=$acme->id")
        ->assertOk();

    // FlushLogs::terminate 由 Laravel Test Kernel 在 getJson() 返回前同步调用，LogBuffer 已落库，可直接断言 DB
    $userLog = UserLog::query()->where('url', 'like', '%/api/acme/batch%')->first();
    expect($userLog)->not->toBeNull();
    expect($userLog->user_id)->toBe($user->id);

    // 不再被误记成对外 API 日志
    expect(ApiLog::query()->where('url', 'like', '%/api/acme/batch%')->exists())->toBeFalse();
});
