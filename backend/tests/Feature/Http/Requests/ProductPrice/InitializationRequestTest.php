<?php

use App\Http\Requests\ProductPrice\InitializationRequest;
use App\Models\UserLevel;
use Illuminate\Support\Facades\Validator;

function slimmingInitializationPayload(UserLevel $level, array $overrides = []): array
{
    return array_replace([
        'levels' => [[
            'code' => $level->code,
            'cost_rate' => '1.2000',
        ]],
        'precision' => 2,
        'force' => false,
        'sync_cost_rates' => false,
        'preview' => true,
    ], $overrides);
}

function validateSlimmingInitializationPayload(array $payload): Illuminate\Validation\Validator
{
    $request = InitializationRequest::create(
        '/api/admin/product-price/initialization',
        'POST',
        $payload,
    );
    $request->setContainer(app());

    return Validator::make($payload, $request->rules());
}

beforeEach(function () {
    $this->level = UserLevel::factory()->create([
        'code' => 'slimming-request-level',
        'name' => 'Slimming Request Level',
        'cost_rate' => '1.0000',
    ]);
});

test('正式执行必须提供预览令牌', function () {
    $validator = validateSlimmingInitializationPayload(
        slimmingInitializationPayload($this->level, ['preview' => false]),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('preview_token'))->toBeTrue();
});

test('倍率必须是范围内最多四位小数的十进制字符串', function (mixed $costRate) {
    $payload = slimmingInitializationPayload($this->level);
    $payload['levels'][0]['cost_rate'] = $costRate;
    $validator = validateSlimmingInitializationPayload($payload);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('levels.0.cost_rate'))->toBeTrue();
})->with([
    '小于一' => ['0.9999'],
    '大于上限' => ['100'],
    '超过四位小数' => ['1.00001'],
    '科学计数法' => ['1e1'],
    'JSON number 整数' => [1],
    'JSON number 小数' => [1.2],
]);

test('倍率合法边界字符串可以通过请求校验', function (string $costRate) {
    $payload = slimmingInitializationPayload($this->level);
    $payload['levels'][0]['cost_rate'] = $costRate;

    expect(validateSlimmingInitializationPayload($payload)->passes())->toBeTrue();
})->with([
    '最小倍率' => ['1'],
    '最大倍率' => ['99.9999'],
]);

test('重复和不存在的级别 code 均校验失败', function (array $levels, string $field) {
    $validator = validateSlimmingInitializationPayload(
        slimmingInitializationPayload($this->level, ['levels' => $levels]),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has($field))->toBeTrue();
})->with([
    '重复 code' => [[
        ['code' => 'slimming-request-level', 'cost_rate' => '1.0000'],
        ['code' => 'slimming-request-level', 'cost_rate' => '1.2000'],
    ], 'levels.0.code'],
    '不存在 code' => [[
        ['code' => 'slimming-request-missing', 'cost_rate' => '1.0000'],
    ], 'levels.0.code'],
]);

test('非法 precision 和布尔字段均校验失败', function (array $overrides, string $field) {
    $validator = validateSlimmingInitializationPayload(
        slimmingInitializationPayload($this->level, $overrides),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has($field))->toBeTrue();
})->with([
    'precision 负数' => [['precision' => -1], 'precision'],
    'precision 大于二' => [['precision' => 3], 'precision'],
    'precision 小数' => [['precision' => 1.5], 'precision'],
    'force 任意 truthy 字符串' => [['force' => 'yes'], 'force'],
    'sync 任意 truthy 字符串' => [['sync_cost_rates' => 'on'], 'sync_cost_rates'],
    'preview 任意 truthy 字符串' => [['preview' => 'true'], 'preview'],
]);
