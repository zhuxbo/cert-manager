<?php

use App\Models\Traits\HasLocalTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->originalTz = config('app.timezone');

    // 测试 fixture：一个使用 HasLocalTimezone trait 的 model
    $this->model = new class extends Model
    {
        use HasLocalTimezone;
    };
});

afterEach(function () {
    Config::set('app.timezone', $this->originalTz);
});

test('serializeDate 输出 +08:00 偏移当 app.timezone=Asia/Shanghai', function () {
    Config::set('app.timezone', 'Asia/Shanghai');

    $reflection = new ReflectionClass($this->model);
    $method = $reflection->getMethod('serializeDate');
    $method->setAccessible(true);

    $date = new DateTime('2026-05-05 13:43:56', new DateTimeZone('UTC'));
    $result = $method->invoke($this->model, $date);

    expect($result)->toContain('+08:00')
        ->and($result)->toBe('2026-05-05T21:43:56+08:00');
});

test('serializeDate 输出 +00:00 偏移当 app.timezone=UTC', function () {
    Config::set('app.timezone', 'UTC');

    $reflection = new ReflectionClass($this->model);
    $method = $reflection->getMethod('serializeDate');
    $method->setAccessible(true);

    $date = new DateTime('2026-05-05 13:43:56', new DateTimeZone('UTC'));
    $result = $method->invoke($this->model, $date);

    expect($result)->toBe('2026-05-05T13:43:56+00:00');
});

test('serializeDate 输出当地偏移当 app.timezone=America/New_York（夏令时）', function () {
    Config::set('app.timezone', 'America/New_York');

    $reflection = new ReflectionClass($this->model);
    $method = $reflection->getMethod('serializeDate');
    $method->setAccessible(true);

    // 2026-05-05 是美国夏令时（DST），UTC-4
    $date = new DateTime('2026-05-05 13:43:56', new DateTimeZone('UTC'));
    $result = $method->invoke($this->model, $date);

    expect($result)->toBe('2026-05-05T09:43:56-04:00');
});

test('serializeDate 同一 UTC 时刻在不同 app.timezone 下输出语义等价的不同字符串', function () {
    $utcMoment = new DateTime('2026-05-05 13:43:56', new DateTimeZone('UTC'));

    $reflection = new ReflectionClass($this->model);
    $method = $reflection->getMethod('serializeDate');
    $method->setAccessible(true);

    Config::set('app.timezone', 'Asia/Shanghai');
    $shanghai = $method->invoke($this->model, $utcMoment);

    Config::set('app.timezone', 'UTC');
    $utc = $method->invoke($this->model, $utcMoment);

    Config::set('app.timezone', 'Europe/London');
    $london = $method->invoke($this->model, $utcMoment);

    // 三个字符串不同，但都指向同一个 UTC 时刻
    expect($shanghai)->not->toBe($utc);
    expect($utc)->not->toBe($london);

    expect(Carbon\Carbon::parse($shanghai)->utc()->toDateTimeString())
        ->toBe(Carbon\Carbon::parse($utc)->utc()->toDateTimeString())
        ->toBe(Carbon\Carbon::parse($london)->utc()->toDateTimeString());
});
