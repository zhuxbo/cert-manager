<?php

use App\Models\Product;
use App\Services\Notification\NotificationCenter;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Tests\Traits\CreatesTestData;

uses(CreatesTestData::class);

test('active 自动签发通知仅派发 SSL 和 S/MIME', function (string $productType, int $expectedCount) {
    $user = $this->createTestUser(['email' => "$productType@example.test"]);
    $product = $this->createTestProduct([
        'source' => 'default',
        'product_type' => $productType,
    ]);
    $order = $this->createTestOrder($user, $product);
    $this->createTestCert($order, [
        'status' => 'processing',
        'action' => 'new',
        'api_id' => "issued-$productType",
    ]);

    $api = Mockery::mock(Api::class);
    $api->shouldReceive('get')->once()->andReturn([
        'code' => 1,
        'data' => ['status' => 'active'],
    ]);
    app()->instance(Api::class, $api);

    $dispatched = [];
    $center = Mockery::mock(NotificationCenter::class);
    $center->shouldReceive('dispatch')->andReturnUsing(function ($intent) use (&$dispatched) {
        if ($intent->code === 'cert_issued') {
            $dispatched[] = $intent;
        }
    });
    app()->instance(NotificationCenter::class, $center);

    app(Action::class)->sync($order->id, true);

    expect($dispatched)->toHaveCount($expectedCount);
    if ($expectedCount === 1) {
        expect($dispatched[0]->code)->toBe('cert_issued')
            ->and($dispatched[0]->notifiableType)->toBe('user')
            ->and($dispatched[0]->notifiableId)->toBe($user->id)
            ->and($dispatched[0]->context)->toBe([
                'order_id' => $order->id,
                'email' => "$productType@example.test",
            ]);
    }
})->with([
    'SSL' => [Product::TYPE_SSL, 1],
    'S/MIME' => [Product::TYPE_SMIME, 1],
    'CodeSign' => [Product::TYPE_CODESIGN, 0],
    'DocSign' => [Product::TYPE_DOCSIGN, 0],
]);
