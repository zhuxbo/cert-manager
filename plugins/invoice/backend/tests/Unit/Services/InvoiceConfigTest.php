<?php

use Illuminate\Support\Facades\Storage;
use Plugins\Invoice\Services\InvoiceConfig;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Storage::fake('local');
    InvoiceConfig::resetCache();
});

it('returns default when file missing', function () {
    expect(InvoiceConfig::get('external_token'))->toBeNull();
    expect(InvoiceConfig::get('external_allowed_ips', '127.0.0.1'))->toBe('127.0.0.1');
});

it('encrypts whitelisted key and decrypts on read', function () {
    InvoiceConfig::set('external_token', 'plain-token-value');

    $raw = json_decode(Storage::disk('local')->get('private/invoice-external.json'), true);
    expect($raw['external_token'])->not->toBe('plain-token-value');

    expect(InvoiceConfig::get('external_token'))->toBe('plain-token-value');
});

it('keeps non-encrypted key plain in file', function () {
    InvoiceConfig::set('external_allowed_ips', '1.2.3.4,5.6.7.8');

    $raw = json_decode(Storage::disk('local')->get('private/invoice-external.json'), true);
    expect($raw['external_allowed_ips'])->toBe('1.2.3.4,5.6.7.8');
    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('1.2.3.4,5.6.7.8');
});

it('preserves existing keys when setting another', function () {
    InvoiceConfig::set('external_token', 'token1');
    InvoiceConfig::set('external_allowed_ips', '1.2.3.4');

    expect(InvoiceConfig::get('external_token'))->toBe('token1');
    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('1.2.3.4');
});

it('memoizes reads within the process and does not re-read disk', function () {
    InvoiceConfig::resetCache();
    InvoiceConfig::set('external_allowed_ips', '1.2.3.4');

    // 首次读取触发读盘并缓存
    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('1.2.3.4');

    // 绕过 InvoiceConfig 直接改写底层文件
    Storage::disk('local')->put(
        'private/invoice-external.json',
        json_encode(['external_allowed_ips' => '9.9.9.9'])
    );

    // 命中 memo，仍返回旧值（未再读盘）
    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('1.2.3.4');
});

it('resetCache forces a fresh disk read', function () {
    InvoiceConfig::resetCache();
    InvoiceConfig::set('external_allowed_ips', '1.2.3.4');
    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('1.2.3.4');

    Storage::disk('local')->put(
        'private/invoice-external.json',
        json_encode(['external_allowed_ips' => '9.9.9.9'])
    );

    InvoiceConfig::resetCache();
    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('9.9.9.9');
});

it('set invalidates the memoized cache', function () {
    InvoiceConfig::resetCache();
    InvoiceConfig::set('external_allowed_ips', '1.2.3.4');
    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('1.2.3.4');

    InvoiceConfig::set('external_allowed_ips', '5.6.7.8');
    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('5.6.7.8');
});
