<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Plugins\Invoice\Models\Invoice;
use Plugins\Invoice\Services\InvoiceConfig;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    InvoiceConfig::set('external_token', 'tok');
});

function makePendingInvoice(array $attrs = []): Invoice
{
    $user = User::factory()->create();

    return Invoice::create(array_merge([
        'user_id' => $user->id,
        'amount' => 100.00,
        'organization' => 'ACME',
        'taxation' => '111',
        'email' => 'a@example.com',
        'remark' => '',
        'status' => 0,
    ], $attrs));
}

it('returns all status=0 invoices ascending by id', function () {
    $a = makePendingInvoice(['amount' => 1]);
    $b = makePendingInvoice(['amount' => 2]);
    $c = makePendingInvoice(['amount' => 3, 'status' => 1]);

    $response = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson('/api/invoice/external/pending');

    $response->assertOk()->assertJson(['code' => 1]);
    $items = $response->json('data.items');
    expect(count($items))->toBe(2);
    expect($items[0]['id'])->toBe($a->id);
    expect($items[1]['id'])->toBe($b->id);
    expect($items[0])->toHaveKeys(['id', 'amount', 'organization', 'taxation', 'email', 'remark', 'created_at']);
});

it('supports since param for incremental pulls', function () {
    $a = makePendingInvoice();
    $b = makePendingInvoice();

    $response = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/invoice/external/pending?since=$a->id");

    $items = $response->json('data.items');
    expect(count($items))->toBe(1);
    expect($items[0]['id'])->toBe($b->id);
});

it('excludes status=1 and status=2', function () {
    makePendingInvoice(['status' => 1]);
    makePendingInvoice(['status' => 2]);
    $pending = makePendingInvoice(['status' => 0]);

    $response = $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson('/api/invoice/external/pending');

    $items = $response->json('data.items');
    expect(count($items))->toBe(1);
    expect($items[0]['id'])->toBe($pending->id);
});
