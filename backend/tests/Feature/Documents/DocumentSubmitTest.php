<?php

use App\Exceptions\ApiResponseException;
use App\Jobs\SubmitDocumentJob;
use App\Models\OrderDocument;
use App\Services\Order\Action;
use App\Services\Order\Api\Api;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Traits\CreatesTestData;

uses(RefreshDatabase::class, CreatesTestData::class);

/** 用 mock 的上游 Api 构造一个真实 Action（绕过 source 路由，直接断言 uploadDocument 调用） */
function actionWithApi(mixed $apiMock): Action
{
    app()->instance(Api::class, $apiMock);

    return app(Action::class);
}

/** 在真实磁盘写一个文档文件，返回相对路径（trait 用 storage_path + 原生 fs，不走 Storage 门面） */
function writeDocFile(string $content = 'PDFDATA'): string
{
    $rel = 'verification/test/'.Str::uuid().'.pdf';
    $full = storage_path("app/$rel");
    @mkdir(dirname($full), 0755, true);
    file_put_contents($full, $content);

    return $rel;
}

/** 捕获 ApiResponse 异常（success/error 均抛 ApiResponseException） */
function captureDocResponse(callable $cb): array
{
    try {
        $cb();
        test()->fail('Expected ApiResponseException but none was thrown.');
    } catch (ApiResponseException $e) {
        return $e->getApiResponse();
    }
}

/** 仅封装 OrderDocument::create（公有），fixture 的 order/cert 由测试闭包内 $this 建 */
function newDoc(int $orderId, int $userId, array $overrides = []): OrderDocument
{
    return OrderDocument::create(array_merge([
        'order_id' => $orderId,
        'user_id' => $userId,
        'type' => 'APPLICANT',
        'file_name' => 'a.pdf',
        'file_path' => 'verification/test/'.Str::uuid().'.pdf',
        'file_size' => 7,
        'content_hash' => hash('sha256', (string) Str::uuid()),
        'uploaded_by' => 'api',
        'submitted' => 0,
    ], $overrides));
}

test('submitDocument 成功标记 submitted + submitted_at，清空 error', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);
    $rel = writeDocFile();
    $doc = newDoc($order->id, $user->id, ['file_path' => $rel]);

    $api = Mockery::mock(Api::class);
    $api->shouldReceive('uploadDocument')->once()->andReturn(['code' => 1]);

    actionWithApi($api)->submitDocument($doc->id);

    $doc->refresh();
    expect($doc->submitted)->toBeTrue()
        ->and($doc->submitted_at)->not->toBeNull()
        ->and($doc->submit_attempts)->toBe(1)
        ->and($doc->submit_error)->toBeNull();

    @unlink(storage_path("app/$rel"));
});

test('submitDocument 上游失败抛异常 + 记录 submit_error（触发 Job 重试）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);
    $rel = writeDocFile();
    $doc = newDoc($order->id, $user->id, ['file_path' => $rel]);

    $api = Mockery::mock(Api::class);
    $api->shouldReceive('uploadDocument')->once()->andReturn(['code' => 0, 'msg' => '上游拒绝']);

    expect(fn () => actionWithApi($api)->submitDocument($doc->id))
        ->toThrow(RuntimeException::class, '上游拒绝');

    $doc->refresh();
    expect($doc->submitted)->toBeFalse()
        ->and($doc->submit_error)->toBe('上游拒绝')
        ->and($doc->submit_attempts)->toBe(1);

    @unlink(storage_path("app/$rel"));
});

test('submitDocument 文件缺失为永久失败：不抛异常、不调上游、记录原因', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);
    $doc = newDoc($order->id, $user->id, ['file_path' => 'verification/test/does-not-exist.pdf']);

    $api = Mockery::mock(Api::class);
    $api->shouldNotReceive('uploadDocument');

    actionWithApi($api)->submitDocument($doc->id); // 不抛异常

    $doc->refresh();
    expect($doc->submitted)->toBeFalse()
        ->and($doc->submit_error)->toBe('文件不存在');
});

test('submitDocument 已提交则幂等跳过（不调上游）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);
    $doc = newDoc($order->id, $user->id, ['submitted' => 1]);

    $api = Mockery::mock(Api::class);
    $api->shouldNotReceive('uploadDocument');

    actionWithApi($api)->submitDocument($doc->id);

    expect($doc->fresh()->submitted)->toBeTrue();
});

test('submitDocument 订单未提交上游（无 api_id）时抛异常可重试', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => null, 'status' => 'pending']);
    $rel = writeDocFile();
    $doc = newDoc($order->id, $user->id, ['file_path' => $rel]);

    $api = Mockery::mock(Api::class);
    $api->shouldNotReceive('uploadDocument');

    expect(fn () => actionWithApi($api)->submitDocument($doc->id))
        ->toThrow(RuntimeException::class, '订单尚未提交');

    expect($doc->fresh()->submit_error)->toBe('订单尚未提交');

    @unlink(storage_path("app/$rel"));
});

test('submitDocuments 为每个未提交文档派发 SubmitDocumentJob（afterCommit）', function () {
    Queue::fake();

    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);

    newDoc($order->id, $user->id, ['type' => 'APPLICANT', 'content_hash' => hash('sha256', 'c0')]);
    newDoc($order->id, $user->id, ['type' => 'ORGANIZATION', 'content_hash' => hash('sha256', 'c1')]);
    // 已提交的不再入队
    newDoc($order->id, $user->id, ['type' => 'ADDITIONAL', 'content_hash' => hash('sha256', 'done'), 'submitted' => 1]);

    $res = captureDocResponse(fn () => app(Action::class)->submitDocuments($order->id));

    expect($res['code'])->toBe(1)->and($res['data']['queued'])->toBe(2);
    Queue::assertPushed(SubmitDocumentJob::class, 2);
    // 必须进 tasks 队列（worker 监听 tasks）；漏 onQueue 会落到 default 无人消费 → 不上传上游
    Queue::assertPushedOn(config('queue.names.tasks'), SubmitDocumentJob::class);
});

test('uploadDocumentFromBase64 相同内容去重：任意 type 同字节只存一行', function () {
    Queue::fake(); // 吞掉自动转发 Job

    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);

    $b64 = base64_encode('SAME-BYTES');
    $action = app(Action::class);

    captureDocResponse(fn () => $action->uploadDocumentFromBase64($order->id, 'APPLICANT', 'a.pdf', $b64));
    // 同字节、不同 type、不同文件名 → 仍去重
    captureDocResponse(fn () => $action->uploadDocumentFromBase64($order->id, 'ORGANIZATION', 'b.pdf', $b64));

    expect(OrderDocument::where('order_id', $order->id)->count())->toBe(1);

    $doc = OrderDocument::where('order_id', $order->id)->first();
    expect($doc->content_hash)->toBe(hash('sha256', 'SAME-BYTES'));
    @unlink(storage_path("app/$doc->file_path"));
});

test('uploadDocumentFromBase64 收到下游文档后自动派发转发 Job', function () {
    Queue::fake();

    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);

    captureDocResponse(fn () => app(Action::class)->uploadDocumentFromBase64(
        $order->id, 'APPLICANT', 'a.pdf', base64_encode('FORWARD-ME')
    ));

    Queue::assertPushed(SubmitDocumentJob::class, 1);

    $doc = OrderDocument::where('order_id', $order->id)->first();
    @unlink(storage_path("app/$doc->file_path"));
});

test('uploadDocument 文件上传：设置 content_hash + 按内容去重 + 自动转发上游', function () {
    Queue::fake(); // uploadDocument 现在也自动转发，吞掉 Job 并断言

    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);

    $f1 = UploadedFile::fake()->createWithContent('a.pdf', 'SAME-FILE-BYTES');
    $f2 = UploadedFile::fake()->createWithContent('b.pdf', 'SAME-FILE-BYTES');

    captureDocResponse(fn () => app(Action::class)->uploadDocument($order->id, $f1, 'APPLICANT', 'user'));
    // 同字节、不同 type/文件名 → 去重为一份
    captureDocResponse(fn () => app(Action::class)->uploadDocument($order->id, $f2, 'ORGANIZATION', 'user'));

    $docs = OrderDocument::where('order_id', $order->id)->get();
    expect($docs)->toHaveCount(1)
        ->and($docs->first()->content_hash)->toBe(hash('sha256', 'SAME-FILE-BYTES'));

    // 首次创建转发 + 第二次去重命中（未提交）补转 → 共 2 次
    Queue::assertPushed(SubmitDocumentJob::class, 2);

    @unlink(storage_path("app/{$docs->first()->file_path}"));
});

test('submitDocument 上游调用抛异常时也记录 submit_error（observability）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP123', 'status' => 'active']);
    $rel = writeDocFile();
    $doc = newDoc($order->id, $user->id, ['file_path' => $rel]);

    $api = Mockery::mock(Api::class);
    $api->shouldReceive('uploadDocument')->once()->andThrow(new RuntimeException('上游炸了'));

    expect(fn () => actionWithApi($api)->submitDocument($doc->id))
        ->toThrow(RuntimeException::class, '上游炸了');

    $doc->refresh();
    expect($doc->submit_error)->toBe('上游炸了')
        ->and($doc->submit_attempts)->toBe(1);

    @unlink(storage_path("app/$rel"));
});
