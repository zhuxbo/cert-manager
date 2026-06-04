<?php

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Plugins\Invoice\Services\InvoiceConfig;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

beforeEach(function () {
    Storage::fake('local');
    $this->admin = Admin::factory()->create();
});

test('returns has_token=false when no token set', function () {
    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/invoice/external-config')
        ->assertOk()
        ->assertJson(['code' => 1]);
    expect($res->json('data.has_token'))->toBeFalse();
    expect($res->json('data.token_masked'))->toBe('');
});

test('returns masked token after token set', function () {
    InvoiceConfig::set('external_token', 'abcd-something-long-xyz9');

    $res = $this->actingAsAdmin($this->admin)
        ->getJson('/api/admin/invoice/external-config');
    expect($res->json('data.has_token'))->toBeTrue();
    expect($res->json('data.token_masked'))->toMatch('/^abcd.*xyz9$/');
});

test('updates allowed_ips but not token', function () {
    InvoiceConfig::set('external_token', 'keep-me');

    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/invoice/external-config', ['allowed_ips' => '1.2.3.4,5.6.7.8'])
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(InvoiceConfig::get('external_allowed_ips'))->toBe('1.2.3.4,5.6.7.8');
    expect(InvoiceConfig::get('external_token'))->toBe('keep-me');
});

test('regenerates token and returns plaintext once', function () {
    $res = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/invoice/external-config/regenerate-token')
        ->assertOk()
        ->assertJson(['code' => 1]);

    $plain = $res->json('data.token');
    expect($plain)->toBeString()->toHaveLength(32);
    expect(InvoiceConfig::get('external_token'))->toBe($plain);
});
