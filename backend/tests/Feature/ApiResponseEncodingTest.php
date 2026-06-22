<?php

use App\Exceptions\ApiResponseException;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// API JSON 响应统一不转义中文（\uXXXX → UTF-8）；客户端 JSON 解析两者等价，但日志 / 文档 try-it 可读。
// 两条编码路径互相独立，分别覆盖：
//   1. ApiResponseException(extends HttpResponseException) —— success()/error()/response() 的唯一载体，
//      绝大多数 API 响应走这里。真实 HTTP 下其 response 被 Laravel 直接返回（不经 ApiExceptions 的
//      render callback），编码在异常构造时设定（见 App\Exceptions\ApiResponseException）。
//   2. 非 HttpResponseException（ValidationException 等）—— 走 ApiExceptions 的 render callback。
// 断言策略：中文若被转义成 \uXXXX，raw content 不含该 UTF-8 串，toContain 即证未转义。
// 切忌用 tinker / 直调 Handler::render 验证——那会强行进入 callback、掩盖主路径绕过问题（已踩坑）。
// 注：AuthenticationException 路径不单独测 —— 本系统 Authenticate 中间件抛 'Unauthorized'（英文），
//     不产生中文（"未登录或登录已过期" 回落仅在 getMessage 为空时触发，近乎 dead code），
//     无法验证中文编码；其 callback 机制已由下面 ValidationException 用例覆盖。

test('ApiResponseException 业务错误中文不转义（HttpResponseException 主路径，绕过 callback）', function () {
    // success()/error()/response() 都抛 ApiResponseException，是绝大多数 API 响应的实际路径。
    // 必须经真实 HTTP（postJson 走完整 kernel pipeline），复现"绕过 render callback、由构造设编码"。
    Route::post('/__test__/api-response-exception-cn', function () {
        throw new ApiResponseException('账号或密码错误');
    });

    $response = $this->postJson('/__test__/api-response-exception-cn');

    expect($response->getContent())->toContain('账号或密码错误');
    expect($response->json('code'))->toBe(0)
        ->and($response->json('msg'))->toBe('账号或密码错误');
});

test('ValidationException 响应中文（提交数据验证失败）不转义为 unicode', function () {
    $user = User::factory()->create(['balance' => '1000.00']);
    $rawToken = Str::random(64);
    ApiToken::factory()->create(['user_id' => $user->id, 'token' => $rawToken, 'status' => 1]);

    // 缺 product_code / contact_email → ValidationException（走 render callback）
    $response = $this->withHeaders(['Authorization' => "Bearer $rawToken"])
        ->postJson('/api/v2/acme/new', []);

    expect($response->getContent())->toContain('提交数据验证失败');
    expect($response->json('code'))->toBe(0)
        ->and($response->json('msg'))->toBe('提交数据验证失败');
});
