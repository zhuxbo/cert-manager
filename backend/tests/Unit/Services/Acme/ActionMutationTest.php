<?php

use App\Models\Acme;
use App\Models\Product;
use App\Services\Acme\Action;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('resolveDomainCounts 精确区分标准、通配符和混合额度', function (
    int|string|null $standardMax,
    int|string|null $wildcardMax,
    array $expected
) {
    $product = new Product([
        'standard_max' => $standardMax,
        'wildcard_max' => $wildcardMax,
    ]);
    $method = new ReflectionMethod(Action::class, 'resolveDomainCounts');

    expect($method->invoke(app(Action::class), $product))->toBe($expected);
})->with([
    '标准产品' => [1, 0, [1, 0]],
    '通配符产品' => [0, 1, [0, 1]],
    '混合额度按标准产品处理' => [1, 1, [1, 0]],
    '未配置额度按标准产品处理' => [0, 0, [1, 0]],
    '标准额度为 null 时按通配符处理' => [null, 1, [0, 1]],
    '通配符额度为 null 时按标准产品处理' => [1, null, [1, 0]],
    '标准额度为零且通配符为 null 时按标准产品处理' => [0, null, [1, 0]],
    '字符串零标准额度按通配符处理' => ['0', '1', [0, 1]],
    '字符串一标准额度按标准产品处理' => ['1', '0', [1, 0]],
]);

test('normalizeCa 同时去除两端空白并转为小写', function (
    string $input,
    string $expected
) {
    expect(app(Action::class)->normalizeCa($input))->toBe($expected);
})->with([
    '大写及两端空白' => ['  SeCTigo  ', 'sectigo'],
    '内部字符保持' => ['SSL_TrUsT', 'ssl_trust'],
    '已规范化' => ['letsencrypt', 'letsencrypt'],
]);

function mutationProbeAcme(?string $ca, ?string $apiId = null): Acme
{
    $acme = new Acme(['api_id' => $apiId]);
    $acme->setRelation('product', new Product(['ca' => $ca]));

    return $acme;
}

test('syncDirectoryUrl 在 CA 为空时直接返回 null', function (?string $ca) {
    Cache::shouldReceive('get')->never();

    expect(app(Action::class)->syncDirectoryUrl(mutationProbeAcme($ca)))
        ->toBeNull();
})->with([
    'null' => null,
    '空白字符串' => '  ',
]);

test('syncDirectoryUrl 命中规范化 CA 缓存并返回字符串', function () {
    Cache::put('acme_directory_url:sectigo', 12345);

    expect(app(Action::class)->syncDirectoryUrl(
        mutationProbeAcme('  SeCTigo  ', 'upstream-id')
    ))->toBe('12345');
});

test('syncDirectoryUrl 缓存缺失且无上游 id 时返回 null', function () {
    $service = new class extends Action
    {
        public bool $syncCalled = false;

        public function sync(int $acmeId, bool $force = false): void
        {
            $this->syncCalled = true;
        }
    };
    $acme = mutationProbeAcme('sectigo');
    $acme->id = 123;

    expect($service->syncDirectoryUrl($acme))->toBeNull()
        ->and($service->syncCalled)->toBeFalse();
});

test('cacheDirectoryUrl 忽略空 CA 或空 URL', function (
    string $ca,
    ?string $url
) {
    Cache::shouldReceive('put')->never();

    $method = new ReflectionMethod(Action::class, 'cacheDirectoryUrl');
    $method->invoke(app(Action::class), $ca, $url);
})->with([
    '空 CA' => ['', 'https://acme.example.test/directory'],
    '空 URL' => ['sectigo', ''],
    'null URL' => ['sectigo', null],
]);

test('cacheDirectoryUrl 使用规范化 key 并精确缓存 30 天', function () {
    Carbon::setTestNow('2026-07-31 12:00:00');
    $method = new ReflectionMethod(Action::class, 'cacheDirectoryUrl');
    $method->invoke(
        app(Action::class),
        '  SeCTigo  ',
        'https://acme.example.test/directory'
    );

    expect(Cache::get('acme_directory_url:sectigo'))
        ->toBe('https://acme.example.test/directory');

    Carbon::setTestNow(now()->addDays(29)->addHours(23));
    expect(Cache::has('acme_directory_url:sectigo'))->toBeTrue();

    Carbon::setTestNow(now()->addHours(2));
    expect(Cache::has('acme_directory_url:sectigo'))->toBeFalse();
});
