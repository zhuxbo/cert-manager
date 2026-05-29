<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Invoice\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\ActsAsAdmin;

uses(TestCase::class, RefreshDatabase::class, ActsAsAdmin::class);

beforeEach(function () {
    $this->admin = Admin::factory()->create();
    $this->user = User::factory()->create();
    $this->payload = [
        'user_id' => $this->user->id,
        'amount' => 100,
        'organization' => '测试科技有限公司',
        'taxation' => '91110000MA01234567',
        'email' => 'invoice@example.com',
    ];
});

test('it rejects creating invoice without taxation', function () {
    $payload = $this->payload;
    unset($payload['taxation']);

    $res = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/invoice', $payload)
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect($res->json('errors'))->toHaveKey('taxation');
    expect(Invoice::count())->toBe(0);
});

test('it rejects creating invoice with blank taxation', function () {
    $payload = $this->payload;
    $payload['taxation'] = '';

    $res = $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/invoice', $payload)
        ->assertOk()
        ->assertJson(['code' => 0]);

    expect($res->json('errors'))->toHaveKey('taxation');
    expect(Invoice::count())->toBe(0);
});

test('it creates invoice when taxation provided', function () {
    $this->actingAsAdmin($this->admin)
        ->postJson('/api/admin/invoice', $this->payload)
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect(
        Invoice::where('user_id', $this->user->id)
            ->where('taxation', '91110000MA01234567')
            ->exists()
    )->toBeTrue();
});
