<?php

use App\Services\EnterpriseLookup\AliyunDriver;
use App\Services\EnterpriseLookup\LookupManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use Tests\Traits\CreatesTestData;

uses(TestCase::class, CreatesTestData::class, RefreshDatabase::class)->group('database');

beforeEach(function () {
    $this->seed = true;
    $this->seeder = DatabaseSeeder::class;
    setEnterpriseLookupSetting('url', 'https://example.com/x');
    setEnterpriseLookupSetting('appCode', 'AC', 'base64');
    setEnterpriseLookupSetting('queryField', 'name');
    setEnterpriseLookupSetting('fieldMap', [
        'name' => 'data.companyName',
        'registration_number' => 'data.creditCode',
        'address' => 'data.regAddress',
    ], 'array');
    Cache::flush();
});

test('enabled returns true when all required settings are configured', function () {
    expect(app(LookupManager::class)->enabled())->toBeTrue();
});

test('enabled returns false when url is empty', function () {
    setEnterpriseLookupSetting('url', '');
    expect(app(LookupManager::class)->enabled())->toBeFalse();
});

test('enabled returns false when appCode is empty', function () {
    setEnterpriseLookupSetting('appCode', '', 'base64');
    expect(app(LookupManager::class)->enabled())->toBeFalse();
});

test('enabled returns false when queryField is empty', function () {
    setEnterpriseLookupSetting('queryField', '');
    expect(app(LookupManager::class)->enabled())->toBeFalse();
});

test('enabled returns false when fieldMap.name source is missing', function () {
    setEnterpriseLookupSetting('fieldMap', [
        'registration_number' => 'data.creditCode',
        'address' => 'data.regAddress',
    ], 'array');
    expect(app(LookupManager::class)->enabled())->toBeFalse();
});

test('enabled returns false when fieldMap.registration_number source is empty string', function () {
    setEnterpriseLookupSetting('fieldMap', [
        'name' => 'data.companyName',
        'registration_number' => '',
        'address' => 'data.regAddress',
    ], 'array');
    expect(app(LookupManager::class)->enabled())->toBeFalse();
});

test('enabled returns false when fieldMap.address source is missing', function () {
    setEnterpriseLookupSetting('fieldMap', [
        'name' => 'data.companyName',
        'registration_number' => 'data.creditCode',
    ], 'array');
    expect(app(LookupManager::class)->enabled())->toBeFalse();
});

test('driver always returns AliyunDriver', function () {
    expect(app(LookupManager::class)->driver())
        ->toBeInstanceOf(AliyunDriver::class);
});
