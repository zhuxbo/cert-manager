<?php

use App\Models\Admin;
use App\Models\OrderDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Traits\ActsAsAdmin;
use Tests\Traits\ActsAsUser;
use Tests\Traits\CreatesTestData;

uses(RefreshDatabase::class, ActsAsUser::class, ActsAsAdmin::class, CreatesTestData::class);

/** 造一个带真实磁盘文件的 OrderDocument，返回 [doc, relativePath] */
function seedPreviewDoc(int $orderId, int $userId, string $content = 'PDFDATA', string $ext = 'pdf'): array
{
    $rel = "verification/$orderId/".Str::uuid().".$ext";
    $full = storage_path("app/$rel");
    @mkdir(dirname($full), 0755, true);
    file_put_contents($full, $content);

    $doc = OrderDocument::create([
        'order_id' => $orderId,
        'user_id' => $userId,
        'type' => 'APPLICANT',
        'file_name' => "a.$ext",
        'file_path' => $rel,
        'file_size' => strlen($content),
        'content_hash' => hash('sha256', $content),
        'uploaded_by' => 'user',
    ]);

    return [$doc, $rel];
}

test('无 JWT 携带有效签名可访问 document-preview（withoutMiddleware 真正移除了 api.user）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id, 'PDFDATA');

    // 不 actingAsUser（无 JWT），直接用后端签名 URL 访问：若 api.user 未被移除会 401，移除则仅 signed 验证 → 200
    $url = URL::temporarySignedRoute('user.order.document-preview', now()->addMinutes(10), ['id' => $doc->id]);
    $resp = $this->get($url);

    // 必须真正吐文件流，而非构造函数 guard 拦截后的 {"code":0,"msg":"用户不存在"}（那也是 200，会假绿）
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('application/pdf');
    // 修复后 PDF 走 inline 内联预览（曾因安全加固误设 attachment 导致 iframe 触发下载）
    expect($resp->headers->get('content-disposition'))->toContain('inline');

    @unlink(storage_path("app/$rel"));
});

test('document-preview 无签名访问被拒绝（signed 中间件拦截，非 401）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id);

    // 无签名、无 JWT：若 api.user 仍在会返回 401；withoutMiddleware 生效后由 signed 返回 403
    $this->get("/api/order/document-preview/$doc->id")->assertForbidden();

    @unlink(storage_path("app/$rel"));
});

test('篡改签名 URL 的 docId 被拒绝（签名失效）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc1, $rel1] = seedPreviewDoc($order->id, $user->id, 'DOC-ONE');
    [$doc2, $rel2] = seedPreviewDoc($order->id, $user->id, 'DOC-TWO');

    $url = URL::temporarySignedRoute('user.order.document-preview', now()->addMinutes(10), ['id' => $doc1->id]);
    // 把路径里的 doc1 id 换成 doc2 → 与原签名不匹配
    $tampered = str_replace("document-preview/$doc1->id?", "document-preview/$doc2->id?", $url);
    $this->get($tampered)->assertForbidden();

    @unlink(storage_path("app/$rel1"));
    @unlink(storage_path("app/$rel2"));
});

test('过期的签名 URL 被拒绝', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id);

    // 过期时间设在过去 → 签名立即失效
    $url = URL::temporarySignedRoute('user.order.document-preview', now()->subMinutes(1), ['id' => $doc->id]);
    $this->get($url)->assertForbidden();

    @unlink(storage_path("app/$rel"));
});

test('previewDocumentUrl 返回短时签名 URL，且不含 access_token', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id);

    $res = $this->actingAsUser($user)->getJson("/api/order/document-preview-url/$doc->id");
    $res->assertOk()->assertJson(['code' => 1]);

    $url = $res->json('data.url');
    expect($url)->toContain('signature=')      // 是签名 URL
        ->and($url)->toContain('expires=')      // 有过期时间
        ->and($url)->not->toContain('token=');  // access_token 绝不进 URL

    // 取到的签名 URL 可直接访问
    $this->get($url)->assertOk();

    @unlink(storage_path("app/$rel"));
});

test('user 不能为他人文档取签名 URL（UserScope 归属校验）', function () {
    $owner = $this->createTestUser();
    $order = $this->createTestOrder($owner, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $owner->id);

    $other = $this->createTestUser();
    $res = $this->actingAsUser($other)->getJson("/api/order/document-preview-url/$doc->id");

    $res->assertOk()->assertJson(['code' => 0, 'msg' => '文档不存在']);

    @unlink(storage_path("app/$rel"));
});

test('admin 无 JWT 携带有效签名可访问 admin document-preview', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id);

    // admin 路由同样 withoutMiddleware(api.admin) + signed
    $url = URL::temporarySignedRoute('admin.order.document-preview', now()->addMinutes(10), ['id' => $doc->id]);
    $this->get($url)->assertOk();

    @unlink(storage_path("app/$rel"));
});

test('admin previewDocumentUrl 可为任意用户文档取签名 URL（admin 全局无 UserScope）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id);

    $admin = Admin::factory()->create();
    $res = $this->actingAsAdmin($admin)->getJson("/api/admin/order/document-preview-url/$doc->id");
    $res->assertOk()->assertJson(['code' => 1]);
    expect($res->json('data.url'))->toContain('signature=')
        ->and($res->json('data.url'))->not->toContain('token=');

    @unlink(storage_path("app/$rel"));
});

// ----- Content-Disposition 预览策略：图片/PDF 内联，其余下载 -----

test('pdf 预览走 inline + CSP sandbox（浏览器内联预览而非下载）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id, 'PDF-INLINE', 'pdf');

    $url = URL::temporarySignedRoute('user.order.document-preview', now()->addMinutes(10), ['id' => $doc->id]);
    $resp = $this->get($url);

    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('application/pdf');
    // inline 让浏览器内置 PDF 查看器预览，而非 attachment 触发下载
    expect($resp->headers->get('content-disposition'))->toContain('inline')
        ->and($resp->headers->get('content-disposition'))->not->toContain('attachment');
    // 双保险：nosniff 关 MIME 嗅探 + CSP sandbox 禁 PDF 内嵌脚本/表单/跳转
    expect($resp->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($resp->headers->get('content-security-policy'))->toContain('sandbox');

    @unlink(storage_path("app/$rel"));
});

test('图片预览走 inline', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id, 'PNG-DATA', 'png');

    $url = URL::temporarySignedRoute('user.order.document-preview', now()->addMinutes(10), ['id' => $doc->id]);
    $resp = $this->get($url);

    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('image/png');
    expect($resp->headers->get('content-disposition'))->toContain('inline');

    @unlink(storage_path("app/$rel"));
});

test('xades 等非图片非 pdf 仍走 attachment 下载（防可渲染内容钓鱼/XSS）', function () {
    $user = $this->createTestUser();
    $order = $this->createTestOrder($user, $this->createTestProduct());
    $this->createTestCert($order, ['api_id' => 'UP1', 'status' => 'processing']);
    [$doc, $rel] = seedPreviewDoc($order->id, $user->id, '<xml/>', 'xades');

    $url = URL::temporarySignedRoute('user.order.document-preview', now()->addMinutes(10), ['id' => $doc->id]);
    $resp = $this->get($url);

    $resp->assertOk();
    expect($resp->headers->get('content-disposition'))->toContain('attachment')
        ->and($resp->headers->get('content-disposition'))->not->toContain('inline');
    // 非内联类型不挂 CSP（无意义）
    expect($resp->headers->get('content-security-policy'))->toBeNull();

    @unlink(storage_path("app/$rel"));
});
