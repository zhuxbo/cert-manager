<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Plugins\Invoice\Models\Invoice;
use Plugins\Invoice\Services\InvoiceConfig;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    InvoiceConfig::set('external_token', 'tok');
});

function makeCompleteInvoice(int $status = 0): Invoice
{
    $user = User::factory()->create();
    $invoice = Invoice::create([
        'user_id' => $user->id,
        'amount' => 100,
        'organization' => 'X',
        'email' => 'a@b.c',
        'status' => 0,
    ]);

    if ($status !== 0) {
        // creating 钩子 block status=2 直接入库；updating 钩子也 block 0→2
        // 用 DB::table 直插绕过模型钩子
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        $invoice->refresh();
    }

    return $invoice;
}

it('marks status 0 -> 1 successfully', function () {
    $inv = makeCompleteInvoice(0);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/invoice/external/complete/{$inv->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($inv->fresh()->status)->toBe(1);
});

it('is idempotent on status=1 (repeat callback returns success)', function () {
    $inv = makeCompleteInvoice(1);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/invoice/external/complete/{$inv->id}")
        ->assertOk()
        ->assertJson(['code' => 1]);

    expect($inv->fresh()->status)->toBe(1);
});

it('rejects when status=2 (cancelled)', function () {
    $inv = makeCompleteInvoice(2);

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson("/api/invoice/external/complete/{$inv->id}")
        ->assertOk()
        ->assertJson(['code' => 0, 'msg' => '发票已作废，无法标记完成']);
});

it('returns error when invoice not found', function () {
    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->postJson('/api/invoice/external/complete/999999')
        ->assertOk()
        ->assertJson(['code' => 0, 'msg' => '发票不存在']);
});
