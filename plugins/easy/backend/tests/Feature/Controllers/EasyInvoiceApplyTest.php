<?php

use App\Models\Fund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Easy\Models\Agiso;
use Plugins\Invoice\Models\Invoice;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function applyPrep(): array
{
    $user = User::factory()->create(['email' => 'u@example.com']);
    Agiso::create([
        'tid' => 'tid-1',
        'pay_method' => 'taobao',
        'product_code' => 'test',
        'period' => 1,
        'price' => '0.00',
        'amount' => '0.00',
        'count' => 1,
        'user_id' => $user->id,
        'recharged' => 1,
    ]);
    Fund::create([
        'user_id' => $user->id, 'amount' => 200, 'type' => 'addfunds',
        'pay_method' => 'alipay', 'pay_sn' => 's', 'status' => 1,
    ]);

    return [$user];
}

it('rejects amount exceeding quota', function () {
    applyPrep();

    $this->postJson('/api/easy/invoice/apply', [
        'tid' => 'tid-1', 'email' => 'u@example.com',
        'amount' => 999, 'organization' => 'ACME', 'taxation' => '12345',
    ])->assertOk()->assertJson(['code' => 0]);

    expect(Invoice::count())->toBe(0);
});

it('rejects when taxation missing', function () {
    applyPrep();

    $this->postJson('/api/easy/invoice/apply', [
        'tid' => 'tid-1', 'email' => 'u@example.com',
        'amount' => 100, 'organization' => 'ACME',
    ])->assertOk()->assertJson(['code' => 0]);

    expect(Invoice::count())->toBe(0);
});

it('creates invoice with status=0 and delivery email = user.email', function () {
    [$user] = applyPrep();

    $this->postJson('/api/easy/invoice/apply', [
        'tid' => 'tid-1', 'email' => 'u@example.com',
        'amount' => 100, 'organization' => 'ACME Co.',
        'taxation' => '12345', 'remark' => 'test',
    ])->assertOk()->assertJson(['code' => 1]);

    $inv = Invoice::firstOrFail();
    expect($inv->user_id)->toBe($user->id);
    expect((string) $inv->amount)->toBe('100.00');
    expect($inv->organization)->toBe('ACME Co.');
    expect($inv->taxation)->toBe('12345');
    expect($inv->email)->toBe('u@example.com');
    expect($inv->status)->toBe(0);
});

it('validates required fields', function () {
    applyPrep();

    $this->postJson('/api/easy/invoice/apply', [
        'tid' => 'tid-1', 'email' => 'u@example.com',
    ])->assertOk()->assertJson(['code' => 0]);
});

it('rejects when tid+email mismatched', function () {
    applyPrep();

    $this->postJson('/api/easy/invoice/apply', [
        'tid' => 'tid-1', 'email' => 'wrong@e.com',
        'amount' => 50, 'organization' => 'X',
    ])->assertOk()->assertJson(['code' => 0]);
});
