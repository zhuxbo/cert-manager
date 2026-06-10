<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Plugins\Invoice\Services\InvoiceConfig;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    InvoiceConfig::resetCache();
});

it('rejects when token not configured', function () {
    $this->getJson('/api/invoice/external/pending')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

it('rejects on wrong token', function () {
    InvoiceConfig::set('external_token', 'right-token');

    $this->withHeaders(['Authorization' => 'Bearer wrong'])
        ->getJson('/api/invoice/external/pending')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

it('rejects when token configured but request omits token', function () {
    InvoiceConfig::set('external_token', 'tok');

    // 不带 Authorization 头,也不带 ?token= query
    $this->getJson('/api/invoice/external/pending')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

it('rejects when ip not in allowlist', function () {
    InvoiceConfig::set('external_token', 'tok');
    InvoiceConfig::set('external_allowed_ips', '9.9.9.9');

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson('/api/invoice/external/pending')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

it('passes with token via Authorization header', function () {
    InvoiceConfig::set('external_token', 'tok');

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson('/api/invoice/external/pending')
        ->assertOk()
        ->assertJson(['code' => 1]);
});

it('rejects token via query string（凭据不进 URL 基线）', function () {
    InvoiceConfig::set('external_token', 'tok');

    // URL query 传长效 token 会落入 nginx/代理/对接方 access log，违反"凭据不进 URL"基线，
    // 必须被拒——仅接受 Authorization: Bearer 头
    $this->getJson('/api/invoice/external/pending?token=tok')
        ->assertOk()
        ->assertJson(['code' => 0]);
});

it('passes when allowed_ips empty', function () {
    InvoiceConfig::set('external_token', 'tok');
    InvoiceConfig::set('external_allowed_ips', '');

    $this->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson('/api/invoice/external/pending')
        ->assertOk()
        ->assertJson(['code' => 1]);
});
